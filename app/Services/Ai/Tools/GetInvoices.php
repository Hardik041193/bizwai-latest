<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksInvoice;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\FiltersOpenItems;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetInvoices implements AiTool
{
    use FiltersOpenItems, ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_invoices';
    }

    public function description(): string
    {
        return 'List customer invoices with their count, the total originally invoiced and the total still unpaid, optionally filtered by customer, payment status, days overdue and period. Use it for unpaid, overdue, paid, partially paid and largest invoices, for what one customer was invoiced, and for accounts receivable (money customers owe): status "Unpaid" gives the total and a breakdown per customer.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => self::OPEN_ITEM_STATUSES,
                    'description' => 'Unpaid: any invoice with a balance still owed, whether overdue or not. Overdue: unpaid and past its due date. Open: unpaid and not yet due. PartiallyPaid: some but not all of it has been paid. Paid: paid in full.',
                ],
                'customer' => ['type' => 'string', 'description' => 'Filter by customer name (partial match).'],
                'min_days_overdue' => ['type' => 'integer', 'description' => 'Only unpaid invoices at least this many days past their due date, e.g. 30 or 60.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by invoice date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['recent', 'amount', 'balance'],
                    'description' => 'recent (default): newest first. amount: largest invoice first. balance: largest unpaid balance first.',
                ],
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

        $this->applyOpenItemStatus($query, $arguments['status'] ?? null);

        if (! empty($arguments['min_days_overdue'])) {
            $this->applyMinDaysOverdue($query, (int) $arguments['min_days_overdue']);
        }

        if (! empty($arguments['customer'])) {
            $query->where('customer_name', 'like', '%'.$arguments['customer'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        $sortColumn = match ($arguments['sort'] ?? 'recent') {
            'amount' => 'total_amount',
            'balance' => 'balance',
            default => 'txn_date',
        };

        $invoices = (clone $query)
            ->orderByDesc($sortColumn)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($inv) => [
                'doc_number' => $inv->doc_number,
                'customer_name' => $inv->customer_name,
                'txn_date' => optional($inv->txn_date)->toDateString(),
                'due_date' => optional($inv->due_date)->toDateString(),
                'total_amount' => (float) $inv->total_amount,
                'balance' => (float) $inv->balance,
            ] + $this->openItemState($inv))
            ->all();

        // Totals cover every matching invoice, not only the rows listed, so the
        // assistant never has to add up a list that the limit cut short.
        return $this->withDataFreshness([
            'invoices' => $invoices,
            'total_count' => (clone $query)->count(),
            // Named so the amount still owed cannot be mistaken for the
            // original value of the invoices.
            'total_invoiced' => round((float) (clone $query)->sum('total_amount'), 2),
            'total_unpaid_balance' => round((float) (clone $query)->sum('balance'), 2),
            'balance_by_customer' => (clone $query)
                ->where('balance', '>', 0)
                ->selectRaw('customer_name, COUNT(*) as invoice_count, SUM(balance) as balance')
                ->groupBy('customer_name')
                ->orderByDesc('balance')
                ->limit(25)
                ->get()
                ->map(fn ($row) => [
                    'customer_name' => $row->customer_name,
                    'unpaid_invoices' => (int) $row->invoice_count,
                    'balance' => round((float) $row->balance, 2),
                ])
                ->all(),
        ], $context, ['invoices']);
    }
}
