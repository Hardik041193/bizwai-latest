<?php

namespace Tests\Feature;

use App\Jobs\SyncQuickBooksDataJob;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The sync trigger and progress endpoints the frontend poller depends on.
 */
class QuickBooksSyncProgressTest extends TestCase
{
    use RefreshDatabase;

    private function connectedUser(string $realm = 'REALM_HTTP_1'): User
    {
        $user = User::factory()->create();

        QuickBooksToken::create([
            'user_id' => $user->id,
            'realm_id' => $realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
        ]);

        return $user->fresh();
    }

    public function test_progress_requires_authentication(): void
    {
        $this->getJson('/api/quickbooks/sync/progress')->assertUnauthorized();
    }

    public function test_progress_is_rejected_when_quickbooks_is_not_connected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/quickbooks/sync/progress')
            ->assertStatus(422)
            ->assertJson(['message' => 'QuickBooks is not connected.']);
    }

    public function test_progress_reports_idle_before_the_first_sync(): void
    {
        Sanctum::actingAs($this->connectedUser());

        $this->getJson('/api/quickbooks/sync/progress')
            ->assertOk()
            ->assertJson([
                'realm_id' => 'REALM_HTTP_1',
                'status' => 'idle',
                'complete' => false,
                'progress' => 0,
            ])
            ->assertJsonCount(count(State::ENTITIES), 'entities');
    }

    public function test_progress_exposes_per_entity_detail(): void
    {
        $user = $this->connectedUser();
        Sanctum::actingAs($user);

        State::markQueued('REALM_HTTP_1');
        State::markComplete('REALM_HTTP_1', 'company_info', 1);
        State::markFailed('REALM_HTTP_1', 'invoices', 'QuickBooks API error (401)');

        $response = $this->getJson('/api/quickbooks/sync/progress')->assertOk();

        $entities = collect($response->json('entities'))->keyBy('entity');

        $this->assertSame('complete', $entities['company_info']['status']);
        $this->assertSame(1, $entities['company_info']['records_synced']);
        $this->assertSame('Company profile', $entities['company_info']['label']);

        $this->assertSame('failed', $entities['invoices']['status']);
        $this->assertStringContainsString('401', $entities['invoices']['error']);
    }

    public function test_sync_queues_a_job_instead_of_running_it_inline(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->connectedUser());

        $this->postJson('/api/quickbooks/sync')->assertOk();

        Queue::assertPushed(SyncQuickBooksDataJob::class);
    }

    /**
     * Overlapping runs are data-safe but double the metered QuickBooks calls.
     */
    public function test_triggering_a_sync_while_one_is_running_does_not_start_another(): void
    {
        Queue::fake();
        $user = $this->connectedUser();
        Sanctum::actingAs($user);

        State::markQueued('REALM_HTTP_1');

        $this->postJson('/api/quickbooks/sync')
            ->assertOk()
            ->assertJson(['message' => 'A sync is already running.'])
            ->assertJsonPath('progress.complete', false);

        Queue::assertNothingPushed();
    }

    public function test_sync_is_rejected_when_quickbooks_is_not_connected(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/quickbooks/sync')->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /**
     * The regression this endpoint exists to prevent.
     *
     * State is seeded in the request, before the job is dispatched. If it were
     * seeded inside the job instead, a poll landing in the gap before a worker
     * picks the job up would read the PREVIOUS run's "complete" rows, clear the
     * spinner, and show a finished sync over stale data.
     */
    public function test_polling_immediately_after_trigger_reports_in_progress(): void
    {
        Queue::fake();
        $user = $this->connectedUser();
        Sanctum::actingAs($user);

        // A previous run that finished cleanly.
        foreach (State::ENTITIES as $entity) {
            State::markComplete('REALM_HTTP_1', $entity, 10);
        }
        $this->assertTrue(State::progressFor('REALM_HTTP_1')['complete']);

        $this->postJson('/api/quickbooks/sync')
            ->assertOk()
            ->assertJsonPath('progress.complete', false);

        // No worker has run yet, and the poll must still say "in progress".
        $this->getJson('/api/quickbooks/sync/progress')
            ->assertOk()
            ->assertJson(['status' => 'syncing', 'complete' => false]);
    }

    public function test_progress_is_scoped_to_the_callers_own_realm(): void
    {
        $this->connectedUser('REALM_A');
        $other = $this->connectedUser('REALM_B');

        State::markComplete('REALM_A', 'invoices', 500);

        Sanctum::actingAs($other);

        $this->getJson('/api/quickbooks/sync/progress')
            ->assertOk()
            ->assertJson(['realm_id' => 'REALM_B', 'status' => 'idle']);
    }
}
