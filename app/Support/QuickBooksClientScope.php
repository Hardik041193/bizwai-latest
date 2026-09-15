<?php

namespace App\Support;

use App\Models\QuickBooksToken;
use App\Services\Ai\QuickBooksAiContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Selected-client scoping for synced QuickBooks data. The one implementation:
 * QuickBooksController reaches it through a token, the AI tools through
 * QuickBooksAiContext.
 *
 * There used to be two copies, this one and one in the AI tools' trait, each
 * marked "do not let the two drift". They drifted. R1b made this copy fail
 * closed, but the AI copy still failed open, so a non-admin whose client match
 * had not resolved could read the whole company's data through the chat. Both
 * callers now reach the same code, so they cannot disagree again.
 *
 * Filters by customer_qbo_id (rename-proof), falling back to the legacy name
 * column(s) for rows synced before customer_qbo_id existed.
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
        self::apply($query, self::scopeOfToken($token), $qboIdColumn, [$nameColumn]);
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
        self::apply($query, self::scopeOfToken($token), $qboIdColumn, [$nameColumnA, $nameColumnB]);
    }

    /**
     * The same filter for the AI tools, which only ever receive a context.
     *
     * @param  array<int, string>  $nameColumns
     */
    public static function applyForContext(
        Builder $query,
        QuickBooksAiContext $context,
        string $qboIdColumn,
        array $nameColumns
    ): void {
        self::apply($query, [
            'is_admin' => $context->isAdmin,
            'resolved' => $context->scopeResolved,
            'specific' => $context->scopeResolved && ! $context->hasAllClients,
            'qbo_ids' => $context->selectedClientQboIds,
            'names' => $context->selectedClientNames,
        ], $qboIdColumn, $nameColumns);
    }

    /**
     * @return array{is_admin: bool, resolved: bool, specific: bool, qbo_ids: array<int, string>, names: array<int, string>}
     */
    private static function scopeOfToken(QuickBooksToken $token): array
    {
        return [
            'is_admin' => $token->user?->isAdmin() ?? false,
            'resolved' => $token->hasCompletedClientSelection(),
            'specific' => $token->hasSelectedClient(),
            'qbo_ids' => $token->selectedClientQboIds(),
            'names' => $token->selectedClientNames(),
        ];
    }

    /**
     * @param  array{is_admin: bool, resolved: bool, specific: bool, qbo_ids: array<int, string>, names: array<int, string>}  $scope
     * @param  array<int, string>  $nameColumns
     */
    private static function apply(Builder $query, array $scope, string $qboIdColumn, array $nameColumns): void
    {
        // Client matching runs as part of the background sync, so between
        // connecting and that step completing there is no selection at all.
        // Returning "no filter" in that window would hand a client-scoped user
        // the whole company's financials, so non-admins see nothing until their
        // scope is known. Admins are unscoped by design.
        if (! $scope['resolved'] && ! $scope['is_admin']) {
            $query->whereRaw('1 = 0');

            return;
        }

        // All clients, or an admin whose scope has not resolved.
        if (! $scope['specific']) {
            return;
        }

        $query->where(function (Builder $q) use ($scope, $qboIdColumn, $nameColumns) {
            $q->whereIn($qboIdColumn, $scope['qbo_ids']);

            foreach ($nameColumns as $nameColumn) {
                $q->orWhereIn($nameColumn, $scope['names']);
            }
        });
    }
}
