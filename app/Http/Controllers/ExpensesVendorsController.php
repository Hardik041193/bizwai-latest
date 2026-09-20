<?php

namespace App\Http\Controllers;

use App\Models\QuickBooksBill;
use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksPayment;
use App\Models\QuickBooksSalesReceipt;
use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransaction;
use App\Services\QuickBooksReports;
use App\Support\QuickBooksClientScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Expenses and Vendors: spend by category, who it went to, and what looks
 * off, for the authenticated user's connected QuickBooks company.
 *
 * quickbooks_bills has no customer on it — vendor_qbo_id points at vendors,
 * which are not mirrored — so every bill-derived figure here is gated
 * behind QuickBooksClientScope::tokenSeesWholeCompany(), same as the cash
 * flow and profitability pages. A client-scoped user still sees their own
 * customer-attributed transactions (quickbooks_transactions carries a
 * customer_qbo_id), just not company-wide bills or unassigned spend.
 */
class ExpensesVendorsController extends Controller
{
    private const EXPENSE_TXN_TYPES = ['Purchase', 'Expense', 'Check'];

    /** @var array<string, array<int, string>> */
    private const CATEGORY_KEYWORDS = [
        'Payroll' => ['payroll', 'wage', 'salary', 'salaries'],
        'Materials' => ['material', 'supply', 'supplies'],
        'Contractors' => ['contractor', 'subcontract'],
        'Marketing' => ['marketing', 'advertis', 'promo'],
        'Software' => ['software', 'saas', 'subscription', 'hosting', 'cloud'],
    ];

    private const CATEGORY_COLORS = [
        'Payroll' => '#ef4444',
        'Materials' => '#f59e0b',
        'Contractors' => '#8b5cf6',
        'Marketing' => '#2563eb',
        'Software' => '#06b6d4',
        'Other' => '#d1d5db',
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $realmId = $token->realm_id;
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $priorMonthStart = $monthStart->copy()->subMonth();
        $priorMonthEnd = $monthStart->copy()->subSecond();
        $seesWholeCompany = QuickBooksClientScope::tokenSeesWholeCompany($token);

        $syncWarning = $this->syncWarning($realmId);

        $transactionQuery = QuickBooksTransaction::where('realm_id', $realmId)->whereIn('txn_type', self::EXPENSE_TXN_TYPES);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($transactionQuery, $token, 'customer_qbo_id', 'entity_name');

        $billQuery = $seesWholeCompany ? QuickBooksBill::where('realm_id', $realmId) : null;

        // ── Total expenses this month, and vs. prior month ──
        $currentTxnTotal = (float) (clone $transactionQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('amount');
        $currentBillTotal = $billQuery ? (float) (clone $billQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount') : 0.0;
        $totalExpenses = $currentTxnTotal + $currentBillTotal;

        $priorTxnTotal = (float) (clone $transactionQuery)->whereBetween('txn_date', [$priorMonthStart, $priorMonthEnd])->sum('amount');
        $priorBillTotal = $billQuery ? (float) (clone $billQuery)->whereBetween('txn_date', [$priorMonthStart, $priorMonthEnd])->sum('total_amount') : 0.0;
        $priorExpenses = $priorTxnTotal + $priorBillTotal;

        $changePct = $priorExpenses > 0 ? round((($totalExpenses - $priorExpenses) / $priorExpenses) * 100, 1) : null;

        // ── Top vendor this month ──
        $vendorTotals = $this->vendorTotals($transactionQuery, $billQuery, $monthStart, $now);
        $topVendor = $vendorTotals->sortDesc()->first();
        $topVendorName = $vendorTotals->sortDesc()->keys()->first();

        // ── Recurring spend: vendors appearing in at least 2 of the last 3
        // months, valued at their most recent month's spend ──
        $recurringSpend = $this->recurringSpend($transactionQuery, $billQuery, $now);

        // ── Expense categories, this month ──
        $categories = $this->expenseCategories($transactionQuery, $billQuery, $monthStart, $now);

        // ── AI checks — each included only when its condition is actually true ──
        $marketingCurrent = $categories->firstWhere('category', 'Marketing')['amount'] ?? 0.0;
        $marketingPrior = $this->categoryTotal($transactionQuery, $billQuery, 'Marketing', $priorMonthStart, $priorMonthEnd);
        $marketingChangePct = $marketingPrior > 0 ? round((($marketingCurrent - $marketingPrior) / $marketingPrior) * 100) : null;

        $duplicateBill = $billQuery ? $this->findDuplicateBill($billQuery) : null;
        $subscriptionRenewal = $this->findUpcomingSubscription($transactionQuery, $billQuery, $now);
        $uncategorizedCount = (clone $transactionQuery)
            ->whereBetween('txn_date', [$monthStart, $now])
            ->where(function (Builder $q) {
                $q->whereNull('account_name')->orWhere('account_name', '')->orWhere('account_name', 'like', '%uncategorized%');
            })
            ->count();

        $checks = [];
        if ($duplicateBill !== null) {
            $checks[] = [
                'type' => 'duplicate_bill',
                'severity' => 'danger',
                'title' => 'Duplicate bill possible',
                'detail' => $duplicateBill,
                'action_type' => 'review_duplicate',
            ];
        }
        if ($marketingChangePct !== null && $marketingChangePct >= 20) {
            $checks[] = [
                'type' => 'budget_spike',
                'severity' => 'warning',
                'title' => "Marketing up {$marketingChangePct}%",
                'detail' => 'Compare campaign spend',
                'action_type' => 'compare_spend',
            ];
        }
        if ($subscriptionRenewal !== null) {
            $checks[] = [
                'type' => 'subscription_renewal',
                'severity' => 'info',
                'title' => 'Subscription renewal',
                'detail' => $subscriptionRenewal,
                'action_type' => 'view_subscription',
            ];
        }
        if ($uncategorizedCount > 0) {
            $checks[] = [
                'type' => 'uncategorized',
                'severity' => 'purple',
                'title' => 'Uncategorized expense',
                'detail' => "{$uncategorizedCount} ".($uncategorizedCount === 1 ? 'transaction' : 'transactions'),
                'action_type' => 'review_uncategorized',
            ];
        }

        // ── Side metrics ──
        $customerQuery = QuickBooksCustomer::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndTwoNameColumns($customerQuery, $token, 'qbo_id', 'display_name', 'company_name');

        $paymentQuery = QuickBooksPayment::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($paymentQuery, $token, 'customer_qbo_id', 'customer_name');

        $salesReceiptQuery = QuickBooksSalesReceipt::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($salesReceiptQuery, $token, 'customer_qbo_id', 'customer_name');

        [$mtd, ] = $this->profitAndLoss($token, $monthStart, $now);
        $activeCustomers = (clone $customerQuery)->where('active', true)->count();
        $cashFlowMtd = (float) (
            (clone $paymentQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            + (clone $salesReceiptQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            - $currentTxnTotal
        );
        $invoiceQuery = QuickBooksInvoice::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($invoiceQuery, $token, 'customer_qbo_id', 'customer_name');
        $openInvoiceCount = (clone $invoiceQuery)->where('balance', '>', 0)->where('status', '!=', 'Paid')->count();

        return response()->json([
            'restricted' => ! $seesWholeCompany,
            'sync_warning' => $syncWarning,
            'summary_cards' => [
                'total_expenses' => [
                    'value' => $totalExpenses,
                    'formatted' => $this->abbreviateCurrency($totalExpenses),
                    'change_percentage' => $changePct,
                    'subtext' => $changePct !== null
                        ? (($changePct >= 0 ? 'Up ' : 'Down ').abs($changePct).'%')
                        : 'No prior month data',
                ],
                'top_vendor' => [
                    'name' => $topVendorName,
                    'value' => $topVendor !== null ? (float) $topVendor : null,
                    'formatted' => $topVendor !== null ? $this->abbreviateCurrency((float) $topVendor) : null,
                ],
                'recurring_spend' => [
                    'value' => $recurringSpend,
                    'formatted' => $this->abbreviateCurrency($recurringSpend),
                    'subtext' => 'Monthly',
                ],
                'anomalies' => [
                    'count' => count($checks),
                    'formatted' => (string) count($checks),
                    'subtext' => 'Need review',
                ],
            ],
            'expense_categories' => $categories->values()->all(),
            'expense_ai_checks' => $checks,
            'suggested_questions' => [
                'What expenses increased the most?',
                'Which vendors did I pay the most?',
                'Are there unusual or duplicate expenses?',
                'What costs should I review immediately?',
            ],
            'side_metrics' => [
                'revenue_mtd' => $this->abbreviateCurrency($mtd['revenue'] ?? null),
                'active_customers' => $activeCustomers,
                'cash_flow' => $this->abbreviateCurrency($cashFlowMtd),
                'open_invoices' => $openInvoiceCount,
            ],
        ]);
    }

    /**
     * Total spend per vendor/entity name for a period, transactions and
     * bills combined.
     *
     * @return Collection<string, float>
     */
    private function vendorTotals(Builder $transactionQuery, ?Builder $billQuery, Carbon $start, Carbon $end): Collection
    {
        $totals = collect();

        (clone $transactionQuery)
            ->whereBetween('txn_date', [$start, $end])
            ->whereNotNull('entity_name')
            ->where('entity_name', '!=', '')
            ->selectRaw('entity_name, SUM(amount) as amt')
            ->groupBy('entity_name')
            ->get()
            ->each(function ($row) use ($totals) {
                $totals[$row->entity_name] = ($totals[$row->entity_name] ?? 0) + (float) $row->amt;
            });

        if ($billQuery) {
            (clone $billQuery)
                ->whereBetween('txn_date', [$start, $end])
                ->whereNotNull('vendor_name')
                ->where('vendor_name', '!=', '')
                ->selectRaw('vendor_name, SUM(total_amount) as amt')
                ->groupBy('vendor_name')
                ->get()
                ->each(function ($row) use ($totals) {
                    $totals[$row->vendor_name] = ($totals[$row->vendor_name] ?? 0) + (float) $row->amt;
                });
        }

        return $totals;
    }

    /**
     * Vendors billed in at least 2 of the last 3 calendar months, summed at
     * their most recent month's amount — a proxy for "recurring" without a
     * subscription flag anywhere in the schema.
     */
    private function recurringSpend(Builder $transactionQuery, ?Builder $billQuery, Carbon $now): float
    {
        $monthsBack = [0, 1, 2];
        $byVendorByMonth = [];

        foreach ($monthsBack as $offset) {
            $start = $now->copy()->subMonths($offset)->startOfMonth();
            $end = $offset === 0 ? $now : $start->copy()->endOfMonth();
            $totals = $this->vendorTotals($transactionQuery, $billQuery, $start, $end);

            foreach ($totals as $vendor => $amount) {
                $byVendorByMonth[$vendor][$offset] = $amount;
            }
        }

        $recurring = 0.0;
        foreach ($byVendorByMonth as $months) {
            if (count($months) >= 2) {
                $recurring += $months[0] ?? reset($months);
            }
        }

        return round($recurring, 2);
    }

    /**
     * @return Collection<int, array{category: string, amount: float, formatted: string|null, color: string, percentage: int}>
     */
    private function expenseCategories(Builder $transactionQuery, ?Builder $billQuery, Carbon $start, Carbon $end): Collection
    {
        $totals = array_fill_keys(array_merge(array_keys(self::CATEGORY_KEYWORDS), ['Other']), 0.0);

        (clone $transactionQuery)
            ->whereBetween('txn_date', [$start, $end])
            ->get(['account_name', 'amount'])
            ->each(function ($row) use (&$totals) {
                $totals[$this->categorize($row->account_name)] += (float) $row->amount;
            });

        if ($billQuery) {
            (clone $billQuery)
                ->whereBetween('txn_date', [$start, $end])
                ->get(['ap_account_name', 'total_amount'])
                ->each(function ($row) use (&$totals) {
                    $totals[$this->categorize($row->ap_account_name)] += (float) $row->total_amount;
                });
        }

        $grandTotal = array_sum($totals);

        return collect($totals)->map(function ($amount, $category) use ($grandTotal) {
            return [
                'category' => $category,
                'amount' => round($amount, 2),
                'formatted' => $this->abbreviateCurrency($amount),
                'color' => self::CATEGORY_COLORS[$category],
                'percentage' => $grandTotal > 0 ? (int) round($amount / $grandTotal * 100) : 0,
            ];
        })->values();
    }

    private function categoryTotal(Builder $transactionQuery, ?Builder $billQuery, string $category, Carbon $start, Carbon $end): float
    {
        return $this->expenseCategories($transactionQuery, $billQuery, $start, $end)
            ->firstWhere('category', $category)['amount'] ?? 0.0;
    }

    private function categorize(?string $accountName): string
    {
        $accountName = strtolower((string) $accountName);

        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($accountName, $keyword)) {
                    return $category;
                }
            }
        }

        return 'Other';
    }

    /**
     * A pair of bills from the same vendor, near-identical amount, raised
     * within 7 days of each other — the classic double-entry mistake.
     */
    private function findDuplicateBill(Builder $billQuery): ?string
    {
        $bills = (clone $billQuery)
            ->whereNotNull('vendor_name')
            ->where('vendor_name', '!=', '')
            ->orderBy('vendor_name')
            ->orderBy('txn_date')
            ->get(['vendor_name', 'total_amount', 'txn_date']);

        $byVendor = $bills->groupBy('vendor_name');

        foreach ($byVendor as $vendor => $vendorBills) {
            $list = $vendorBills->values();
            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    $amountClose = abs((float) $list[$i]->total_amount - (float) $list[$j]->total_amount) < 0.01;
                    $daysApart = Carbon::parse($list[$i]->txn_date)->diffInDays(Carbon::parse($list[$j]->txn_date));

                    if ($amountClose && $daysApart <= 7) {
                        return "{$vendor} - ".$this->abbreviateExactCurrency((float) $list[$i]->total_amount);
                    }
                }
            }
        }

        return null;
    }

    /**
     * A Software-category vendor billed roughly monthly whose next expected
     * charge (last bill date + ~30 days) falls within the next 14 days.
     */
    private function findUpcomingSubscription(Builder $transactionQuery, ?Builder $billQuery, Carbon $now): ?string
    {
        $candidates = collect();

        if ($billQuery) {
            (clone $billQuery)
                ->get(['vendor_name', 'ap_account_name', 'total_amount', 'txn_date'])
                ->each(function ($row) use ($candidates) {
                    if ($this->categorize($row->ap_account_name) === 'Software') {
                        $candidates->push($row);
                    }
                });
        }

        $byVendor = $candidates->filter(fn ($c) => $c->vendor_name)->groupBy('vendor_name');

        foreach ($byVendor as $rows) {
            $latest = $rows->sortByDesc('txn_date')->first();
            $nextExpected = Carbon::parse($latest->txn_date)->addDays(30);

            if ($nextExpected->between($now, $now->copy()->addDays(14))) {
                return 'Software - '.$this->abbreviateExactCurrency((float) $latest->total_amount);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function syncWarning(string $realmId): ?array
    {
        $progress = QuickBooksSyncState::progressFor($realmId);
        $watched = ['bills', 'transactions'];

        $incomplete = collect($progress['entities'])
            ->whereIn('entity', $watched)
            ->filter(fn (array $e) => in_array($e['status'], ['failed', 'pending', 'syncing'], true))
            ->pluck('label')
            ->values();

        if ($incomplete->isEmpty()) {
            return null;
        }

        return [
            'status' => $progress['status'],
            'incomplete_entities' => $incomplete,
        ];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function profitAndLoss(QuickBooksToken $token, Carbon $start, Carbon $end): array
    {
        $customers = QuickBooksClientScope::reportCustomersForToken($token);

        if ($customers === null) {
            return [null, 'client_access_pending'];
        }

        try {
            return [app(QuickBooksReports::class)->profitAndLoss($token, $start, $end, $customers), null];
        } catch (\Throwable $e) {
            return [null, 'quickbooks_report_unavailable'];
        }
    }

    private function abbreviateCurrency(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        if ($abs >= 1_000_000) {
            return $sign.'$'.round($abs / 1_000_000, 1).'M';
        }

        if ($abs >= 1_000) {
            return $sign.'$'.round($abs / 1_000, 1).'K';
        }

        return $sign.'$'.number_format($abs, 0);
    }

    private function abbreviateExactCurrency(float $value): string
    {
        return '$'.number_format($value, 0);
    }
}
