<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksInvoice;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;
use Illuminate\Support\Facades\DB;

class GetRevenue implements AiTool
{
    use ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_revenue';
    }

    public function description(): string
    {
        return 'Get total revenue (paid invoices) for a period, broken down by top 10 customers.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'The period to report on.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
            ],
            'required' => ['period'],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $range = DateRangeResolver::resolve(
            $arguments['period'] ?? '',
            $arguments['start_date'] ?? null,
            $arguments['end_date'] ?? null
        );

        $query = QuickBooksInvoice::where('realm_id', $context->realmId)
            ->where('status', 'Paid')
            ->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['customer_name']);

        $byCustomer = (clone $query)
            ->select('customer_name', 'customer_qbo_id', DB::raw('SUM(total_amount) as total'))
            ->groupBy('customer_name', 'customer_qbo_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'customer_name' => $row->customer_name,
                'customer_qbo_id' => $row->customer_qbo_id,
                'total' => (float) $row->total,
            ])
            ->all();

        return [
            'period_label' => $range['label'],
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'revenue' => (float) (clone $query)->sum('total_amount'),
            'invoice_count' => (clone $query)->count(),
            'by_customer' => $byCustomer,
        ];
    }
}
