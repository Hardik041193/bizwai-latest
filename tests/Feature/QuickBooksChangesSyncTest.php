<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Jobs\SyncQuickBooksChangesJob;
use App\Jobs\SyncQuickBooksDataJob;
use App\Jobs\SyncQuickBooksEntityJob;
use App\Models\QuickBooksAccount;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\QuickBooksService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incremental sync through QuickBooks change data capture (CDC).
 */
class QuickBooksChangesSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_CDC';

    /** @var array<int, string> every request sent, decoded */
    private array $requests = [];

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

    private function watermark(string $entity): ?Carbon
    {
        return State::where('realm_id', $this->realm)->where('entity', $entity)->first()?->changes_through;
    }

    /**
     * @param  array<string, array<int, array>>  $changes  entity name => changed objects
     * @param  bool  $objectNesting  objects instead of lists at each response level
     */
    private function fakeChanges(array $changes, bool $objectNesting = false, ?int $status = null): void
    {
        Http::fake(function (Request $request) use ($changes, $objectNesting, $status) {
            $this->requests[] = urldecode($request->url());

            if ($status !== null) {
                return Http::response('{"Fault":{}}', $status);
            }

            if (! str_contains($request->url(), '/cdc')) {
                return Http::response(['QueryResponse' => []]);
            }

            if ($objectNesting) {
                return Http::response(['CDCResponse' => ['QueryResponse' => $changes]]);
            }

            return Http::response(['CDCResponse' => [['QueryResponse' => array_map(
                fn (string $name) => [$name => $changes[$name] ?? []],
                ['Account', 'Customer', 'Invoice', 'Purchase']
            )]]]);
        });
    }

    private function existingRows(): void
    {
        $stamp = ['created_at' => now(), 'updated_at' => now()];

        DB::table('quickbooks_accounts')->insert(['realm_id' => $this->realm, 'qbo_id' => '1', 'name' => 'Old name'] + $stamp);
        DB::table('quickbooks_customers')->insert(['realm_id' => $this->realm, 'qbo_id' => '7', 'display_name' => 'Gone'] + $stamp);
        DB::table('quickbooks_invoices')->insert(['realm_id' => $this->realm, 'qbo_id' => '9', 'total_amount' => 10, 'balance' => 0] + $stamp);
    }

    /**
     * @return array<string, array<int, array>>
     */
    private function sampleChanges(): array
    {
        return [
            'Account' => [
                ['Id' => '1', 'Name' => 'New name', 'Active' => true],
                ['Id' => '2', 'Name' => 'Added', 'Active' => true],
            ],
            'Customer' => [
                ['Id' => '7', 'status' => 'Deleted', 'MetaData' => ['LastUpdatedTime' => '2026-09-14T09:14:16-08:00']],
            ],
            'Invoice' => [
                ['Id' => '9', 'status' => 'Deleted', 'MetaData' => ['LastUpdatedTime' => '2026-09-14T09:14:16-08:00']],
            ],
        ];
    }

    private function assertSampleChangesApplied(array $result): void
    {
        $this->assertFalse($result['truncated']);
        $this->assertSame(['updated' => 2, 'deleted' => 0], $result['counts']['accounts']);
        // A deleted object must not be stored as if it were active.
        $this->assertSame(['updated' => 0, 'deleted' => 1], $result['counts']['customers']);
        $this->assertSame(['updated' => 0, 'deleted' => 1], $result['counts']['invoices']);
        $this->assertSame(['updated' => 0, 'deleted' => 0], $result['counts']['transactions']);

        $this->assertSame('New name', DB::table('quickbooks_accounts')->where('realm_id', $this->realm)->where('qbo_id', '1')->value('name'));
        $this->assertTrue(DB::table('quickbooks_accounts')->where('realm_id', $this->realm)->where('qbo_id', '2')->exists());
        $this->assertFalse(DB::table('quickbooks_customers')->where('realm_id', $this->realm)->where('qbo_id', '7')->exists());
        $this->assertFalse(DB::table('quickbooks_invoices')->where('realm_id', $this->realm)->where('qbo_id', '9')->exists());
    }

    // ── watermarks ───────────────────────────────────────────────────────

    public function test_completing_a_step_moves_its_watermark_to_when_it_started(): void
    {
        $this->freezeSecond();
        $startedAt = now()->toDateTimeString();
        State::markSyncing($this->realm, 'invoices');

        $this->travel(10)->minutes();
        State::markComplete($this->realm, 'invoices', 3);

        $this->assertSame($startedAt, $this->watermark('invoices')->toDateTimeString());
    }

    public function test_a_failed_run_keeps_the_previous_watermark(): void
    {
        $this->freezeSecond();
        State::markSyncing($this->realm, 'invoices');
        State::markComplete($this->realm, 'invoices', 3);
        $previous = $this->watermark('invoices')->toDateTimeString();

        $this->travel(2)->days();
        State::markQueued($this->realm);
        State::markSyncing($this->realm, 'invoices');
        State::markFailed($this->realm, 'invoices', 'boom');

        $this->assertSame($previous, $this->watermark('invoices')->toDateTimeString());
    }

    public function test_changes_since_is_the_oldest_watermark_when_every_entity_is_inside_the_window(): void
    {
        $this->freezeSecond();

        foreach (QuickBooksService::PAGED_ENTITIES as $i => $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 1);
            State::where('realm_id', $this->realm)->where('entity', $entity)
                ->update(['changes_through' => now()->subDays($i + 1)]);
        }

        $since = State::changesSince($this->realm, QuickBooksService::PAGED_ENTITIES, 29);

        $this->assertSame(now()->subDays(4)->toDateTimeString(), $since?->toDateTimeString());
    }

    public function test_changes_since_is_null_when_an_entity_has_never_completed(): void
    {
        $this->syncedDaysAgo(2);
        State::where('realm_id', $this->realm)->where('entity', 'invoices')->update(['changes_through' => null]);

        $this->assertNull(State::changesSince($this->realm, QuickBooksService::PAGED_ENTITIES, 29));
    }

    public function test_changes_since_is_null_past_the_lookback_window(): void
    {
        $this->syncedDaysAgo(30);

        $this->assertNull(State::changesSince($this->realm, QuickBooksService::PAGED_ENTITIES, 29));
    }

    // ── service ──────────────────────────────────────────────────────────

    public function test_changes_apply_updates_and_deletes_from_one_request(): void
    {
        $this->existingRows();
        $this->fakeChanges($this->sampleChanges());

        $result = app(QuickBooksService::class)->syncChanges($this->token(), now()->subDays(2));

        $this->assertSampleChangesApplied($result);

        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('entities=Account,Customer,Invoice,Purchase', $this->requests[0]);
        $this->assertMatchesRegularExpression('/changedSince=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00/', $this->requests[0]);
    }

    public function test_an_object_nested_response_is_read_the_same_way(): void
    {
        $this->existingRows();
        $this->fakeChanges($this->sampleChanges(), objectNesting: true);

        $result = app(QuickBooksService::class)->syncChanges($this->token(), now()->subDays(2));

        $this->assertSampleChangesApplied($result);
    }

    /**
     * The shape a live sandbox realm actually returned. One CDCResponse holding a
     * QueryResponse per entity, but NOT in the order requested, with paging
     * metadata beside each entity and an empty object for an entity with no
     * changes. A parser matching entities by position, as the SDK's XML parser
     * does, would file these changes under the wrong entity.
     */
    public function test_the_live_response_shape_is_read_by_entity_name_not_position(): void
    {
        $this->existingRows();

        Http::fake(fn () => Http::response(<<<'JSON'
            {"CDCResponse":[{"QueryResponse":[
                {},
                {"Invoice":[{"domain":"QBO","status":"Deleted","Id":"9"}],"startPosition":1,"maxResults":1,"totalCount":1},
                {"Account":[{"Id":"1","Name":"New name","Active":true},{"Id":"2","Name":"Added","Active":true}],"startPosition":1,"maxResults":2,"totalCount":2},
                {"Customer":[{"domain":"QBO","status":"Deleted","Id":"7"}],"startPosition":1,"maxResults":1,"totalCount":1}
            ]}],"time":"2026-09-15T11:25:13.000-07:00"}
            JSON, 200, ['Content-Type' => 'application/json']));

        $result = app(QuickBooksService::class)->syncChanges($this->token(), now()->subDays(2));

        $this->assertSampleChangesApplied($result);
    }

    public function test_a_change_set_at_the_object_cap_is_reported_truncated_and_nothing_is_applied(): void
    {
        $this->fakeChanges(['Account' => array_map(
            fn (int $i) => ['Id' => (string) $i, 'Name' => "Account {$i}"],
            range(1, 1000)
        )]);

        $result = app(QuickBooksService::class)->syncChanges($this->token(), now()->subDays(2));

        $this->assertTrue($result['truncated']);
        $this->assertSame(0, QuickBooksAccount::where('realm_id', $this->realm)->count());
    }

    public function test_a_rejected_connection_during_changes_requires_reauthorization(): void
    {
        $this->fakeChanges([], status: 401);

        $this->expectException(QuickBooksReauthorizationRequired::class);

        app(QuickBooksService::class)->syncChanges($this->token(), now()->subDays(2));
    }

    // ── orchestrator ─────────────────────────────────────────────────────

    public function test_a_realm_inside_the_window_syncs_through_one_changes_job(): void
    {
        Bus::fake();
        $this->freezeSecond();
        $token = $this->token();
        $this->syncedDaysAgo(2);

        (new SyncQuickBooksDataJob($token->id))->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            $jobs = $batch->jobs->all();

            return count($jobs) === 3
                && $jobs[0] instanceof SyncQuickBooksEntityJob && $jobs[0]->entity === 'company_info'
                && $jobs[1] instanceof SyncQuickBooksEntityJob && $jobs[1]->entity === 'client_matching'
                && $jobs[2] instanceof SyncQuickBooksChangesJob
                && Carbon::parse($jobs[2]->since)->toDateTimeString() === now()->subDays(2)->toDateTimeString();
        });
    }

    public function test_a_realm_past_the_window_syncs_in_full(): void
    {
        Bus::fake();
        $token = $this->token();
        $this->syncedDaysAgo(40);

        (new SyncQuickBooksDataJob($token->id))->handle();

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->every(fn ($job) => $job instanceof SyncQuickBooksEntityJob)
            && $batch->jobs->map(fn ($job) => $job->entity)->all() === State::ENTITIES
        );
    }

    // ── changes job ──────────────────────────────────────────────────────

    public function test_the_changes_job_completes_each_entity_and_advances_its_watermark(): void
    {
        $token = $this->token();
        $this->syncedDaysAgo(3);
        $this->fakeChanges(['Account' => [['Id' => '1', 'Name' => 'Changed', 'Active' => true]]]);

        (new SyncQuickBooksChangesJob($token->id, now()->subDays(3)->toIso8601String()))
            ->handle(app(QuickBooksService::class));

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');
        foreach (QuickBooksService::PAGED_ENTITIES as $entity) {
            $this->assertSame(State::STATUS_COMPLETE, $rows[$entity]->status);
            $this->assertTrue($rows[$entity]->changes_through->greaterThan(now()->subMinute()), "{$entity} watermark should advance");
        }
        $this->assertSame(1, $rows['accounts']->records_synced);
    }

    public function test_a_truncated_change_set_falls_back_to_a_full_paged_sync(): void
    {
        Queue::fake();
        $token = $this->token();
        $this->syncedDaysAgo(3);
        $this->fakeChanges(['Account' => array_map(
            fn (int $i) => ['Id' => (string) $i, 'Name' => "Account {$i}"],
            range(1, 1000)
        )]);

        (new SyncQuickBooksChangesJob($token->id, now()->subDays(3)->toIso8601String()))
            ->handle(app(QuickBooksService::class));

        Queue::assertPushed(SyncQuickBooksEntityJob::class, count(QuickBooksService::PAGED_ENTITIES));
        foreach (QuickBooksService::PAGED_ENTITIES as $entity) {
            Queue::assertPushed(SyncQuickBooksEntityJob::class, fn ($job) => $job->entity === $entity && $job->startPosition === 1);
        }

        $this->assertSame(0, QuickBooksAccount::where('realm_id', $this->realm)->count());
        $this->assertSame(State::STATUS_SYNCING, State::where('realm_id', $this->realm)->where('entity', 'accounts')->value('status'));
    }

    public function test_a_rejected_connection_during_changes_fails_the_run_at_once(): void
    {
        $token = $this->token();
        $this->syncedDaysAgo(3);
        State::markQueued($this->realm);
        $this->fakeChanges([], status: 401);

        (new SyncQuickBooksChangesJob($token->id, now()->subDays(3)->toIso8601String()))
            ->handle(app(QuickBooksService::class));

        $this->assertSame(0, State::where('realm_id', $this->realm)->where('status', '!=', State::STATUS_FAILED)->count());
        Http::assertSentCount(1);
    }
}
