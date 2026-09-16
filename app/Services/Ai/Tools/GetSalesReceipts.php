<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksSalesReceipt;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetSalesReceipts implements AiTool
{
    use ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_sales_receipts';
    }

    public function description(): string
    {
        return 'List sales receipts (sales paid for on the spot, without an invoice), optionally filtered by customer and period, most recent first, with their total.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer' => ['type' => 'string', 'description' => 'Filter by customer name (partial match).'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by sale date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max sales receipts to return (default 20, max 50).'],
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

        $query = QuickBooksSalesReceipt::where('realm_id', $context->realmId);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['customer_name']);

        if (! empty($arguments['customer'])) {
            $query->where('customer_name', 'like', '%'.$arguments['customer'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        return $this->withDataFreshness([
            'sales_receipts' => (clone $query)
                ->orderByDesc('txn_date')
                ->limit($limit)
                ->get()
                ->map(fn ($receipt) => [
                    'doc_number' => $receipt->doc_number,
                    'customer_name' => $receipt->customer_name,
                    'txn_date' => optional($receipt->txn_date)->toDateString(),
                    'total_amount' => (float) $receipt->total_amount,
                    'payment_method' => $receipt->payment_method,
                ])
                ->all(),
            'total_count' => (clone $query)->count(),
            'total_amount' => (float) (clone $query)->sum('total_amount'),
        ], $context, ['sales_receipts']);
    }
}
