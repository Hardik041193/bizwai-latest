<?php

namespace App\Http\Controllers;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksPayment;
use App\Models\QuickBooksSalesReceipt;
use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransaction;
use App\Services\QuickBooksReports;
use App\Support\QuickBooksClientScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Profitability Center: gross/net margin, a profit bridge from revenue
 * to net profit, and which customers actually make money, for the
 * authenticated user's connected QuickBooks company.
 *
 * Every figure comes from QuickBooks' own Profit and Loss report
 * (App\Services\QuickBooksReports), filtered to the token's scope exactly as
 * QuickBooksClientScope::reportCustomersForToken() does for the other
 * dashboards — QuickBooks itself drops lines with no matching customer when
 * a customer filter is applied, so a client-scoped user's report already
 * excludes company-wide payroll and overhead without any extra gating here.
 */
class ProfitabilityController extends Controller
{
    public function center(Request $request): JsonResponse
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

        $syncWarning = $this->syncWarning($realmId);

        [$current, $currentError] = $this->profitAndLoss($token, $monthStart, $now);
        [$prior, $priorError] = $this->profitAndLoss($token, $priorMonthStart, $priorMonthEnd);

        $revenue = $current['revenue'] ?? null;
        $cogs = $current['cost_of_goods_sold'] ?? null;
        $grossProfit = $current['gross_profit'] ?? null;
        $netProfit = $current['net_income'] ?? null;
        $expenseAccounts = $current['expense_accounts'] ?? [];

        $grossMargin = ($revenue !== null && $revenue > 0 && $grossProfit !== null)
            ? round($grossProfit / $revenue * 100, 1)
            : null;
        $netMargin = ($revenue !== null && $revenue > 0 && $netProfit !== null)
            ? round($netProfit / $revenue * 100, 1)
            : null;

        $priorRevenue = $prior['revenue'] ?? null;
        $priorNetMargin = ($priorRevenue !== null && $priorRevenue > 0 && isset($prior['net_income']))
            ? round($prior['net_income'] / $priorRevenue * 100, 1)
            : null;
        $marginChange = ($netMargin !== null && $priorNetMargin !== null)
            ? round($netMargin - $priorNetMargin, 1)
            : null;

        // Split non-COGS expenses into payroll and everything else, by
        // account name — QuickBooks has no separate "payroll" section of its
        // own, so this is the same heuristic the cash flow page uses.
        $payroll = 0.0;
        $operatingExpenseAccounts = [];
        foreach ($expenseAccounts as $account) {
            if (stripos($account['account'], 'payroll') !== false) {
                $payroll += $account['amount'];
            } else {
                $operatingExpenseAccounts[] = $account;
            }
        }
        $operatingExpenses = array_sum(array_column($operatingExpenseAccounts, 'amount'));

        // A failed report leaves $expenseAccounts empty, which would
        // otherwise read as "no payroll/overhead" ($0) rather than "unknown".
        if ($currentError !== null) {
            $payroll = null;
            $operatingExpenses = null;
        }

        // Break-even revenue = fixed costs ÷ gross margin ratio: the revenue
        // needed for gross profit alone to cover payroll and overhead.
        $fixedCosts = ($payroll !== null && $operatingExpenses !== null) ? $payroll + $operatingExpenses : null;
        $breakEvenRevenue = ($fixedCosts !== null && $grossMargin !== null && $grossMargin > 0)
            ? round($fixedCosts / ($grossMargin / 100), 2)
            : null;

        // ── Most profitable customers ──
        [$customers, $customersError] = $this->incomeByCustomer($token, $monthStart, $now);
        $topCustomers = $this->topProfitableCustomers($customers ?? []);

        // ── Side metrics (same formulas as the executive dashboard) ──
        $invoiceQuery = QuickBooksInvoice::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($invoiceQuery, $token, 'customer_qbo_id', 'customer_name');

        $customerQuery = QuickBooksCustomer::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndTwoNameColumns($customerQuery, $token, 'qbo_id', 'display_name', 'company_name');

        $paymentQuery = QuickBooksPayment::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($paymentQuery, $token, 'customer_qbo_id', 'customer_name');

        $salesReceiptQuery = QuickBooksSalesReceipt::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($salesReceiptQuery, $token, 'customer_qbo_id', 'customer_name');

        $transactionQuery = QuickBooksTransaction::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($transactionQuery, $token, 'customer_qbo_id', 'entity_name');

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
            'sync_warning' => $syncWarning,
            'summary_cards' => [
                'gross_profit' => $this->moneyCard($grossProfit, $grossMargin !== null ? "{$grossMargin}% margin" : $this->errorNote($currentError)),
                'net_profit' => $this->moneyCard($netProfit, $netMargin !== null ? "{$netMargin}% margin" : $this->errorNote($currentError)),
                'profit_change' => [
                    'percentage' => $marginChange,
                    'formatted' => $marginChange !== null ? sprintf('%+.1f%%', $marginChange) : null,
                    'subtext' => 'vs prior month',
                ],
                'break_even' => [
                    'value' => $breakEvenRevenue,
                    'formatted' => $this->abbreviateCurrency($breakEvenRevenue),
                    'subtext' => 'monthly revenue',
                ],
            ],
            'profit_bridge_waterfall' => [
                'stages' => $this->waterfallStages($revenue, $cogs, $payroll, $operatingExpenses, $netProfit),
            ],
            'most_profitable_customers' => $topCustomers,
            'suggested_questions' => [
                'Why did profit go down?',
                'Which jobs or services have low margin?',
                'What expenses hurt profit most?',
                'What price increase improves margin?',
            ],
            'side_metrics' => [
                'revenue_mtd' => $this->abbreviateCurrency($revenue),
                'active_customers' => $activeCustomers,
                'cash_flow' => $this->abbreviateCurrency($cashFlowMtd),
                'open_invoices' => $openInvoiceCount,
            ],
        ]);
    }

    /**
     * @return array<int, array{label: string, amount: float|null, display: string, type: string, color: string}>
     */
    private function waterfallStages(?float $revenue, ?float $cogs, ?float $payroll, ?float $operatingExpenses, ?float $netProfit): array
    {
        return [
            $this->stage('Revenue', $revenue, 'positive', '#2563eb'),
            $this->stage('COGS', $cogs !== null ? -$cogs : null, 'negative', '#f59e0b'),
            $this->stage('Payroll', $payroll !== null ? -$payroll : null, 'negative', '#ef4444'),
            $this->stage('Operating Exp.', $operatingExpenses !== null ? -$operatingExpenses : null, 'negative', '#8b5cf6'),
            $this->stage('Net Profit', $netProfit, 'total', '#10b981'),
        ];
    }

    private function stage(string $label, ?float $amount, string $type, string $color): array
    {
        return [
            'label' => $label,
            'amount' => $amount,
            'display' => $amount === null ? 'N/A' : $this->signedAbbreviation($amount),
            'type' => $type,
            'color' => $color,
        ];
    }

    /**
     * @return array<int, array{name: string, profit: string, margin_percentage: int}>
     */
    private function topProfitableCustomers(array $customers, int $limit = 5): array
    {
        usort($customers, fn ($a, $b) => $b['net_income'] <=> $a['net_income']);

        return array_map(fn ($c) => [
            'name' => $c['customer_name'] !== '' ? $c['customer_name'] : 'Unnamed',
            'profit' => $this->abbreviateCurrency($c['net_income']),
            'margin_percentage' => $c['income'] > 0 ? (int) round($c['net_income'] / $c['income'] * 100) : 0,
        ], array_slice($customers, 0, $limit));
    }

    /**
     * Flag the response when a section this page depends on has not finished
     * syncing — the same watermark check used across the other dashboards.
     *
     * @return array<string, mixed>|null
     */
    private function syncWarning(string $realmId): ?array
    {
        $progress = QuickBooksSyncState::progressFor($realmId);
        $watched = ['invoices', 'bills', 'sales_receipts', 'transactions'];

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
            Log::warning('QuickBooks report unavailable for the profitability center.', [
                'realm_id' => $token->realm_id,
                'error' => $e->getMessage(),
            ]);

            return [null, 'quickbooks_report_unavailable'];
        }
    }

    /**
     * @return array{0: array<int, array{customer_qbo_id: string, customer_name: string, income: float, net_income: float}>|null, 1: string|null}
     */
    private function incomeByCustomer(QuickBooksToken $token, Carbon $start, Carbon $end): array
    {
        $customers = QuickBooksClientScope::reportCustomersForToken($token);

        if ($customers === null) {
            return [null, 'client_access_pending'];
        }

        try {
            return [app(QuickBooksReports::class)->incomeByCustomer($token, $start, $end, $customers), null];
        } catch (QuickBooksReauthorizationRequired $e) {
            return [null, 'quickbooks_reconnect_required'];
        } catch (RuntimeException $e) {
            Log::warning('QuickBooks income-by-customer report unavailable for the profitability center.', [
                'realm_id' => $token->realm_id,
                'error' => $e->getMessage(),
            ]);

            return [null, 'quickbooks_report_unavailable'];
        }
    }

    private function errorNote(?string $error): ?string
    {
        return match ($error) {
            'client_access_pending' => 'Access is still being set up',
            'quickbooks_reconnect_required' => 'Reconnect QuickBooks to load these figures',
            'quickbooks_report_unavailable' => 'QuickBooks report unavailable',
            default => null,
        };
    }

    /**
     * @return array{value: float|null, formatted: string|null, subtext: string|null}
     */
    private function moneyCard(?float $value, ?string $subtext): array
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

    /**
     * A waterfall bar label: "+125K" / "-52K", no dollar sign.
     */
    private function signedAbbreviation(float $value): string
    {
        $sign = $value < 0 ? '-' : '+';
        $abs = abs($value);

        if ($abs >= 1_000_000) {
            return $sign.round($abs / 1_000_000, 1).'M';
        }

        if ($abs >= 1_000) {
            return $sign.round($abs / 1_000, 1).'K';
        }

        return $sign.number_format($abs, 0);
    }
}
