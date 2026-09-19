<?php

namespace Tests\Feature;

use App\Jobs\SyncQuickBooksDataJob;
use App\Models\QuickBooksAccount;
use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\QuickBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The whole queued pipeline on a real database queue, run by a real worker.
 *
 * The other job tests use the sync driver or a faked bus, and neither reaches
 * Batch::add(): there, next pages are dispatched directly. This drives the
 * orchestrator's batch through queue:work, so page jobs really are added to a
 * live batch and the batch really settles.
 */
class QuickBooksSyncBatchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_BATCH';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'quickbooks.max_results' => 2]);
    }

    private function token(): QuickBooksToken
    {
        return QuickBooksToken::create([
            'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
        ]);
    }

    /**
     * Serve generated rows per entity, honouring STARTPOSITION and MAXRESULTS.
     *
     * @param  array<string, int>  $totals  entity => record count
     * @param  array<string, int>  $statuses  entity => HTTP status to fail with
     * @param  array<string, array<int, array>>  $changes  entity name => objects a CDC request returns
     */
    private function fakeQuickBooks(array $totals, array $statuses = [], array $changes = []): void
    {
        Http::fake(function (Request $request) use ($totals, $statuses, $changes) {
            if (str_contains($request->url(), '/cdc')) {
                return Http::response(['CDCResponse' => [['QueryResponse' => array_map(
                    fn (string $name) => [$name => $changes[$name] ?? []],
                    ['Account', 'Customer', 'Invoice', 'Purchase']
                )]]]);
            }

            $query = urldecode($request->url());

            preg_match('/FROM (\w+)/', $query, $e);
            $entity = $e[1] ?? '';

            if (isset($statuses[$entity])) {
                return Http::response('{"Fault":{}}', $statuses[$entity]);
            }

            preg_match('/STARTPOSITION (\d+)/', $query, $s);
            preg_match('/MAXRESULTS (\d+)/', $query, $m);
            $start = (int) ($s[1] ?? 1);
            $size = (int) ($m[1] ?? 1000);
            $total = $totals[$entity] ?? 0;

            $rows = [];
            for ($i = $start; $i < $start + $size && $i <= $total; $i++) {
                $rows[] = match ($entity) {
                    'CompanyInfo' => ['CompanyName' => 'Acme Ltd'],
                    'Customer' => ['Id' => (string) $i, 'DisplayName' => "Customer {$i}", 'Active' => true],
                    'Invoice' => ['Id' => (string) $i, 'CustomerRef' => ['name' => 'Beta', 'value' => '10'],
                        'TotalAmt' => 10, 'Balance' => 0, 'Line' => []],
                    default => ['Id' => (string) $i, 'Name' => "Row {$i}", 'Active' => true],
                };
            }

            return Http::response(['QueryResponse' => [$entity => $rows]]);
        });
    }

    private function work(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'quickbooks',
            '--stop-when-empty' => true,
            '--sleep' => 0,
        ]);
    }

    public function test_a_full_sync_pages_through_a_live_batch_and_settles(): void
    {
        $this->fakeQuickBooks(['CompanyInfo' => 1, 'Account' => 5, 'Customer' => 3, 'Invoice' => 4, 'Purchase' => 0]);
        $token = $this->token();

        dispatch(new SyncQuickBooksDataJob($token->id));
        $this->work();

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');
        foreach (State::ENTITIES as $entity) {
            $this->assertSame(State::STATUS_COMPLETE, $rows[$entity]->status, "{$entity} should complete");
        }
        $this->assertSame(5, $rows['accounts']->records_synced);
        $this->assertSame(3, $rows['customers']->records_synced);
        $this->assertSame(4, $rows['invoices']->records_synced);
        $this->assertSame(0, $rows['transactions']->records_synced);

        $this->assertSame(5, QuickBooksAccount::where('realm_id', $this->realm)->count());
        $this->assertSame(3, QuickBooksCustomer::where('realm_id', $this->realm)->count());
        $this->assertSame(4, QuickBooksInvoice::where('realm_id', $this->realm)->count());

        $batch = DB::table('job_batches')->sole();

        // 2 single steps + accounts 5@2 (3 pages) + customers 3@2 (2) + invoices
        // 4@2 (3: an exact multiple needs a trailing empty page) + one empty page
        // each for purchases, bills, payments, sales receipts and credit memos (5).
        $this->assertSame(15, (int) $batch->total_jobs, 'next pages must be added to the live batch');
        $this->assertSame(0, (int) $batch->pending_jobs);
        $this->assertSame(0, (int) $batch->failed_jobs);
        $this->assertNotNull($batch->finished_at);
        $this->assertNull($batch->cancelled_at);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('complete', State::progressFor($this->realm)['status']);
    }

    public function test_a_rejected_connection_cancels_the_live_batch_after_one_request(): void
    {
        // Company info runs first, so the very first request is rejected.
        $this->fakeQuickBooks(['Account' => 5], ['CompanyInfo' => 401]);
        $token = $this->token();

        dispatch(new SyncQuickBooksDataJob($token->id));
        $this->work();

        $this->assertSame(
            0,
            State::where('realm_id', $this->realm)->where('status', '!=', State::STATUS_FAILED)->count(),
            'every step should be closed out'
        );

        $batch = DB::table('job_batches')->sole();
        $this->assertNotNull($batch->cancelled_at);
        $this->assertNotNull($batch->finished_at);

        // One job failed; the rest saw the cancelled batch and stood down
        // instead of each calling QuickBooks and retrying.
        $this->assertSame(1, DB::table('failed_jobs')->count());
        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Every data entity last completed a run $daysAgo days ago.
     */
    private function syncedDaysAgo(int $daysAgo): void
    {
        foreach (QuickBooksService::PAGED_ENTITIES as $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 1);
        }

        State::where('realm_id', $this->realm)->update(['changes_through' => now()->subDays($daysAgo)]);
    }

    public function test_a_realm_inside_the_window_syncs_through_changes_on_a_live_batch(): void
    {
        $this->fakeQuickBooks(['CompanyInfo' => 1], [], ['Account' => [['Id' => '1', 'Name' => 'Changed', 'Active' => true]]]);
        $token = $this->token();
        $this->syncedDaysAgo(2);

        dispatch(new SyncQuickBooksDataJob($token->id));
        $this->work();

        $batch = DB::table('job_batches')->sole();

        // company_info + client_matching + one changes job, no paged queries.
        $this->assertSame(3, (int) $batch->total_jobs);
        $this->assertNotNull($batch->finished_at);
        $this->assertSame(0, (int) $batch->failed_jobs);

        // Company info and one change request: two calls for the whole realm.
        Http::assertSentCount(2);

        $this->assertSame('complete', State::progressFor($this->realm)['status']);
        $this->assertSame('Changed', QuickBooksAccount::where('realm_id', $this->realm)->where('qbo_id', '1')->value('name'));
    }

    public function test_a_truncated_change_set_adds_a_full_sync_to_the_live_batch(): void
    {
        $this->fakeQuickBooks(
            ['CompanyInfo' => 1, 'Account' => 5],
            [],
            ['Account' => array_map(fn (int $i) => ['Id' => (string) $i, 'Name' => "Account {$i}"], range(1, 1000))]
        );
        $token = $this->token();
        $this->syncedDaysAgo(2);

        dispatch(new SyncQuickBooksDataJob($token->id));
        $this->work();

        $batch = DB::table('job_batches')->sole();

        // 3 as above, plus the full sync added to the same batch: accounts 5@2
        // (3 pages) and one page each for the other seven data entities.
        $this->assertSame(13, (int) $batch->total_jobs);
        $this->assertNotNull($batch->finished_at);
        $this->assertSame(0, (int) $batch->pending_jobs);

        // The full sync's 5 accounts, not the 1000 from the truncated change set.
        $this->assertSame(5, QuickBooksAccount::where('realm_id', $this->realm)->count());
        $this->assertSame('complete', State::progressFor($this->realm)['status']);
    }
}
