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
     * Whether the caller's data scope has not been resolved yet.
     *
     * Client matching runs as part of the background sync, so between
     * connecting and that entity completing a token has no selection at all.
     * Returning "no filter" in that window would hand a client-scoped user the
     * whole company's financials, so the scope fails closed instead: non-admins
     * see nothing until their scope is known. Admins are unscoped by design.
     */
    private static function deniesEverything(QuickBooksToken $token): bool
    {
        if ($token->hasCompletedClientSelection()) {
            return false;
        }

        return ! ($token->user?->isAdmin() ?? false);
    }

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
        if (self::deniesEverything($token)) {
            $query->whereRaw('1 = 0');

            return;
        }

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
        if (self::deniesEverything($token)) {
            $query->whereRaw('1 = 0');

            return;
        }

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
