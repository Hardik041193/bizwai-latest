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
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Sync one step of a QuickBooks realm: a single-shot step (company info, client
 * matching) or one page of a paged entity.
 *
 * This replaces one job that ran every entity back to back. Each step now has
 * its own timeout and its own retries, so a failing invoices page retries that
 * page alone rather than re-running every entity, and adding entities no longer
 * lengthens a single job towards its timeout.
 */
class SyncQuickBooksEntityJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * One page is a single API call plus up to 1000 upserts, comfortably inside
     * this. Must stay below the queue connection's retry_after (900).
     */
    public int $timeout = 300;

    public function __construct(
        public readonly int $tokenId,
        public readonly string $entity,
        public readonly int $startPosition = 1,
        // Chosen by the first page and carried to every later one; see
        // QuickBooksService::syncEntityPage() for why it cannot be re-derived.
        public readonly ?string $query = null,
    ) {
        $this->onQueue('quickbooks');
    }

    public function handle(QuickBooksService $service): void
    {
        // A reauthorization failure elsewhere in this run cancels the batch.
        if ($this->batch()?->cancelled()) {
            return;
        }

        $token = QuickBooksToken::find($this->tokenId);

        // Disconnected mid-sync; disconnect() has already purged this realm's state.
        if (! $token) {
            return;
        }

        try {
            if (in_array($this->entity, QuickBooksService::PAGED_ENTITIES, true)) {
                $this->syncPage($service, $token);
            } else {
                $this->syncSingleStep($service, $token);
            }
        } catch (QuickBooksReauthorizationRequired $e) {
            // No retry can succeed, and every other step of this run would hit
            // the same wall. Close the whole run out now so the progress screen
            // stops straight away, instead of after three backed-off attempts at
            // each of six steps.
            QuickBooksSyncState::failUnfinished($token->realm_id, $e->getMessage());
            $this->batch()?->cancel();
            $this->fail($e);
        }
    }

    private function syncPage(QuickBooksService $service, QuickBooksToken $token): void
    {
        if ($this->startPosition === 1) {
            QuickBooksSyncState::markSyncing($token->realm_id, $this->entity);
        }

        $page = $service->syncEntityPage($token, $this->entity, $this->startPosition, $this->query);

        // Positions are 1-based: this page covered startPosition .. startPosition + count - 1.
        $recordsSoFar = $this->startPosition - 1 + $page['count'];

        if ($page['next_position'] === null) {
            QuickBooksSyncState::markComplete($token->realm_id, $this->entity, $recordsSoFar);

            return;
        }

        QuickBooksSyncState::recordPageProgress(
            $token->realm_id, $this->entity, $recordsSoFar, $page['next_position']
        );

        $next = new self($this->tokenId, $this->entity, $page['next_position'], $page['query']);

        // Adding to the batch keeps it open until the last page, so its finally
        // callback cannot fire while pages are still outstanding.
        $this->batch() ? $this->batch()->add([$next]) : dispatch($next);
    }

    private function syncSingleStep(QuickBooksService $service, QuickBooksToken $token): void
    {
        QuickBooksSyncState::markSyncing($token->realm_id, $this->entity);

        $count = match ($this->entity) {
            'company_info' => $service->syncCompanyInfo($token),
            'client_matching' => $service->syncClientMatching($token),
            default => throw new InvalidArgumentException("Unknown QuickBooks sync step: {$this->entity}"),
        };

        QuickBooksSyncState::markComplete($token->realm_id, $this->entity, $count);
    }

    /**
     * Retries are exhausted, or the job failed itself on a reauthorization error.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("QuickBooks: {$this->entity} sync failed permanently for token #{$this->tokenId}.", [
            'start_position' => $this->startPosition,
            'error' => $exception->getMessage(),
        ]);

        $token = QuickBooksToken::find($this->tokenId);

        if ($token) {
            QuickBooksSyncState::markFailed($token->realm_id, $this->entity, $exception->getMessage());
        }
    }
}
