<?php

namespace App\Http\Controllers;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Models\QuickBooksAccount;
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
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Cash Flow Command Center: bank cash, a 30-day forecast, and the
 * AR/AP levers that move it, for the authenticated user's connected
 * QuickBooks company.
 *
 * Bank balances and bills carry no customer to scope by (see
 * App\Support\QuickBooksClientScope), so those sections are restricted to
 * a user who sees the whole company — an admin, or one tracking every
 * client. A client-scoped user still gets their own AR figures.
 */
class CashFlowController extends Controller
{
    private const FORECAST_DAYS = 30;

    private const TRAILING_DAYS = 60;

    public function commandCenter(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $realmId = $token->realm_id;
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $monthStart = $now->copy()->startOfMonth();
        $seesWholeCompany = QuickBooksClientScope::tokenSeesWholeCompany($token);

        $invoiceQuery = QuickBooksInvoice::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($invoiceQuery, $token, 'customer_qbo_id', 'customer_name');

        $paymentQuery = QuickBooksPayment::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($paymentQuery, $token, 'customer_qbo_id', 'customer_name');

        $salesReceiptQuery = QuickBooksSalesReceipt::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($salesReceiptQuery, $token, 'customer_qbo_id', 'customer_name');

        $transactionQuery = QuickBooksTransaction::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($transactionQuery, $token, 'customer_qbo_id', 'entity_name');

        $customerQuery = QuickBooksCustomer::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndTwoNameColumns($customerQuery, $token, 'qbo_id', 'display_name', 'company_name');

        // Bills point at a vendor, not a customer, and bank accounts are
        // company-wide — neither can be scoped to particular clients.
        $billQuery = $seesWholeCompany ? QuickBooksBill::where('realm_id', $realmId) : null;
        $accountQuery = $seesWholeCompany ? QuickBooksAccount::where('realm_id', $realmId) : null;

        $syncWarning = $this->syncWarning($realmId);

        // ── Money coming in (AR) — visible at any scope ──
        $openInvoices = (float) (clone $invoiceQuery)->where('balance', '>', 0)->sum('balance');
        $overdue = (float) (clone $invoiceQuery)->where('balance', '>', 0)->whereDate('due_date', '<', $today)->sum('balance');
        $expectedThisWeek = (float) (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->whereBetween('due_date', [$today, $today->copy()->addDays(7)])
            ->sum('balance');
        $overdueCustomers = (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<', $today)
            ->distinct()
            ->count('customer_name');

        // Payroll has no customer on it, so it only turns up for a scope
        // that is not filtered down to particular clients' transactions —
        // in practice the same whole-company transactions an admin sees.
        $payrollEstimate = (float) (clone $transactionQuery)
            ->whereIn('txn_type', ['Purchase', 'Expense'])
            ->whereDate('txn_date', '>=', $now->copy()->subDays(30))
            ->where(function (Builder $q) {
                $q->where('account_name', 'like', '%payroll%')
                    ->orWhere('description', 'like', '%payroll%');
            })
            ->sum('amount');

        if ($seesWholeCompany) {
            $forecast = $this->forecast($billQuery, $accountQuery, $invoiceQuery, $paymentQuery, $salesReceiptQuery, $transactionQuery, $today, $now);
        } else {
            $forecast = [
                'cash_today' => null,
                'thirty_day_ending' => null,
                'runway_weeks' => null,
                'minimum_safe_cash' => 0.0,
                'data_points' => [],
                'cash_risk' => [
                    'status' => 'Unknown',
                    'subtext' => 'Connect as admin or select all clients to see cash flow',
                ],
                'bills_due_14_days' => null,
                'bills_after_14_days_count' => 0,
                'vendor_payments' => null,
                'vendor_trend_pct' => null,
            ];
        }

        // ── Cash actions ──
        $actions = [[
            'type' => 'Collect',
            'message' => "Call {$overdueCustomers} overdue ".($overdueCustomers === 1 ? 'customer' : 'customers'),
            'action_payload' => 'invoices_overdue',
        ]];

        if ($forecast['bills_after_14_days_count'] > 0) {
            $actions[] = [
                'type' => 'Delay',
                'message' => 'Review bills due after 14 days',
                'action_payload' => 'bills_extendable',
            ];
        }

        if ($forecast['vendor_trend_pct'] !== null && $forecast['vendor_trend_pct'] > 5) {
            $actions[] = [
                'type' => 'Review',
                'message' => "Vendor payments up {$forecast['vendor_trend_pct']}%",
                'action_payload' => 'vendor_variance',
            ];
        }

        $actions[] = [
            'type' => 'Ask AI',
            'message' => 'What can I do this week?',
            'action_payload' => 'prompt_cash_strategy',
        ];

        // ── Side rail ──
        [$mtd, $mtdError] = $this->profitAndLoss($token, $monthStart, $now);
        $activeCustomers = (clone $customerQuery)->where('active', true)->count();
        $cashFlowMtd = (float) (
            (clone $paymentQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            + (clone $salesReceiptQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            - (clone $transactionQuery)
                ->whereIn('txn_type', ['Purchase', 'Expense'])
                ->whereBetween('txn_date', [$monthStart, $now])
                ->sum('amount')
        );
        $openInvoiceCount = (clone $invoiceQuery)->where('balance', '>', 0)->where('status', '!=', 'Paid')->count();

        return response()->json([
            'restricted' => ! $seesWholeCompany,
            'sync_warning' => $syncWarning,
            'top_cards' => [
                'cash_today' => $this->moneyCard($forecast['cash_today'], 'Available bank cash'),
                'thirty_day_ending' => $this->moneyCard($forecast['thirty_day_ending'], 'Projected cash'),
                'runway' => [
                    'value' => $forecast['runway_weeks'],
                    'unit' => 'weeks',
                    'formatted' => $forecast['runway_weeks'] !== null ? "{$forecast['runway_weeks']} weeks" : 'N/A',
                    'subtext' => 'At current burn',
                ],
                'cash_risk' => $forecast['cash_risk'],
            ],
            'cash_forecast_chart' => [
                'minimum_safe_cash' => $forecast['minimum_safe_cash'],
                'data_points' => $forecast['data_points'],
            ],
            'cash_actions' => $actions,
            'cash_drivers' => [
                'money_coming_in' => [
                    'open_invoices' => $openInvoices,
                    'overdue' => $overdue,
                    'expected_this_week' => $expectedThisWeek,
                ],
                'money_going_out' => [
                    'bills_due_14_days' => $forecast['bills_due_14_days'],
                    'payroll_estimate' => $payrollEstimate,
                    'vendor_payments' => $forecast['vendor_payments'],
                ],
                'questions_to_ask' => [
                    'Who should I collect from first?',
                    'Can I cover payroll?',
                    'What bills can wait?',
                ],
            ],
            'side_rail' => [
                'revenue_mtd' => $this->abbreviateCurrency($mtd['revenue'] ?? null),
                'active_customers' => $activeCustomers,
                'cash_flow' => $this->abbreviateCurrency($cashFlowMtd),
                'open_invoices' => $openInvoiceCount,
            ],
        ]);
    }

    /**
     * Every whole-company-only figure: bank cash, the 30-day forecast, the
     * burn rate behind runway and the minimum-safe-cash line, and the AP
     * side of the cash drivers.
     *
     * @return array<string, mixed>
     */
    private function forecast(
        Builder $billQuery,
        Builder $accountQuery,
        Builder $invoiceQuery,
        Builder $paymentQuery,
        Builder $salesReceiptQuery,
        Builder $transactionQuery,
        Carbon $today,
        Carbon $now
    ): array {
        $cashToday = (float) (clone $accountQuery)->where('account_type', 'Bank')->where('active', true)->sum('current_balance');

        $billsDue14 = (float) (clone $billQuery)
            ->where('balance', '>', 0)
            ->whereBetween('due_date', [$today, $today->copy()->addDays(14)])
            ->sum('balance');
        $billsAfter14Count = (clone $billQuery)
            ->where('balance', '>', 0)
            ->whereDate('due_date', '>', $today->copy()->addDays(14))
            ->count();
        $vendorPayments = (float) (clone $billQuery)->where('balance', '>', 0)->sum('balance');

        // ── Trailing burn rate, for runway and the minimum-safe-cash line ──
        $trailingStart = $now->copy()->subDays(self::TRAILING_DAYS);
        $trailingIn = (float) (clone $paymentQuery)->whereBetween('txn_date', [$trailingStart, $now])->sum('total_amount')
            + (float) (clone $salesReceiptQuery)->whereBetween('txn_date', [$trailingStart, $now])->sum('total_amount');
        $trailingOutOps = (float) (clone $transactionQuery)
            ->whereIn('txn_type', ['Purchase', 'Expense'])
            ->whereBetween('txn_date', [$trailingStart, $now])
            ->sum('amount');
        $trailingOutBills = (float) (clone $billQuery)->whereBetween('txn_date', [$trailingStart, $now])->sum('total_amount');
        $trailingOut = $trailingOutOps + $trailingOutBills;

        $weeklyBurn = ($trailingOut - $trailingIn) / (self::TRAILING_DAYS / 7);
        $minimumSafeCash = $weeklyBurn > 0 ? round($weeklyBurn * 2, 2) : 0.0;
        $runwayWeeks = $weeklyBurn > 0 ? round($cashToday / $weeklyBurn, 1) : null;

        // ── Vendor spend trend: trailing 30 days vs. the 30 before that ──
        $recent30Start = $now->copy()->subDays(30);
        $prior30Start = $recent30Start->copy()->subDays(30);
        $recentBills = (float) (clone $billQuery)->whereBetween('txn_date', [$recent30Start, $now])->sum('total_amount');
        $priorBills = (float) (clone $billQuery)->whereBetween('txn_date', [$prior30Start, $recent30Start])->sum('total_amount');
        $vendorTrendPct = $priorBills > 0 ? round((($recentBills - $priorBills) / $priorBills) * 100) : null;

        // ── 30-day daily forecast: a smooth recurring baseline plus the
        // lumpy invoice/bill due dates that actually land in the window ──
        $dailyRecurringIn = (float) (clone $salesReceiptQuery)->whereBetween('txn_date', [$trailingStart, $now])->sum('total_amount') / self::TRAILING_DAYS;
        $dailyRecurringOut = $trailingOutOps / self::TRAILING_DAYS;

        $forecastEnd = $today->copy()->addDays(self::FORECAST_DAYS);

        $invoicesByDay = (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->whereBetween('due_date', [$today, $forecastEnd])
            ->selectRaw('DATE(due_date) as d, SUM(balance) as amt')
            ->groupBy('d')
            ->pluck('amt', 'd');

        $billsByDay = (clone $billQuery)
            ->where('balance', '>', 0)
            ->whereBetween('due_date', [$today, $forecastEnd])
            ->selectRaw('DATE(due_date) as d, SUM(balance) as amt')
            ->groupBy('d')
            ->pluck('amt', 'd');

        $dataPoints = [];
        $riskDay = null;
        $balance = $cashToday;

        for ($day = 1; $day <= self::FORECAST_DAYS; $day++) {
            $date = $today->copy()->addDays($day)->toDateString();
            $balance += $dailyRecurringIn - $dailyRecurringOut;
            $balance += (float) ($invoicesByDay[$date] ?? 0);
            $balance -= (float) ($billsByDay[$date] ?? 0);

            $dataPoints[] = ['day' => $day, 'projected_balance' => round($balance, 2)];

            if ($riskDay === null && $balance < $minimumSafeCash) {
                $riskDay = $day;
            }
        }

        $cashRisk = $riskDay !== null
            ? ['status' => 'Watch', 'subtext' => "Low cash in {$riskDay} days"]
            : ['status' => 'Healthy', 'subtext' => 'Cash stays above minimum'];

        return [
            'cash_today' => $cashToday,
            'thirty_day_ending' => $dataPoints === [] ? $cashToday : end($dataPoints)['projected_balance'],
            'runway_weeks' => $runwayWeeks,
            'minimum_safe_cash' => $minimumSafeCash,
            'data_points' => $dataPoints,
            'cash_risk' => $cashRisk,
            'bills_due_14_days' => $billsDue14,
            'bills_after_14_days_count' => $billsAfter14Count,
            'vendor_payments' => $vendorPayments,
            'vendor_trend_pct' => $vendorTrendPct,
        ];
    }

    /**
     * Flag the entities this page depends on when the mirror behind them
     * is incomplete — a stale or partial sync must not read as ground truth.
     *
     * @return array<string, mixed>|null
     */
    private function syncWarning(string $realmId): ?array
    {
        $progress = QuickBooksSyncState::progressFor($realmId);
        $watched = ['invoices', 'bills', 'payments', 'accounts'];

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
        } catch (QuickBooksReauthorizationRequired $e) {
            return [null, 'quickbooks_reconnect_required'];
        } catch (RuntimeException $e) {
            Log::warning('QuickBooks report unavailable for the cash flow command center.', [
                'realm_id' => $token->realm_id,
                'error' => $e->getMessage(),
            ]);

            return [null, 'quickbooks_report_unavailable'];
        }
    }

    /**
     * @return array{value: float|null, formatted: string|null, subtext: string}
     */
    private function moneyCard(?float $value, string $subtext): array
    {
        return [
            'value' => $value,
            'formatted' => $this->abbreviateCurrency($value),
            'subtext' => $subtext,
        ];
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
}
