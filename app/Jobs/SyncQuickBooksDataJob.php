<?php

namespace App\Jobs;

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
     */
    public int $timeout = 120;

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
    }
}
