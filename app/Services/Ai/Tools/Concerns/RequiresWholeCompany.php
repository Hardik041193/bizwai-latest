<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Services\Ai\QuickBooksAiContext;
use App\Support\QuickBooksClientScope;

/**
 * For tools whose data belongs to the company as a whole and to no client:
 * bank balances, the balance sheet, cash flow, money owed to vendors. Client
 * scoping cannot narrow such data, so only a caller who sees the whole company
 * may have it at all.
 */
trait RequiresWholeCompany
{
    /**
     * @param  string  $what  the data being asked for, to start a sentence, e.g. "Bank and account balances"
     * @return array{error: string, message: string}|null  null when the caller may see it
     */
    private function wholeCompanyError(QuickBooksAiContext $context, string $what): ?array
    {
        if (QuickBooksClientScope::contextSeesWholeCompany($context)) {
            return null;
        }

        if (! $context->scopeResolved && ! $context->isAdmin) {
            return ['error' => 'client_access_pending', 'message' => 'Access to this data is still being set up for this user.'];
        }

        return [
            'error' => 'company_level_data',
            'message' => "{$what} belong to the company as a whole, so they are not available to users limited to specific clients.",
        ];
    }
}
