<?php

namespace App\Jobs;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use App\Services\QuickBooksService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Bring every data entity up to date from one QuickBooks change data capture
 * request, instead of a full paged query per entity.
 *
 * Owns the progress rows of all the paged entities for this run. When QuickBooks
 * returns as many changes as one response can hold, the change set may be
 * incomplete, so it applies nothing and adds a full paged sync to the batch.
 */
class SyncQuickBooksChangesJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * One API call and at most 1000 upserts or deletes. Must stay below the
     * queue connection's retry_after (900).
     */
    public int $timeout = 300;

    public function __construct(
        public readonly int $tokenId,
        // ISO 8601. A string keeps the queued payload independent of how Carbon serializes.
        public readonly string $since,
    ) {
        $this->onQueue('quickbooks');
    }

    public function handle(QuickBooksService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $token = QuickBooksToken::find($this->tokenId);

        if (! $token) {
            return;
        }

        foreach (QuickBooksService::PAGED_ENTITIES as $entity) {
            QuickBooksSyncState::markSyncing($token->realm_id, $entity);
        }

        try {
            $result = $service->syncChanges($token, Carbon::parse($this->since));
        } catch (QuickBooksReauthorizationRequired $e) {
            // Nothing else in this run can succeed either, so close it out now,
            // exactly as a single step does.
            QuickBooksSyncState::failUnfinished($token->realm_id, $e->getMessage());
            $this->batch()?->cancel();
            $this->fail($e);

            return;
        }

        if ($result['truncated']) {
            $this->fallBackToFullSync($token);

            return;
        }

        foreach ($result['counts'] as $entity => $count) {
            QuickBooksSyncState::markComplete($token->realm_id, $entity, $count['updated'] + $count['deleted']);
        }
    }

    private function fallBackToFullSync(QuickBooksToken $token): void
    {
        Log::info("QuickBooks: too many changes for realm {$token->realm_id} since {$this->since}, running a full sync instead.");

        $jobs = array_map(
            fn (string $entity) => new SyncQuickBooksEntityJob($token->id, $entity),
            QuickBooksService::PAGED_ENTITIES
        );

        // Added to the live batch so it stays open until the full sync is done.
        if ($batch = $this->batch()) {
            $batch->add($jobs);

            return;
        }

        foreach ($jobs as $job) {
            dispatch($job);
        }
    }

    /**
     * Retries are exhausted, or the job failed itself on a reauthorization error.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("QuickBooks: change sync failed permanently for token #{$this->tokenId}.", [
            'since' => $this->since,
            'error' => $exception->getMessage(),
        ]);

        $token = QuickBooksToken::find($this->tokenId);

        if (! $token) {
            return;
        }

        // Only the rows this job owns. Single steps in the same batch report
        // their own outcome.
        foreach (QuickBooksService::PAGED_ENTITIES as $entity) {
            QuickBooksSyncState::markFailed($token->realm_id, $entity, $exception->getMessage());
        }
    }
}
