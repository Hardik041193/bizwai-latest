<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksBill;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Support\QuickBooksClientScope;

class GetBills implements AiTool
{
    use ReportsDataFreshness;

    public function name(): string
    {
        return 'get_bills';
    }

    public function description(): string
    {
        return 'List supplier bills (money the company owes its vendors), optionally filtered by status, vendor and period, most recent first, with the balance still owed. Company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['Open', 'Overdue', 'Paid'], 'description' => 'Filter by bill status.'],
                'vendor' => ['type' => 'string', 'description' => 'Filter by vendor name (partial match).'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by bill date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
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

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        if (! empty($arguments['vendor'])) {
            $query->where('vendor_name', 'like', '%'.$arguments['vendor'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        $totalCount = (clone $query)->count();
        $balanceOwed = (float) (clone $query)->whereIn('status', ['Open', 'Overdue'])->sum('balance');

        $bills = (clone $query)
            ->orderByDesc('txn_date')
            ->limit($limit)
            ->get()
            ->map(fn ($bill) => [
                'doc_number' => $bill->doc_number,
                'vendor_name' => $bill->vendor_name,
                'txn_date' => optional($bill->txn_date)->toDateString(),
                'due_date' => optional($bill->due_date)->toDateString(),
                'total_amount' => (float) $bill->total_amount,
                'balance' => (float) $bill->balance,
                'status' => $bill->status,
            ])
            ->all();

        return $this->withDataFreshness([
            'bills' => $bills,
            'total_count' => $totalCount,
            'balance_owed' => $balanceOwed,
        ], $context, ['bills']);
    }
}
