<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Services\Ai\QuickBooksAiContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mirrors App\Support\QuickBooksClientScope but keyed off QuickBooksAiContext
 * instead of a QuickBooksToken model, since tools only ever receive the
 * context — never the token or raw request input. Keep the filtering logic
 * identical to QuickBooksClientScope; do not let the two drift.
 */
trait ScopesToSelectedClients
{
    private function applyClientScope(
        Builder $query,
        QuickBooksAiContext $context,
        string $qboIdColumn,
        array $nameColumns
    ): void {
        if ($context->hasAllClients || empty($context->selectedClientQboIds) && empty($context->selectedClientNames)) {
            return;
        }

        $qboIds = $context->selectedClientQboIds;
        $names = $context->selectedClientNames;

        $query->where(function (Builder $q) use ($qboIds, $names, $qboIdColumn, $nameColumns) {
            $q->whereIn($qboIdColumn, $qboIds);

            foreach ($nameColumns as $nameColumn) {
                $q->orWhereIn($nameColumn, $names);
            }
        });
    }
}
