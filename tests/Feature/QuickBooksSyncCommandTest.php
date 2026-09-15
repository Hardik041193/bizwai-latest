<?php

namespace Tests\Feature;

use App\Jobs\SyncQuickBooksDataJob;
use App\Jobs\SyncQuickBooksEntityJob;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The scheduled sync:quickbooks command.
 *
 * Several users can connect the same QuickBooks company. Synced data is stored
 * per realm, so the command queues one sync per realm rather than one per user,
 * which used to pull the same company repeatedly and multiply metered calls.
 */
class QuickBooksSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $realm, bool $resolved = true, array $overrides = [], ?string $updatedAt = null): QuickBooksToken
    {
        $token = QuickBooksToken::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'realm_id' => $realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
            'selected_clients' => $resolved ? [] : null,
            'client_selected_at' => $resolved ? now() : null,
        ], $overrides));

        if ($updatedAt) {
            DB::table('quickbooks_tokens')->where('id', $token->id)->update(['updated_at' => $updatedAt]);
        }

        return $token->fresh();
    }

    public function test_one_sync_is_queued_per_realm_not_per_connected_user(): void
    {
        Queue::fake();
        $older = $this->token('REALM_R', updatedAt: now()->subDay()->toDateTimeString());
        $newer = $this->token('REALM_R', updatedAt: now()->toDateTimeString());

        $this->artisan('sync:quickbooks')->assertSuccessful();

        Queue::assertPushed(SyncQuickBooksDataJob::class, 1);
        Queue::assertPushed(SyncQuickBooksDataJob::class, fn ($job) => $job->tokenId() === $newer->id);
        Queue::assertNotPushed(SyncQuickBooksEntityJob::class);
    }

    public function test_each_realm_gets_its_own_sync(): void
    {
        Queue::fake();
        $this->token('REALM_R');
        $this->token('REALM_S');

        $this->artisan('sync:quickbooks')->assertSuccessful();

        Queue::assertPushed(SyncQuickBooksDataJob::class, 2);
    }

    /**
     * Client scope is per user and fails closed, so another user on the realm
     * whose scope never resolved would otherwise stay locked out.
     */
    public function test_other_users_with_an_unresolved_scope_get_client_matching_queued(): void
    {
        Queue::fake();
        $primary = $this->token('REALM_R', updatedAt: now()->toDateTimeString());
        $unresolved = $this->token('REALM_R', resolved: false, updatedAt: now()->subDay()->toDateTimeString());

        $this->artisan('sync:quickbooks')->assertSuccessful();

        Queue::assertPushed(SyncQuickBooksDataJob::class, fn ($job) => $job->tokenId() === $primary->id);
        Queue::assertPushed(SyncQuickBooksEntityJob::class, 1);
        Queue::assertPushed(SyncQuickBooksEntityJob::class, fn ($job) =>
            $job->tokenId === $unresolved->id && $job->entity === 'client_matching'
        );
    }

    public function test_a_realm_whose_connections_all_need_reconnecting_is_skipped(): void
    {
        Queue::fake();
        $this->token('REALM_DEAD', overrides: ['refresh_token_expires_at' => now()->subDay()]);

        $this->artisan('sync:quickbooks')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_an_expired_connection_is_passed_over_for_a_usable_one(): void
    {
        Queue::fake();
        $this->token('REALM_R', overrides: ['refresh_token_expires_at' => now()->subDay()], updatedAt: now()->toDateTimeString());
        $usable = $this->token('REALM_R', updatedAt: now()->subDay()->toDateTimeString());

        $this->artisan('sync:quickbooks')->assertSuccessful();

        Queue::assertPushed(SyncQuickBooksDataJob::class, fn ($job) => $job->tokenId() === $usable->id);
    }

    public function test_a_realm_already_syncing_is_skipped(): void
    {
        Queue::fake();
        $this->token('REALM_R');
        State::markQueued('REALM_R');

        $this->artisan('sync:quickbooks')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
