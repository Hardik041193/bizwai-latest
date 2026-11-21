<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksRefundReceipt;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetRefunds implements AiTool
{
    use ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_refunds';
    }

    public function description(): string
    {
        return 'List refunds paid back to customers (refund receipts), optionally filtered by customer and period, most recent first, with what was refunded, how it was paid and the total refunded. A credit left on a customer\'s account instead of paid back is a credit memo, not a refund.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer' => ['type' => 'string', 'description' => 'Filter by customer name (partial match).'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max refunds to return (default 20, max 50).'],
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

        $query = QuickBooksRefundReceipt::where('realm_id', $context->realmId);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['customer_name']);

        if (! empty($arguments['customer'])) {
            $query->where('customer_name', 'like', '%'.$arguments['customer'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        return $this->withDataFreshness([
            'refunds' => (clone $query)->orderByDesc('txn_date')->limit($limit)->get()->map(fn ($refund) => [
                'doc_number' => $refund->doc_number,
                'customer_name' => $refund->customer_name,
                'txn_date' => optional($refund->txn_date)->toDateString(),
                'total_amount' => (float) $refund->total_amount,
                'payment_method' => $refund->payment_method,
                'paid_from_account' => $refund->account_name,
                'refunded_for' => collect($refund->line_items ?? [])->map(fn ($line) => $line['description'] ?? $line['item_name'] ?? null)->filter()->values()->all(),
            ])->all(),
            'total_count' => (clone $query)->count(),
            'total_refunded' => round((float) (clone $query)->sum('total_amount'), 2),
        ], $context, ['refund_receipts']);
    }
}
