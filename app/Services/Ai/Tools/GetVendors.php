<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksBill;
use App\Models\QuickBooksBillPayment;
use App\Models\QuickBooksTransaction;
use App\Models\QuickBooksVendor;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetVendors implements AiTool
{
    use ReportsDataFreshness, RequiresWholeCompany;

    public function name(): string
    {
        return 'get_vendors';
    }

    public function description(): string
    {
        return 'List vendors (suppliers) with what the company currently owes each one and, for a period or all time, what was spent with each: bills received from them, purchases paid to them directly, the two added together as total spending, and what was paid against their bills. Use it for top vendors by spending, how much was spent with one vendor, which vendor was paid the most, and how much is owed to each vendor. To see how a vendor\'s spending changed, call it once for each period. Company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Filter by vendor name (partial match).'],
                'with_balance_only' => ['type' => 'boolean', 'description' => 'Only vendors the company currently owes money to.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'sort' => ['type' => 'string', 'enum' => ['spending', 'paid', 'balance', 'name'], 'description' => 'spending (default): largest total spending first. paid: largest payments made first. balance: largest amount owed first. name: alphabetical.'],
                'limit' => ['type' => 'integer', 'description' => 'Max vendors to return (default 10, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        if ($error = $this->wholeCompanyError($context, 'Vendor records')) {
            return $error;
        }

        $limit = min(max((int) ($arguments['limit'] ?? 10), 1), 50);
        $range = empty($arguments['period'])
            ? null
            : DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);

        $inPeriod = function ($query) use ($range) {
            if ($range) {
                $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
            }

            return $query;
        };

        $realm = $context->realmId;

        // Bills and bill payments are matched to a vendor by id. A direct
        // purchase only carries the payee's name, which may be a vendor.
        $billed = $inPeriod(QuickBooksBill::where('realm_id', $realm))
            ->selectRaw('vendor_qbo_id, SUM(total_amount) as amount')->groupBy('vendor_qbo_id')->pluck('amount', 'vendor_qbo_id');
        $paid = $inPeriod(QuickBooksBillPayment::where('realm_id', $realm))
            ->selectRaw('vendor_qbo_id, SUM(total_amount) as amount')->groupBy('vendor_qbo_id')->pluck('amount', 'vendor_qbo_id');
        $purchased = $inPeriod(QuickBooksTransaction::where('realm_id', $realm))->whereNotNull('entity_name')
            ->selectRaw('entity_name, SUM(amount) as amount')->groupBy('entity_name')->pluck('amount', 'entity_name');

        $query = QuickBooksVendor::where('realm_id', $realm);

        if (! empty($arguments['search'])) {
            $query->where('display_name', 'like', '%'.$arguments['search'].'%');
        }

        if (! empty($arguments['with_balance_only'])) {
            $query->where('balance', '>', 0);
        }

        $vendors = $query->get()->map(function ($vendor) use ($billed, $paid, $purchased) {
            $bills = round((float) ($billed[$vendor->qbo_id] ?? 0), 2);
            $purchases = round((float) ($purchased[$vendor->display_name] ?? 0), 2);

            return [
                'vendor_name' => $vendor->display_name,
                'active' => (bool) $vendor->active,
                'balance_owed_now' => (float) $vendor->balance,
                'bills_received' => $bills,
                'direct_purchases' => $purchases,
                'total_spending' => round($bills + $purchases, 2),
                'paid_against_bills' => round((float) ($paid[$vendor->qbo_id] ?? 0), 2),
            ];
        });

        $sorted = match ($arguments['sort'] ?? 'spending') {
            'paid' => $vendors->sortByDesc('paid_against_bills'),
            'balance' => $vendors->sortByDesc('balance_owed_now'),
            'name' => $vendors->sortBy('vendor_name', SORT_NATURAL | SORT_FLAG_CASE),
            default => $vendors->sortByDesc('total_spending'),
        };

        return $this->withDataFreshness([
            'period' => $range ? ['label' => $range['label'], 'start_date' => $range['start']->toDateString(), 'end_date' => $range['end']->toDateString()] : 'all time',
            'vendors' => $sorted->take($limit)->values()->all(),
            'total_count' => $vendors->count(),
            'vendors_with_spending' => $vendors->where('total_spending', '>', 0)->count(),
            'totals' => [
                'balance_owed_now' => round($vendors->sum('balance_owed_now'), 2),
                'total_spending' => round($vendors->sum('total_spending'), 2),
                'paid_against_bills' => round($vendors->sum('paid_against_bills'), 2),
            ],
            'note' => 'total_spending is bills received plus purchases paid directly in the period. paid_against_bills is money that left to settle bills and is not added on top, since those bills are already counted. balance_owed_now is as of the last sync whatever the period.',
        ], $context, ['vendors', 'bills', 'bill_payments', 'transactions']);
    }
}
