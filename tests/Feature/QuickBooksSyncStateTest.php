<?php

namespace Tests\Feature;

use App\Models\QuickBooksSyncState as State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The state machine behind the sync progress indicator.
 */
class QuickBooksSyncStateTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_TEST_1';

    public function test_a_realm_that_never_synced_reports_idle(): void
    {
        $progress = State::progressFor($this->realm);

        $this->assertSame('idle', $progress['status']);
        $this->assertFalse($progress['complete']);
        $this->assertCount(count(State::ENTITIES), $progress['entities']);
        $this->assertSame(0, $progress['progress']);
    }

    public function test_mark_queued_sets_every_entity_pending(): void
    {
        State::markQueued($this->realm);

        $this->assertSame(
            count(State::ENTITIES),
            State::where('realm_id', $this->realm)->where('status', State::STATUS_PENDING)->count()
        );

        $progress = State::progressFor($this->realm);
        $this->assertSame('syncing', $progress['status']);
        $this->assertFalse($progress['complete']);
        $this->assertSame(0, $progress['progress']);
        $this->assertSame(State::ENTITIES, $progress['pending_entities']);
    }

    public function test_mark_queued_clears_a_previous_runs_error(): void
    {
        State::markFailed($this->realm, 'invoices', 'boom');
        State::markQueued($this->realm);

        $row = State::where('realm_id', $this->realm)->where('entity', 'invoices')->first();

        $this->assertSame(State::STATUS_PENDING, $row->status);
        $this->assertNull($row->error);
    }

    public function test_progress_percentage_tracks_finished_entities(): void
    {
        State::markQueued($this->realm);
        State::markComplete($this->realm, 'company_info', 1);
        State::markComplete($this->realm, 'accounts', 12);

        $progress = State::progressFor($this->realm);

        $this->assertSame('syncing', $progress['status']);
        $this->assertSame(33, $progress['progress']); // 2 of 6
        $this->assertSame(2, $progress['entities_finished']);
        $this->assertSame(['client_matching', 'customers', 'invoices', 'transactions'], $progress['pending_entities']);
    }

    public function test_a_clean_run_reports_complete(): void
    {
        foreach (State::ENTITIES as $entity) {
            State::markComplete($this->realm, $entity, 5);
        }

        $progress = State::progressFor($this->realm);

        $this->assertSame('complete', $progress['status']);
        $this->assertTrue($progress['complete']);
        $this->assertSame(100, $progress['progress']);
        $this->assertSame(0, $progress['entities_failed']);
    }

    /**
     * The frontend clears its spinner on `complete`. A failed entity never
     * finishes on its own, so a partial run must still report complete or the
     * UI spins forever.
     */
    public function test_a_partial_run_still_reports_complete_so_the_ui_is_released(): void
    {
        foreach (['company_info', 'client_matching', 'accounts', 'customers', 'invoices'] as $entity) {
            State::markComplete($this->realm, $entity, 5);
        }
        State::markFailed($this->realm, 'transactions', 'QuickBooks API error (401)');

        $progress = State::progressFor($this->realm);

        $this->assertSame('partial', $progress['status']);
        $this->assertTrue($progress['complete']);
        $this->assertSame(1, $progress['entities_failed']);
        $this->assertSame(100, $progress['progress']);
    }

    public function test_fail_unfinished_only_touches_entities_still_in_flight(): void
    {
        State::markQueued($this->realm);
        State::markComplete($this->realm, 'company_info', 1);
        State::markSyncing($this->realm, 'accounts');

        State::failUnfinished($this->realm, 'worker died');

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');

        // A finished entity keeps its result.
        $this->assertSame(State::STATUS_COMPLETE, $rows['company_info']->status);
        $this->assertNull($rows['company_info']->error);

        // Everything mid-flight is closed out.
        $this->assertSame(State::STATUS_FAILED, $rows['accounts']->status);
        $this->assertSame(State::STATUS_FAILED, $rows['invoices']->status);
        $this->assertSame('worker died', $rows['accounts']->error);

        $this->assertTrue(State::progressFor($this->realm)['complete']);
    }

    public function test_long_error_bodies_are_truncated_to_fit_the_column(): void
    {
        State::markFailed($this->realm, 'invoices', str_repeat('x', 5000));

        $row = State::where('realm_id', $this->realm)->where('entity', 'invoices')->first();

        $this->assertSame(2000, mb_strlen($row->error));
    }

    /**
     * When the entity list grows, realms synced before the new entity existed
     * keep their old rows. Counting the missing entity as unfinished would make
     * a cleanly finished realm report "syncing" forever and hang any poller.
     */
    public function test_an_entity_with_no_row_does_not_block_completion(): void
    {
        // Every entity except the newest one, as an older realm would have.
        foreach (State::ENTITIES as $entity) {
            if ($entity === 'client_matching') {
                continue;
            }
            State::markComplete($this->realm, $entity, 5);
        }

        $progress = State::progressFor($this->realm);

        $this->assertSame('complete', $progress['status']);
        $this->assertTrue($progress['complete']);
        $this->assertSame(100, $progress['progress']);

        // Still listed, so the UI can show it, just not counted.
        $row = collect($progress['entities'])->firstWhere('entity', 'client_matching');
        $this->assertSame('idle', $row['status']);
    }

    public function test_a_realm_with_freshly_written_unfinished_steps_is_in_progress(): void
    {
        State::markQueued($this->realm);

        $this->assertTrue(State::isInProgress($this->realm));
    }

    public function test_a_finished_realm_is_not_in_progress(): void
    {
        foreach (State::ENTITIES as $entity) {
            State::markComplete($this->realm, $entity, 1);
        }

        $this->assertFalse(State::isInProgress($this->realm));
        $this->assertFalse(State::isInProgress('NEVER_SYNCED'));
    }

    /**
     * A worker that died without reaching a failure handler leaves rows
     * unfinished forever. Treating those as live would block every later sync.
     */
    public function test_unfinished_steps_nobody_has_written_recently_are_not_in_progress(): void
    {
        State::markQueued($this->realm);
        State::where('realm_id', $this->realm)->update(['updated_at' => now()->subMinutes(20)]);

        $this->assertFalse(State::isInProgress($this->realm));
    }

    public function test_page_progress_is_a_running_total_not_an_increment(): void
    {
        State::recordPageProgress($this->realm, 'invoices', 1000, 1001);
        State::recordPageProgress($this->realm, 'invoices', 1000, 1001);

        $row = State::where('realm_id', $this->realm)->where('entity', 'invoices')->first();
        $this->assertSame(1000, $row->records_synced);
        $this->assertSame(1001, $row->start_position);
        $this->assertSame(State::STATUS_SYNCING, $row->status);
    }

    public function test_state_is_scoped_per_realm(): void
    {
        State::markQueued($this->realm);
        State::markComplete('OTHER_REALM', 'invoices', 99);

        $this->assertSame('syncing', State::progressFor($this->realm)['status']);
        $this->assertSame(0, State::progressFor($this->realm)['entities_finished']);
    }
}
