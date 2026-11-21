<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksBill;
use App\Models\QuickBooksBillPayment;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetBillPayments implements AiTool
{
    use ReportsDataFreshness, RequiresWholeCompany;

    public function name(): string
    {
        return 'get_bill_payments';
    }

    public function description(): string
    {
        return 'List payments the company made to vendors against their bills, optionally filtered by vendor and period, most recent first, with the bills each payment settled, how it was paid, the total paid and a breakdown per vendor. Use it for bill payments made in a period and for a vendor\'s payment history. Company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vendor' => ['type' => 'string', 'description' => 'Filter by vendor name (partial match).'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max payments to return (default 20, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        if ($error = $this->wholeCompanyError($context, 'Payments to vendors')) {
            return $error;
        }

        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);

        $query = QuickBooksBillPayment::where('realm_id', $context->realmId);

        if (! empty($arguments['vendor'])) {
            $query->where('vendor_name', 'like', '%'.$arguments['vendor'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        $payments = (clone $query)->orderByDesc('txn_date')->orderByDesc('id')->limit($limit)->get();

        $billNumbers = QuickBooksBill::where('realm_id', $context->realmId)
            ->whereIn('qbo_id', $payments->pluck('bill_qbo_ids')->flatten()->filter()->unique()->values()->all())
            ->pluck('doc_number', 'qbo_id');

        return $this->withDataFreshness([
            'bill_payments' => $payments->map(fn ($payment) => [
                'vendor_name' => $payment->vendor_name,
                'txn_date' => optional($payment->txn_date)->toDateString(),
                'total_amount' => (float) $payment->total_amount,
                'paid_by' => $payment->pay_type,
                'paid_from_account' => $payment->account_name,
                'reference' => $payment->doc_number,
                'bills_paid' => count($payment->bill_qbo_ids ?? []),
                'bill_numbers' => collect($payment->bill_qbo_ids ?? [])->map(fn ($id) => $billNumbers[$id] ?? null)->filter()->values()->all(),
            ])->all(),
            'total_count' => (clone $query)->count(),
            'total_paid' => round((float) (clone $query)->sum('total_amount'), 2),
            'by_vendor' => (clone $query)
                ->selectRaw('vendor_name, COUNT(*) as payment_count, SUM(total_amount) as amount')
                ->groupBy('vendor_name')
                ->orderByDesc('amount')
                ->limit(25)
                ->get()
                ->map(fn ($row) => [
                    'vendor_name' => $row->vendor_name,
                    'payments' => (int) $row->payment_count,
                    'total_paid' => round((float) $row->amount, 2),
                ])
                ->all(),
        ], $context, ['bill_payments', 'bills']);
    }
}
