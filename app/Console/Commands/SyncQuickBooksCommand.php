<?php

namespace App\Console\Commands;

use App\Jobs\SyncQuickBooksDataJob;
use App\Jobs\SyncQuickBooksEntityJob;
use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use Illuminate\Console\Command;

class SyncQuickBooksCommand extends Command
{
    protected $signature = 'sync:quickbooks
                            {--user= : Sync only the specified user ID}
                            {--realm= : Sync only the specified realm ID}';

    protected $description = 'Queue a QuickBooks sync for every connected company (one per realm).';

    public function handle(): int
    {
        $query = QuickBooksToken::query();

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        if ($realm = $this->option('realm')) {
            $query->where('realm_id', $realm);
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->info('No connected QuickBooks accounts found.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($tokens->groupBy('realm_id') as $realmId => $realmTokens) {
            // A lapsed refresh token cannot sync; queueing it would only produce
            // a failed run until the user reconnects.
            $usable = $realmTokens->reject(fn (QuickBooksToken $token) => $token->isRefreshTokenExpired());

            if ($usable->isEmpty()) {
                $this->warn("  ! Skipped realm {$realmId}: every connection to it needs reconnecting.");

                continue;
            }

            if (QuickBooksSyncState::isInProgress($realmId)) {
                $this->line("  - Skipped realm {$realmId}: a sync is already running.");

                continue;
            }

            // Synced data is stored per realm, so one full sync serves every user
            // connected to it. This used to queue one per token, pulling the same
            // company once per connected user and multiplying the metered
            // QuickBooks read calls.
            $primary = $usable->sortByDesc('updated_at')->first();

            dispatch(new SyncQuickBooksDataJob($primary->id));
            $dispatched++;

            $this->line("  → Queued sync for realm {$realmId} via user #{$primary->user_id}");

            // Client scope is per user, though. Anyone else on this realm whose
            // scope never resolved would stay locked out (the scope fails closed)
            // until they synced by hand, so resolve theirs too.
            foreach ($usable->where('id', '!=', $primary->id) as $other) {
                if (! $other->hasCompletedClientSelection()) {
                    dispatch(new SyncQuickBooksEntityJob($other->id, 'client_matching'));

                    $this->line("  → Queued client matching for user #{$other->user_id}");
                }
            }
        }

        $this->info("Dispatched {$dispatched} realm sync(s).");

        return self::SUCCESS;
    }
}
