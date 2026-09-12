<?php

namespace App\Jobs;

use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use App\Services\QuickBooksService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SyncQuickBooksDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying on failure.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Maximum seconds the job may run.
     *
     * Must stay below the queue connection's retry_after, otherwise the worker
     * releases the job back for a second worker to pick up while the first is
     * still running it, and the realm syncs twice concurrently.
     */
    public int $timeout = 600;

    public function __construct(private readonly int $tokenId)
    {
        $this->onQueue('quickbooks');
    }

    public function handle(QuickBooksService $service): void
    {
        $token = QuickBooksToken::find($this->tokenId);

        if (! $token) {
            Log::warning("SyncQuickBooksDataJob: token #{$this->tokenId} not found — skipping.");
            return;
        }

        Log::info("QuickBooks sync started for realm {$token->realm_id} (user {$token->user_id}).");

        // The controller seeds these rows before dispatching so the frontend's
        // first poll sees work in progress, but a sync started from the console
        // command or the scheduler has no such seed. Seeding again here is
        // harmless (updateOrCreate) and keeps every entry point consistent.
        QuickBooksSyncState::markQueued($token->realm_id);

        try {
            $counts = $service->syncAll($token);

            Log::info('QuickBooks sync completed.', array_merge(['realm_id' => $token->realm_id], $counts));
        } catch (RuntimeException $e) {
            Log::error('QuickBooks sync failed.', [
                'realm_id' => $token->realm_id,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SyncQuickBooksDataJob permanently failed for token #{$this->tokenId}.", [
            'error' => $exception->getMessage(),
        ]);

        // Release the frontend. Entities left pending or syncing will never
        // finish now that retries are exhausted, and without this the progress
        // poller would spin forever on a sync that is already dead. Covers the
        // cases syncAll's own handler cannot: timeout, OOM, worker restart.
        $token = QuickBooksToken::find($this->tokenId);

        if ($token) {
            QuickBooksSyncState::failUnfinished($token->realm_id, $exception->getMessage());
        }
    }
}
