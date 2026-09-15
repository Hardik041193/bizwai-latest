<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksInvoice;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetInvoices implements AiTool
{
    use ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_invoices';
    }

    public function description(): string
    {
        return 'List invoices, optionally filtered by status and/or period, most recent first.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['Open', 'Paid', 'Overdue'], 'description' => 'Filter by invoice status.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by transaction date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max invoices to return (default 20, max 50).'],
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

        $query = QuickBooksInvoice::where('realm_id', $context->realmId);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['customer_name']);

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        $totalCount = (clone $query)->count();

        $invoices = (clone $query)
            ->orderByDesc('txn_date')
            ->limit($limit)
            ->get()
            ->map(fn ($inv) => [
                'doc_number' => $inv->doc_number,
                'customer_name' => $inv->customer_name,
                'txn_date' => optional($inv->txn_date)->toDateString(),
                'due_date' => optional($inv->due_date)->toDateString(),
                'total_amount' => (float) $inv->total_amount,
                'balance' => (float) $inv->balance,
                'status' => $inv->status,
            ])
            ->all();

        return $this->withDataFreshness([
            'invoices' => $invoices,
            'total_count' => $totalCount,
        ], $context, ['invoices']);
    }
}
