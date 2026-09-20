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
 * Customers and Collections: who owes what, how slowly they pay, and how
 * concentrated revenue is in a handful of accounts, for the authenticated
 * user's connected QuickBooks company.
 *
 * Revenue per customer comes from QuickBooks' own Profit and Loss report
 * (summarized by customer) — the same source of truth every other dashboard
 * uses — because it already carries the token's client scope. AR balances
 * and due dates have no report equivalent, so those come from
 * quickbooks_invoices directly, scoped through QuickBooksClientScope like
 * every other document query in this app.
 */
class CustomersCollectionsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $realmId = $token->realm_id;
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $monthStart = $now->copy()->startOfMonth();
        $priorMonthStart = $monthStart->copy()->subMonth();
        $priorMonthEnd = $monthStart->copy()->subSecond();

        $syncWarning = $this->syncWarning($realmId);

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

        // ── Revenue by customer, from the P&L report (this month & prior) ──
        [$currentByCustomer, $currentError] = $this->incomeByCustomer($token, $monthStart, $now);
        [$mtd, $mtdError] = $this->profitAndLoss($token, $monthStart, $now);
        [$prior, ] = $this->profitAndLoss($token, $priorMonthStart, $priorMonthEnd);

        $revenueMtd = $mtd['revenue'] ?? null;

        // ── Open AR, by customer, from the documents themselves — the P&L
        // report has no notion of an outstanding balance or a due date ──
        $arRows = (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->whereNotNull('customer_qbo_id')
            ->selectRaw('customer_qbo_id, MAX(customer_name) as name, SUM(balance) as bal, MIN(due_date) as earliest_due')
            ->groupBy('customer_qbo_id')
            ->get()
            ->keyBy('customer_qbo_id');

        $displayNames = (clone $customerQuery)->pluck('display_name', 'qbo_id');

        $customers = [];
        foreach ($currentByCustomer ?? [] as $row) {
            $qboId = $row['customer_qbo_id'];
            $arRow = $arRows->get($qboId);
            $openAr = $arRow ? (float) $arRow->bal : 0.0;
            $earliestDue = ($arRow && $arRow->earliest_due) ? Carbon::parse($arRow->earliest_due) : null;
            $daysOverdue = ($earliestDue && $earliestDue->lt($today)) ? (int) $earliestDue->diffInDays($today) : 0;

            $customers[] = [
                'name' => $displayNames[$qboId] ?? ($row['customer_name'] !== '' ? $row['customer_name'] : 'Unnamed'),
                'revenue' => (float) $row['income'],
                'open_ar' => $openAr,
                'days_overdue' => $daysOverdue,
                'risk' => $this->riskLevel($openAr, $daysOverdue),
            ];
        }

        usort($customers, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        $priorityList = array_map(fn ($c) => [
            'customer' => $c['name'],
            'revenue' => $this->abbreviateCurrency($c['revenue']),
            'open_ar' => $this->abbreviateCurrency($c['open_ar']),
            'risk' => $c['risk'],
        ], array_slice($customers, 0, 5));

        // ── Concentration: top 1 / top 2-5 / everyone else, by revenue ──
        $totalRevenue = array_sum(array_column($customers, 'revenue'));
        $top1 = $customers[0]['revenue'] ?? 0.0;
        $top2to5 = array_sum(array_column(array_slice($customers, 1, 4), 'revenue'));
        $concentration = $totalRevenue > 0 ? [
            'top_1_percentage' => (int) round($top1 / $totalRevenue * 100),
            'top_2_to_5_percentage' => (int) round($top2to5 / $totalRevenue * 100),
            'other_percentage' => max(0, 100 - (int) round($top1 / $totalRevenue * 100) - (int) round($top2to5 / $totalRevenue * 100)),
        ] : ['top_1_percentage' => 0, 'top_2_to_5_percentage' => 0, 'other_percentage' => 0];

        $top5Share = ($totalRevenue > 0)
            ? round(array_sum(array_column(array_slice($customers, 0, 5), 'revenue')) / $totalRevenue * 100)
            : null;

        // ── Active customers, and how many are new to the mirror this month.
        // QuickBooks' own creation date is not synced, so "new" is a proxy:
        // the first time this row was written to our database ──
        $activeCustomers = (clone $customerQuery)->where('active', true)->count();
        $newThisMonth = (clone $customerQuery)->where('active', true)->where('created_at', '>=', $monthStart)->count();

        // ── Overdue AR and DSO ──
        $overdueAr = (float) (clone $invoiceQuery)->where('balance', '>', 0)->whereDate('due_date', '<', $today)->sum('balance');
        $totalOpenAr = (float) (clone $invoiceQuery)->where('balance', '>', 0)->sum('balance');

        $daysElapsedThisMonth = max(1, $now->day);
        $dso = ($revenueMtd !== null && $revenueMtd > 0)
            ? round($totalOpenAr / $revenueMtd * $daysElapsedThisMonth)
            : null;

        // Prior-period DSO has no historical AR snapshot to draw on, so it
        // approximates "open AR" as the balance still outstanding today on
        // invoices raised last month — a proxy, not last month's true AR.
        $priorOpenAr = (float) (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->whereBetween('txn_date', [$priorMonthStart, $priorMonthEnd])
            ->sum('balance');
        $priorRevenue = $prior['revenue'] ?? null;
        $priorDso = ($priorRevenue !== null && $priorRevenue > 0)
            ? round($priorOpenAr / $priorRevenue * $priorMonthStart->daysInMonth)
            : null;

        $dsoSubtext = 'vs prior month unavailable';
        if ($dso !== null && $priorDso !== null) {
            $diff = $dso - $priorDso;
            $dsoSubtext = match (true) {
                $diff > 0 => "{$diff} days slower",
                $diff < 0 => abs($diff).' days faster',
                default => 'Same as last month',
            };
        }

        // ── Side metrics (same formulas as the other dashboards) ──
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
                'active_customers' => [
                    'value' => $activeCustomers,
                    'change_text' => "Up {$newThisMonth} this month",
                ],
                'top_5_share' => [
                    'percentage' => $top5Share,
                    'formatted' => $top5Share !== null ? "{$top5Share}%" : null,
                    'subtext' => 'Concentration risk',
                ],
                'overdue_ar' => [
                    'value' => $overdueAr,
                    'formatted' => $this->abbreviateCurrency($overdueAr),
                    'subtext' => 'Needs collection',
                ],
                'avg_collection' => [
                    'value' => $dso,
                    'unit' => 'days',
                    'formatted' => $dso !== null ? "{$dso} days" : 'N/A',
                    'subtext' => $dsoSubtext,
                ],
            ],
            'customer_priority_list' => $priorityList,
            'customer_concentration_risk' => $concentration,
            'suggested_questions' => [
                'Who owes me money right now?',
                'Which customers pay slowly?',
                'Which customers are most profitable?',
                'Which customers stopped buying?',
                'Am I too dependent on a few customers?',
                'Which customers should I call today?',
            ],
            'side_metrics' => [
                'revenue_mtd' => $this->abbreviateCurrency($revenueMtd),
                'active_customers' => $activeCustomers,
                'cash_flow' => $this->abbreviateCurrency($cashFlowMtd),
                'open_invoices' => $openInvoiceCount,
            ],
        ]);
    }

    private function riskLevel(float $openAr, int $daysOverdue): string
    {
        if ($openAr <= 0 || $daysOverdue <= 0) {
            return 'Low';
        }

        if ($daysOverdue > 30 || $openAr > 5000) {
            return 'High';
        }

        return 'Med';
    }

    /**
     * Flag the response when a section this page depends on has not
     * finished syncing.
     *
     * @return array<string, mixed>|null
     */
    private function syncWarning(string $realmId): ?array
    {
        $progress = QuickBooksSyncState::progressFor($realmId);
        $watched = ['customers', 'invoices', 'payments'];

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
            Log::warning('QuickBooks report unavailable for customers and collections.', [
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
            Log::warning('QuickBooks income-by-customer report unavailable for customers and collections.', [
                'realm_id' => $token->realm_id,
                'error' => $e->getMessage(),
            ]);

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
}
