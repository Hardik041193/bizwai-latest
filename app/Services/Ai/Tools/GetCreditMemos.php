<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksCreditMemo;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetCreditMemos implements AiTool
{
    use ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_credit_memos';
    }

    public function description(): string
    {
        return 'List credit memos (credits issued to customers, such as refunds or adjustments), optionally filtered by customer and period, most recent first, with the credit issued and the credit still available to use.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer' => ['type' => 'string', 'description' => 'Filter by customer name (partial match).'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by credit memo date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max credit memos to return (default 20, max 50).'],
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

        $query = QuickBooksCreditMemo::where('realm_id', $context->realmId);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['customer_name']);

        if (! empty($arguments['customer'])) {
            $query->where('customer_name', 'like', '%'.$arguments['customer'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        return $this->withDataFreshness([
            'credit_memos' => (clone $query)
                ->orderByDesc('txn_date')
                ->limit($limit)
                ->get()
                ->map(fn ($memo) => [
                    'doc_number' => $memo->doc_number,
                    'customer_name' => $memo->customer_name,
                    'txn_date' => optional($memo->txn_date)->toDateString(),
                    'total_amount' => (float) $memo->total_amount,
                    'remaining_credit' => (float) $memo->remaining_credit,
                ])
                ->all(),
            'total_count' => (clone $query)->count(),
            'total_credited' => (float) (clone $query)->sum('total_amount'),
            'remaining_credit' => (float) (clone $query)->sum('remaining_credit'),
        ], $context, ['credit_memos']);
    }
}
