<?php

namespace Tests\Feature;

use App\Jobs\SyncQuickBooksDataJob;
use App\Jobs\SyncQuickBooksEntityJob;
use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\QuickBooksService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The queued sync: an orchestrator batch of one job per step, each paging alone.
 */
class QuickBooksSyncJobsTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_JOBS';

    /** @var array<int, string> every query sent, decoded */
    private array $queries = [];

    private function token(array $overrides = []): QuickBooksToken
    {
        return QuickBooksToken::create(array_merge([
            'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
        ], $overrides));
    }

    /**
     * Serve generated rows per entity, honouring STARTPOSITION and MAXRESULTS.
     *
     * @param  array<string, int>  $totals  entity => record count, or an HTTP status to fail with
     * @param  array<string, int>  $statuses  entity => HTTP status to fail with
     */
    private function fakeQuickBooks(array $totals, array $statuses = []): void
    {
        Http::fake(function (Request $request) use ($totals, $statuses) {
            $query = urldecode($request->url());
            $this->queries[] = $query;

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
                    'CompanyInfo' => ['CompanyName' => 'Acme Ltd', 'Email' => ['Address' => 'books@acme.test']],
                    'Customer' => ['Id' => (string) $i, 'DisplayName' => "Customer {$i}", 'Active' => true],
                    'Invoice' => ['Id' => (string) $i, 'CustomerRef' => ['name' => 'Beta', 'value' => '10'],
                        'TotalAmt' => 10, 'Balance' => 0, 'Line' => []],
                    default => ['Id' => (string) $i, 'Name' => "Row {$i}", 'Active' => true],
                };
            }

            return Http::response(['QueryResponse' => [$entity => $rows]]);
        });
    }

    private function startsFor(string $entity): array
    {
        return collect($this->queries)
            ->filter(fn ($q) => str_contains($q, "FROM {$entity}"))
            ->map(fn ($q) => (int) preg_replace('/.*STARTPOSITION (\d+).*/s', '$1', $q))
            ->values()
            ->all();
    }

    // ── orchestrator ─────────────────────────────────────────────────────

    public function test_the_orchestrator_batches_one_job_per_step_and_tolerates_failures(): void
    {
        Bus::fake();
        $token = $this->token();

        (new SyncQuickBooksDataJob($token->id))->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->allowsFailures()
                && $batch->queue() === 'quickbooks'
                && $batch->jobs->map(fn (SyncQuickBooksEntityJob $job) => $job->entity)->all() === State::ENTITIES;
        });

        // Seeded so the progress screen shows work before any step starts.
        $this->assertSame('syncing', State::progressFor($this->realm)['status']);
    }

    public function test_the_batch_finally_closes_out_steps_that_never_reported(): void
    {
        Bus::fake();
        $token = $this->token();

        (new SyncQuickBooksDataJob($token->id))->handle();

        $batch = null;
        Bus::assertBatched(function (PendingBatch $pending) use (&$batch) {
            $batch = $pending;

            return true;
        });

        // One step finished; the rest were, say, skipped by a cancelled batch.
        State::markComplete($this->realm, 'company_info', 1);

        ($batch->finallyCallbacks()[0])();

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');
        $this->assertSame(State::STATUS_COMPLETE, $rows['company_info']->status);
        $this->assertSame(State::STATUS_FAILED, $rows['invoices']->status);

        // The progress screen is released rather than left spinning.
        $this->assertTrue(State::progressFor($this->realm)['complete']);
    }

    // ── paging through jobs ──────────────────────────────────────────────

    public function test_a_paged_step_walks_every_page_through_chained_jobs(): void
    {
        config(['quickbooks.max_results' => 2]);
        $this->fakeQuickBooks(['Customer' => 5]);
        $token = $this->token();

        // The sync queue driver runs each next-page job inline.
        dispatch(new SyncQuickBooksEntityJob($token->id, 'customers'));

        $row = State::where('realm_id', $this->realm)->where('entity', 'customers')->first();
        $this->assertSame(State::STATUS_COMPLETE, $row->status);
        $this->assertSame(5, $row->records_synced);
        $this->assertNull($row->start_position);

        $this->assertSame(5, QuickBooksCustomer::where('realm_id', $this->realm)->count());
        $this->assertSame([1, 3, 5], $this->startsFor('Customer'));
    }

    public function test_each_page_records_progress_and_queues_the_next_page(): void
    {
        Queue::fake();
        config(['quickbooks.max_results' => 2]);
        $this->fakeQuickBooks(['Customer' => 5]);
        $token = $this->token();

        (new SyncQuickBooksEntityJob($token->id, 'customers'))->handle(app(QuickBooksService::class));

        $row = State::where('realm_id', $this->realm)->where('entity', 'customers')->first();
        $this->assertSame(State::STATUS_SYNCING, $row->status);
        $this->assertSame(2, $row->records_synced);
        $this->assertSame(3, $row->start_position);

        Queue::assertPushed(SyncQuickBooksEntityJob::class, 1);
        Queue::assertPushed(SyncQuickBooksEntityJob::class, fn (SyncQuickBooksEntityJob $job) =>
            $job->entity === 'customers'
            && $job->startPosition === 3
            && $job->query === 'SELECT * FROM Customer'
        );
    }

    /**
     * Invoices use a full-history query while the realm has no rows. Page one
     * creates rows, so re-choosing the query on page two would switch to the
     * date-windowed variant and page through a different result set.
     */
    public function test_later_pages_reuse_the_query_the_first_page_chose(): void
    {
        config(['quickbooks.max_results' => 2]);
        $this->fakeQuickBooks(['Invoice' => 3]);
        $token = $this->token();

        dispatch(new SyncQuickBooksEntityJob($token->id, 'invoices'));

        $invoiceQueries = array_values(array_filter($this->queries, fn ($q) => str_contains($q, 'FROM Invoice')));

        $this->assertCount(2, $invoiceQueries);
        foreach ($invoiceQueries as $query) {
            $this->assertStringNotContainsString('LastUpdatedTime', $query);
        }
        $this->assertSame(3, QuickBooksInvoice::where('realm_id', $this->realm)->count());
    }

    public function test_a_retried_page_does_not_double_count_records(): void
    {
        Queue::fake();
        config(['quickbooks.max_results' => 2]);
        $this->fakeQuickBooks(['Customer' => 5]);
        $token = $this->token();
        $service = app(QuickBooksService::class);

        (new SyncQuickBooksEntityJob($token->id, 'customers'))->handle($service);

        // The second page run twice, as a retry would. The first page is no use
        // for this: it resets the count, which would hide an increment.
        $secondPage = fn () => new SyncQuickBooksEntityJob($token->id, 'customers', 3, 'SELECT * FROM Customer');
        $secondPage()->handle($service);
        $secondPage()->handle($service);

        $this->assertSame(4, State::where('realm_id', $this->realm)->where('entity', 'customers')->value('records_synced'));
    }

    public function test_a_single_step_runs_in_its_own_job(): void
    {
        $this->fakeQuickBooks(['CompanyInfo' => 1]);
        $token = $this->token();

        dispatch(new SyncQuickBooksEntityJob($token->id, 'company_info'));

        $this->assertSame(
            State::STATUS_COMPLETE,
            State::where('realm_id', $this->realm)->where('entity', 'company_info')->value('status')
        );
        $this->assertSame('Acme Ltd', $token->fresh()->company_name);
    }

    // ── failures ─────────────────────────────────────────────────────────

    public function test_a_failing_step_marks_only_itself_failed(): void
    {
        $this->fakeQuickBooks([], ['Invoice' => 500]);
        $token = $this->token();
        State::markQueued($this->realm);

        try {
            dispatch(new SyncQuickBooksEntityJob($token->id, 'invoices'));
            $this->fail('A 500 should fail the step.');
        } catch (RuntimeException $e) {
            // expected
        }

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');
        $this->assertSame(State::STATUS_FAILED, $rows['invoices']->status);
        $this->assertStringContainsString('500', $rows['invoices']->error);

        // Other steps are left to run.
        $this->assertSame(State::STATUS_PENDING, $rows['accounts']->status);
    }

    public function test_a_rejected_connection_fails_the_whole_run_at_once(): void
    {
        $this->fakeQuickBooks([], ['Account' => 401]);
        $token = $this->token();
        State::markQueued($this->realm);

        (new SyncQuickBooksEntityJob($token->id, 'accounts'))->handle(app(QuickBooksService::class));

        // Every step closed out, not just this one, and no retry storm.
        $this->assertSame(0, State::where('realm_id', $this->realm)->where('status', '!=', State::STATUS_FAILED)->count());
        $this->assertStringContainsString('reconnect', State::where('realm_id', $this->realm)->value('error'));
        $this->assertTrue(State::progressFor($this->realm)['complete']);
        Http::assertSentCount(1);
    }

    public function test_an_expired_refresh_token_fails_the_run_before_calling_quickbooks(): void
    {
        Http::fake();
        $token = $this->token([
            'token_expires_at' => now()->subHour(),
            'refresh_token_expires_at' => now()->subMinute(),
        ]);
        // Past the two-minute "just issued" grace period.
        DB::table('quickbooks_tokens')->where('id', $token->id)->update(['created_at' => now()->subHour()]);
        State::markQueued($this->realm);

        (new SyncQuickBooksEntityJob($token->id, 'accounts'))->handle(app(QuickBooksService::class));

        $this->assertSame(0, State::where('realm_id', $this->realm)->where('status', '!=', State::STATUS_FAILED)->count());
        $this->assertStringContainsString('refresh token has expired', State::where('realm_id', $this->realm)->value('error'));
        Http::assertNothingSent();
    }

    public function test_a_job_for_a_disconnected_token_does_nothing(): void
    {
        Http::fake();

        dispatch(new SyncQuickBooksEntityJob(999999, 'accounts'));

        $this->assertSame(0, State::count());
        Http::assertNothingSent();
    }
}
