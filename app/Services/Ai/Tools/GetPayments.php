<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksPayment;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetPayments implements AiTool
{
    use ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_payments';
    }

    public function description(): string
    {
        return 'List payments received from customers, optionally filtered by customer and period, most recent first, with the invoices each payment settled and the total received.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer' => ['type' => 'string', 'description' => 'Filter by customer name (partial match).'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by payment date. Omit for all time.',
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

        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);

        $query = QuickBooksPayment::where('realm_id', $context->realmId);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['customer_name']);

        if (! empty($arguments['customer'])) {
            $query->where('customer_name', 'like', '%'.$arguments['customer'].'%');
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        $totalCount = (clone $query)->count();
        $totalReceived = (float) (clone $query)->sum('total_amount');
        $payments = (clone $query)->orderByDesc('txn_date')->limit($limit)->get();

        // Invoice numbers for what each payment settled, looked up through the
        // same client scope, so a payment can never reveal another client's
        // invoice even if QuickBooks linked one.
        $invoiceQuery = QuickBooksInvoice::where('realm_id', $context->realmId)
            ->whereIn('qbo_id', $payments->pluck('invoice_qbo_ids')->flatten()->filter()->unique()->values()->all());
        $this->applyClientScope($invoiceQuery, $context, 'customer_qbo_id', ['customer_name']);
        $invoiceNumbers = $invoiceQuery->pluck('doc_number', 'qbo_id');

        return $this->withDataFreshness([
            'payments' => $payments->map(fn ($payment) => [
                'customer_name' => $payment->customer_name,
                'txn_date' => optional($payment->txn_date)->toDateString(),
                'total_amount' => (float) $payment->total_amount,
                'unapplied_amount' => (float) $payment->unapplied_amount,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->payment_ref_number,
                'invoices_paid' => collect($payment->invoice_qbo_ids ?? [])
                    ->map(fn ($id) => $invoiceNumbers[$id] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
            ])->all(),
            'total_count' => $totalCount,
            'total_received' => $totalReceived,
        ], $context, ['payments', 'invoices']);
    }
}
