<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksBill;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\FiltersOpenItems;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Support\QuickBooksClientScope;
use Carbon\Carbon;

class GetBills implements AiTool
{
    use FiltersOpenItems, ReportsDataFreshness;

    public function name(): string
    {
        return 'get_bills';
    }

    public function description(): string
    {
        return 'List supplier bills (money the company owes its vendors) with their count, total value and total still owed, optionally filtered by vendor, payment status, days overdue, due date and period. Use it for unpaid and overdue bills, bills due soon, what was billed by one vendor, and accounts payable: status "Unpaid" gives the total owed and a breakdown per vendor. Company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => self::OPEN_ITEM_STATUSES,
                    'description' => 'Unpaid: any bill with a balance still owed, whether overdue or not. Overdue: unpaid and past its due date. Open: unpaid and not yet due. PartiallyPaid: some but not all of it has been paid. Paid: paid in full.',
                ],
                'vendor' => ['type' => 'string', 'description' => 'Filter by vendor name (partial match).'],
                'min_days_overdue' => ['type' => 'integer', 'description' => 'Only unpaid bills at least this many days past their due date, e.g. 30 or 60.'],
                'due_within_days' => ['type' => 'integer', 'description' => 'Only unpaid bills falling due from today up to this many days ahead, e.g. 7.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by bill date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['recent', 'amount', 'balance'],
                    'description' => 'recent (default): newest first. amount: largest bill first. balance: largest amount owed first.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Max bills to return (default 20, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        // A bill is owed by the company to a supplier and belongs to no client,
        // so client scoping cannot narrow it: only a caller who sees the whole
        // company may list bills at all.
        if (! QuickBooksClientScope::contextSeesWholeCompany($context)) {
            if (! $context->scopeResolved && ! $context->isAdmin) {
                return ['error' => 'client_access_pending', 'message' => 'Access to this data is still being set up for this user.'];
            }

            return [
                'error' => 'company_level_data',
                'message' => 'Supplier bills belong to the company as a whole, so they are not available to users limited to specific clients.',
            ];
        }

        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);

        $query = QuickBooksBill::where('realm_id', $context->realmId);

        $this->applyOpenItemStatus($query, $arguments['status'] ?? null);

        if (! empty($arguments['min_days_overdue'])) {
            $this->applyMinDaysOverdue($query, (int) $arguments['min_days_overdue']);
        }

        if (! empty($arguments['due_within_days'])) {
            $query->where('balance', '>', 0)->whereBetween('due_date', [
                Carbon::today()->toDateString(),
                Carbon::today()->addDays((int) $arguments['due_within_days'])->toDateString(),
            ]);
        }

        if (! empty($arguments['vendor'])) {
            $query->where('vendor_name', 'like', '%'.$arguments['vendor'].'%');
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

        $bills = (clone $query)
            ->orderByDesc($sortColumn)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($bill) => [
                'doc_number' => $bill->doc_number,
                'vendor_name' => $bill->vendor_name,
                'txn_date' => optional($bill->txn_date)->toDateString(),
                'due_date' => optional($bill->due_date)->toDateString(),
                'total_amount' => (float) $bill->total_amount,
                'balance' => (float) $bill->balance,
            ] + $this->openItemState($bill))
            ->all();

        // Totals cover every matching bill, not only the rows listed.
        return $this->withDataFreshness([
            'bills' => $bills,
            'total_count' => (clone $query)->count(),
            'total_billed' => round((float) (clone $query)->sum('total_amount'), 2),
            'balance_owed' => round((float) (clone $query)->where('balance', '>', 0)->sum('balance'), 2),
            'by_vendor' => (clone $query)
                ->selectRaw('vendor_name, COUNT(*) as bill_count, SUM(total_amount) as billed, SUM(balance) as balance')
                ->groupBy('vendor_name')
                ->orderByDesc('billed')
                ->limit(25)
                ->get()
                ->map(fn ($row) => [
                    'vendor_name' => $row->vendor_name,
                    'bills' => (int) $row->bill_count,
                    'total_billed' => round((float) $row->billed, 2),
                    'balance_owed' => round((float) $row->balance, 2),
                ])
                ->all(),
        ], $context, ['bills']);
    }
}
