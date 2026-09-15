<?php

namespace App\Jobs;

use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Start a full sync of a QuickBooks realm.
 *
 * Makes no QuickBooks calls itself. It fans the sync out into a batch of
 * SyncQuickBooksEntityJob, one per step, each of which pages on its own. Every
 * entry point (the Sync button, the OAuth callback, the scheduler) still
 * dispatches this job, so none of them needed to change.
 */
class SyncQuickBooksDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Only writes state rows and a batch.
     */
    public int $timeout = 60;

    // Private, as it was before R2. Jobs already waiting in the queue were
    // serialized with a private property, and changing its visibility risks them
    // failing to unserialize after a deploy. Read it through tokenId().
    public function __construct(private readonly int $tokenId)
    {
        $this->onQueue('quickbooks');
    }

    public function tokenId(): int
    {
        return $this->tokenId;
    }

    public function handle(): void
    {
        $token = QuickBooksToken::find($this->tokenId);

        if (! $token) {
            Log::warning("SyncQuickBooksDataJob: token #{$this->tokenId} not found — skipping.");

            return;
        }

        $realmId = $token->realm_id;

        Log::info("QuickBooks sync started for realm {$realmId} (user {$token->user_id}).");

        // The controller seeds these rows before dispatching so the frontend's
        // first poll sees work in progress, but a sync started from the console
        // command or the scheduler has no such seed. Seeding again here is
        // harmless (updateOrCreate) and keeps every entry point consistent.
        QuickBooksSyncState::markQueued($realmId);

        $steps = array_map(
            fn (string $entity) => new SyncQuickBooksEntityJob($token->id, $entity),
            QuickBooksSyncState::ENTITIES
        );

        Bus::batch($steps)
            ->name("quickbooks-sync:{$realmId}")
            // One failing step must not stop the rest: a bad invoices page should
            // not deny the user their accounts and customers.
            ->allowFailures()
            ->onQueue('quickbooks')
            ->finally(function () use ($realmId) {
                // Safety net. Each step records its own outcome, but a cancelled
                // batch skips steps and a lost job never reports back. Whatever
                // is still unfinished once the batch has settled never will be,
                // and leaving it would hold the progress screen open.
                QuickBooksSyncState::failUnfinished($realmId, 'This step did not run to completion.');

                Log::info('QuickBooks sync finished.', ['realm_id' => $realmId] + array_intersect_key(
                    QuickBooksSyncState::progressFor($realmId),
                    array_flip(['status', 'entities_failed'])
                ));
            })
            ->dispatch();
    }

    /**
     * The batch was never dispatched, so no step will ever report.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SyncQuickBooksDataJob permanently failed for token #{$this->tokenId}.", [
            'error' => $exception->getMessage(),
        ]);

        $token = QuickBooksToken::find($this->tokenId);

        if ($token) {
            QuickBooksSyncState::failUnfinished($token->realm_id, $exception->getMessage());
        }
    }
}
