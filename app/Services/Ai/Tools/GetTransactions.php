<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksTransaction;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetTransactions implements AiTool
{
    use ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_transactions';
    }

    public function description(): string
    {
        return 'List purchase/expense transactions, optionally filtered by type and/or period, most recent first.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'Filter by transaction type (e.g. Cash, Check, CreditCard).'],
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

        return [
            'transactions' => $transactions,
            'total_count' => $totalCount,
        ];
    }
}
