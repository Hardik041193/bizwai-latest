<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksAccount;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetAccountBalances implements AiTool
{
    use ReportsDataFreshness, RequiresWholeCompany;

    /** Balance sheet accounts. Income and expense accounts carry no running balance. */
    private const CLASSIFICATIONS = ['Asset', 'Liability', 'Equity'];

    public function name(): string
    {
        return 'get_account_balances';
    }

    public function description(): string
    {
        return 'Current balances of the company\'s accounts as of the last sync: bank accounts (the cash balance), credit cards, loans, and other asset, liability and equity accounts, largest first. Use it for the current cash or bank balance, how much money is available across bank accounts, and which accounts have the highest balances. Company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account_type' => [
                    'type' => 'string',
                    'enum' => ['Bank', 'Accounts Receivable', 'Other Current Asset', 'Fixed Asset', 'Other Asset', 'Accounts Payable', 'Credit Card', 'Other Current Liability', 'Long Term Liability', 'Equity'],
                    'description' => 'Only accounts of this type. Use "Bank" for cash and bank balances.',
                ],
                'classification' => ['type' => 'string', 'enum' => self::CLASSIFICATIONS, 'description' => 'Only asset, liability or equity accounts.'],
                'search' => ['type' => 'string', 'description' => 'Filter by account name (partial match).'],
                'limit' => ['type' => 'integer', 'description' => 'Max accounts to return (default 25, max 100).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        if ($error = $this->wholeCompanyError($context, 'Bank and account balances')) {
            return $error;
        }

        $limit = min(max((int) ($arguments['limit'] ?? 25), 1), 100);

        $active = fn () => QuickBooksAccount::where('realm_id', $context->realmId)->where('active', true);

        $query = $active()->whereIn('classification', self::CLASSIFICATIONS);

        if (! empty($arguments['account_type'])) {
            $query->where('account_type', $arguments['account_type']);
        }

        if (! empty($arguments['classification'])) {
            $query->where('classification', $arguments['classification']);
        }

        if (! empty($arguments['search'])) {
            $query->where('name', 'like', '%'.$arguments['search'].'%');
        }

        return $this->withDataFreshness([
            'accounts' => (clone $query)
                ->orderByRaw('ABS(current_balance) DESC')
                ->limit($limit)
                ->get()
                ->map(fn ($account) => [
                    'name' => $account->name,
                    'account_type' => $account->account_type,
                    'classification' => $account->classification,
                    'balance' => (float) $account->current_balance,
                ])
                ->all(),
            'total_count' => (clone $query)->count(),
            // Only when one account type is asked for: a sum across assets,
            // liabilities and equity together is not a meaningful figure.
            'total_balance' => empty($arguments['account_type']) ? null : round((float) (clone $query)->sum('current_balance'), 2),
            // Always given, whatever the filter, so "cash balance" has one
            // unambiguous figure to quote.
            'cash_in_bank_accounts' => round((float) $active()->where('account_type', 'Bank')->sum('current_balance'), 2),
            'note' => 'Balances are as QuickBooks records them at the last sync. For totals of assets, liabilities or equity, use the balance sheet lookup rather than adding these up.',
        ], $context, ['accounts']);
    }
}
