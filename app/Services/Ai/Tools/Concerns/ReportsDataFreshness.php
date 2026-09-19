<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Models\QuickBooksSyncState;
use App\Services\Ai\QuickBooksAiContext;

/**
 * For tools that answer from synced tables rather than live QuickBooks reports.
 *
 * While a sync is still importing, or after a step failed, those tables can be
 * incomplete or out of date. Without saying so, "3 overdue invoices" reads as a
 * fact when it only covers what has been imported. Each result carries the state
 * of the data it was built from, so the assistant can qualify its answer.
 *
 * Figures from QuickBooks' own reports do not need this: they are read live.
 */
trait ReportsDataFreshness
{
    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, string>  $entities  the sync steps the result was read from
     * @return array<string, mixed>
     */
    private function withDataFreshness(array $result, QuickBooksAiContext $context, array $entities): array
    {
        $rows = collect(QuickBooksSyncState::progressFor($context->realmId)['entities'])
            ->whereIn('entity', $entities);

        // "idle" means the step has never run for this company, so its data has
        // not been imported at all.
        $stillImporting = $rows->whereIn('status', ['idle', 'pending', 'syncing'])->pluck('label')->values()->all();
        $failed = $rows->where('status', 'failed')->pluck('label')->values()->all();

        $result['data_freshness'] = [
            'complete' => $stillImporting === [] && $failed === [],
            'still_importing' => $stillImporting,
            // The latest attempt failed; what is shown may be from an earlier sync.
            'failed_to_import' => $failed,
            // The oldest of the steps read, so no part of the answer is older.
            'last_synced_at' => $rows->pluck('last_synced_at')->filter()->min(),
        ];

        return $result;
    }
}
