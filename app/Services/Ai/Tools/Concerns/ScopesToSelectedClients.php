<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Services\Ai\QuickBooksAiContext;
use App\Support\QuickBooksClientScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client scoping for AI tools, which receive a QuickBooksAiContext and never the
 * token or raw request input.
 *
 * Delegates to QuickBooksClientScope rather than keeping its own copy. An
 * earlier copy here drifted from the controller's and failed open for users
 * whose client match had not resolved.
 */
trait ScopesToSelectedClients
{
    /**
     * @param  array<int, string>  $nameColumns
     */
    private function applyClientScope(
        Builder $query,
        QuickBooksAiContext $context,
        string $qboIdColumn,
        array $nameColumns
    ): void {
        QuickBooksClientScope::applyForContext($query, $context, $qboIdColumn, $nameColumns);
    }
}
