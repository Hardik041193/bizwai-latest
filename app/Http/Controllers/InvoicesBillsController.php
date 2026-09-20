<?php

namespace App\Http\Controllers;

use App\Exceptions\QuickBooksReauthorizationRequired;
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
 * Invoices and Bills: open AR, its aging, who to collect from, and what AP
 * comes due next, for the authenticated user's connected QuickBooks company.
 *
 * AR figures always come straight from quickbooks_invoices.balance, never
 * quickbooks_customers.balance — the two disagree once a sync is partial,
 * and invoices are the side every other AR figure in this app already
 * trusts. Bills carry no customer (vendor_qbo_id points at vendors, which
 * are not mirrored), so every bill-derived figure is gated behind
 * QuickBooksClientScope::tokenSeesWholeCompany(), same as the cash flow
 * and executive dashboards.
 */
class InvoicesBillsController extends Controller
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
        $seesWholeCompany = QuickBooksClientScope::tokenSeesWholeCompany($token);

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

        $billQuery = $seesWholeCompany ? QuickBooksBill::where('realm_id', $realmId) : null;

        // ── Open invoices / overdue ──
        $openInvoiceCount = (clone $invoiceQuery)->where('balance', '>', 0)->where('status', '!=', 'Paid')->count();
        $totalUnpaid = (float) (clone $invoiceQuery)->where('balance', '>', 0)->sum('balance');

        $overdueCount = (clone $invoiceQuery)->where('balance', '>', 0)->whereDate('due_date', '<', $today)->count();
        $totalPastDue = (float) (clone $invoiceQuery)->where('balance', '>', 0)->whereDate('due_date', '<', $today)->sum('balance');

        // ── Bills due / net AR-AP — company-wide, admin/all-clients only ──
        $billsDue14 = $billQuery
            ? (float) (clone $billQuery)->where('balance', '>', 0)->whereBetween('due_date', [$today, $today->copy()->addDays(14)])->sum('balance')
            : null;
        $totalOpenAp = $billQuery ? (float) (clone $billQuery)->where('balance', '>', 0)->sum('balance') : null;
        $netArAp = $totalOpenAp !== null ? $totalUnpaid - $totalOpenAp : null;

        // ── AR aging ──
        $arAging = $this->arAging($invoiceQuery, $today, $totalUnpaid);

        // ── Collection priority: top 3 overdue customers ──
        $collectionPriority = $this->collectionPriority($invoiceQuery, $token, $today);

        // ── Bills due timeline (cumulative) — company-wide only ──
        $billsTimeline = $this->billsTimeline($billQuery, $today);

        // ── Side metrics ──
        [$mtd, ] = $this->profitAndLoss($token, $monthStart, $now);
        $activeCustomers = (clone $customerQuery)->where('active', true)->count();
        $cashFlowMtd = (float) (
            (clone $paymentQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            + (clone $salesReceiptQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            - (clone $transactionQuery)
                ->whereIn('txn_type', ['Purchase', 'Expense'])
                ->whereBetween('txn_date', [$monthStart, $now])
                ->sum('amount')
        );

        return response()->json([
            'restricted_bills' => ! $seesWholeCompany,
            'sync_warning' => $syncWarning,
            'summary_cards' => [
                'open_invoices' => [
                    'count' => $openInvoiceCount,
                    'total_unpaid' => $totalUnpaid,
                    'formatted' => (string) $openInvoiceCount,
                    'subtext' => $this->abbreviateCurrency($totalUnpaid).' unpaid',
                ],
                'overdue' => [
                    'count' => $overdueCount,
                    'total_past_due' => $totalPastDue,
                    'formatted' => (string) $overdueCount,
                    'subtext' => $this->abbreviateCurrency($totalPastDue).' past due',
                ],
                'bills_due' => [
                    'value' => $billsDue14,
                    'formatted' => $this->abbreviateCurrency($billsDue14),
                    'subtext' => 'Next 14 days',
                ],
                'net_ar_ap' => [
                    'value' => $netArAp,
                    'formatted' => $netArAp !== null ? $this->signedAbbreviation($netArAp) : null,
                    'subtext' => 'AR less bills',
                ],
            ],
            'ar_aging' => $arAging,
            'collection_priority' => $collectionPriority,
            'bills_due_timeline' => $billsTimeline,
            'side_metrics' => [
                'revenue_mtd' => $this->abbreviateCurrency($mtd['revenue'] ?? null),
                'active_customers' => $activeCustomers,
                'cash_flow' => $this->abbreviateCurrency($cashFlowMtd),
                'open_invoices' => $openInvoiceCount,
            ],
        ]);
    }

    /**
     * @return array<int, array{bracket: string, amount: float, formatted: string|null, color: string, percentage: int}>
     */
    private function arAging(Builder $invoiceQuery, Carbon $today, float $totalOpenAr): array
    {
        $rows = (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->selectRaw(
                "CASE
                    WHEN due_date >= ? THEN 'current'
                    WHEN due_date >= ? THEN 'b1_30'
                    WHEN due_date >= ? THEN 'b31_60'
                    WHEN due_date >= ? THEN 'b61_90'
                    ELSE 'b90_plus'
                END as bracket,
                SUM(balance) as amt",
                [
                    $today->toDateString(),
                    $today->copy()->subDays(30)->toDateString(),
                    $today->copy()->subDays(60)->toDateString(),
                    $today->copy()->subDays(90)->toDateString(),
                ]
            )
            ->groupBy('bracket')
            ->pluck('amt', 'bracket');

        $buckets = [
            ['key' => 'current', 'bracket' => 'Current', 'color' => '#10b981'],
            ['key' => 'b1_30', 'bracket' => '1-30', 'color' => '#2563eb'],
            ['key' => 'b31_60', 'bracket' => '31-60', 'color' => '#f59e0b'],
            ['key' => 'b61_90', 'bracket' => '61-90', 'color' => '#ef4444'],
            ['key' => 'b90_plus', 'bracket' => '90+', 'color' => '#dc2626'],
        ];

        return array_map(function ($bucket) use ($rows, $totalOpenAr) {
            $amount = (float) ($rows[$bucket['key']] ?? 0);

            return [
                'bracket' => $bucket['bracket'],
                'amount' => $amount,
                'formatted' => $this->abbreviateCurrency($amount),
                'color' => $bucket['color'],
                'percentage' => $totalOpenAr > 0 ? (int) round($amount / $totalOpenAr * 100) : 0,
            ];
        }, $buckets);
    }

    /**
     * Top 3 customers by how overdue they are, then by dollar amount.
     *
     * @return array<int, array{rank: int, customer: string, amount: string|null, detail: string}>
     */
    private function collectionPriority(Builder $invoiceQuery, QuickBooksToken $token, Carbon $today): array
    {
        // Group by qbo_id where it exists, falling back to the customer name
        // for rows synced before that column existed — the same fallback
        // QuickBooksClientScope itself uses.
        $rows = (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<', $today)
            ->selectRaw("COALESCE(customer_qbo_id, CONCAT('name:', customer_name)) as key_id, customer_qbo_id, MAX(customer_name) as name, SUM(balance) as amt, MIN(due_date) as earliest_due")
            ->groupBy('key_id', 'customer_qbo_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $realmId = $token->realm_id;
        $displayNames = QuickBooksCustomer::where('realm_id', $realmId)
            ->whereIn('qbo_id', $rows->pluck('customer_qbo_id')->filter())
            ->pluck('display_name', 'qbo_id');

        $customers = $rows->map(function ($row) use ($today, $displayNames) {
            $daysOverdue = (int) Carbon::parse($row->earliest_due)->diffInDays($today);

            return [
                'name' => ($row->customer_qbo_id ? $displayNames[$row->customer_qbo_id] ?? null : null) ?? ($row->name ?: 'Unnamed'),
                'amount' => (float) $row->amt,
                'days_overdue' => $daysOverdue,
            ];
        });

        // Rank by days overdue first, dollar amount as the tiebreaker,
        // exactly as the spec orders the priority list.
        $sorted = $customers->sort(function ($a, $b) {
            return $b['days_overdue'] <=> $a['days_overdue'] ?: $b['amount'] <=> $a['amount'];
        })->values();

        return $sorted->take(3)->values()->map(function ($c, $index) {
            return [
                'rank' => $index + 1,
                'customer' => $c['name'],
                'amount' => $this->abbreviateExactCurrency($c['amount']),
                'detail' => $c['days_overdue'] >= 90 ? '90+ days' : "{$c['days_overdue']} days",
            ];
        })->all();
    }

    /**
     * Cumulative AP due through each horizon — company-wide only.
     *
     * @return array<string, array{label: string, amount: float|null, formatted: string|null}>
     */
    private function billsTimeline(?Builder $billQuery, Carbon $today): array
    {
        $horizons = [
            'today' => ['label' => 'Today', 'end' => $today],
            'seven_days' => ['label' => '7 Days', 'end' => $today->copy()->addDays(7)],
            'fourteen_days' => ['label' => '14 Days', 'end' => $today->copy()->addDays(14)],
            'thirty_days' => ['label' => '30 Days', 'end' => $today->copy()->addDays(30)],
        ];

        $out = [];
        foreach ($horizons as $key => $horizon) {
            $amount = $billQuery
                ? (float) (clone $billQuery)->where('balance', '>', 0)->whereDate('due_date', '<=', $horizon['end'])->sum('balance')
                : null;

            $out[$key] = [
                'label' => $horizon['label'],
                'amount' => $amount,
                'formatted' => $this->abbreviateCurrency($amount),
            ];
        }

        return $out;
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
        $watched = ['invoices', 'bills'];

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
            Log::warning('QuickBooks report unavailable for invoices and bills.', [
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

    private function signedAbbreviation(float $value): string
    {
        $sign = $value < 0 ? '-' : '+';

        return $sign.$this->abbreviateCurrency(abs($value));
    }

    /**
     * Exact, comma-grouped dollars for the collection priority list, which
     * shows real amounts like "$4,600" rather than "$4.6K".
     */
    private function abbreviateExactCurrency(float $value): string
    {
        return '$'.number_format($value, 0);
    }
}
