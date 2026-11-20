<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksTransaction;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetTransactions implements AiTool
{
    use ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_transactions';
    }

    public function description(): string
    {
        return 'List purchases the company paid for directly (cash, cheque and card expenses), with their count and total, optionally filtered by payee (the vendor paid), expense account, payment type and period, most recent first. Use it for what was spent with one vendor and for the transactions behind an expense figure. These are money going out to vendors: it is not a customer\'s history, so never use it to show what a customer was invoiced or paid.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Filter by transaction type (e.g. Cash, Check, CreditCard).'],
                'payee' => ['type' => 'string', 'description' => 'Filter by the vendor or other payee the money went to (partial match).'],
                'account' => ['type' => 'string', 'description' => 'Filter by expense account name (partial match), e.g. Advertising.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by transaction date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max transactions to return (default 20, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);

        $query = QuickBooksTransaction::where('realm_id', $context->realmId);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['entity_name']);

        if (! empty($arguments['type'])) {
            $query->where('txn_type', $arguments['type']);
        }

        if (! empty($arguments['payee'])) {
            $query->where('entity_name', 'like', '%'.$arguments['payee'].'%');
        }

        if (! empty($arguments['account'])) {
            $query->where('account_name', 'like', '%'.$arguments['account'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        $totalCount = (clone $query)->count();

        $transactions = (clone $query)
            ->orderByDesc('txn_date')
            ->limit($limit)
            ->get()
            ->map(fn ($txn) => [
                'txn_type' => $txn->txn_type,
                'txn_date' => optional($txn->txn_date)->toDateString(),
                'account_name' => $txn->account_name,
                'entity_name' => $txn->entity_name,
                'amount' => (float) $txn->amount,
                'description' => $txn->description,
            ])
            ->all();

        return $this->withDataFreshness([
            'transactions' => $transactions,
            'total_count' => $totalCount,
            'total_amount' => round((float) (clone $query)->sum('amount'), 2),
            'by_payee' => (clone $query)
                ->whereNotNull('entity_name')
                ->selectRaw('entity_name, COUNT(*) as txn_count, SUM(amount) as amount')
                ->groupBy('entity_name')
                ->orderByDesc('amount')
                ->limit(25)
                ->get()
                ->map(fn ($row) => [
                    'payee' => $row->entity_name,
                    'transactions' => (int) $row->txn_count,
                    'amount' => round((float) $row->amount, 2),
                ])
                ->all(),
        ], $context, ['transactions']);
    }
}
