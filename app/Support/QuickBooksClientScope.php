<?php

namespace App\Support;

use App\Models\QuickBooksToken;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared "selected client" scoping logic used by QuickBooksController and the
 * AI chat tools, so the two never drift apart.
 *
 * Filters by customer_qbo_id (rename-proof) OR falls back to matching the
 * legacy name column(s), for rows synced before customer_qbo_id existed.
 */
class QuickBooksClientScope
{
    /**
     * Apply the selected-client filter to a query that has a single
     * "name" column to match against (invoices.customer_name,
     * transactions.entity_name) and a customer_qbo_id column.
     */
    public static function applyToQboIdAndSingleNameColumn(
        Builder $query,
        QuickBooksToken $token,
        string $qboIdColumn,
        string $nameColumn
    ): void {
        if (! $token->hasSelectedClient()) {
            return;
        }

        $qboIds = $token->selectedClientQboIds();
        $names = $token->selectedClientNames();

        $query->where(function (Builder $q) use ($qboIds, $names, $qboIdColumn, $nameColumn) {
            $q->whereIn($qboIdColumn, $qboIds)
                ->orWhereIn($nameColumn, $names);
        });
    }

    /**
     * Apply the selected-client filter to a query whose "name" can appear in
     * either of two columns (quickbooks_customers.display_name/company_name)
     * alongside its own qbo_id column.
     */
    public static function applyToQboIdAndTwoNameColumns(
        Builder $query,
        QuickBooksToken $token,
        string $qboIdColumn,
        string $nameColumnA,
        string $nameColumnB
    ): void {
        if (! $token->hasSelectedClient()) {
            return;
        }

        $qboIds = $token->selectedClientQboIds();
        $names = $token->selectedClientNames();

        $query->where(function (Builder $q) use ($qboIds, $names, $qboIdColumn, $nameColumnA, $nameColumnB) {
            $q->whereIn($qboIdColumn, $qboIds)
                ->orWhereIn($nameColumnA, $names)
                ->orWhereIn($nameColumnB, $names);
        });
    }
}
