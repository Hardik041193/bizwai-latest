<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksTransaction;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetProfitAndLoss implements AiTool
{
    use ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_profit_and_loss';
    }

    public function description(): string
    {
        return 'Get revenue, expenses, and profit for a given period. Revenue is the sum of paid invoices; expenses is the sum of purchase transactions.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'The period to report on.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
            ],
            'required' => ['period'],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $range = DateRangeResolver::resolve(
            $arguments['period'] ?? '',
            $arguments['start_date'] ?? null,
            $arguments['end_date'] ?? null
        );

        $figures = self::compute($range, $context);

        return [
            'period_label' => $range['label'],
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'revenue' => $figures['revenue'],
            'expenses' => $figures['expenses'],
            'profit' => $figures['profit'],
        ];
    }

    /**
     * Shared revenue/expense computation reused by CompareFinancialPeriods so
     * the two tools never disagree on how a period's figures are derived.
     *
     * @param  array{start: \Carbon\Carbon, end: \Carbon\Carbon, label: string}  $range
     * @return array{revenue: float, expenses: float, profit: float}
     */
    public static function compute(array $range, QuickBooksAiContext $context): array
    {
        $self = new self;

        $invoiceQuery = QuickBooksInvoice::where('realm_id', $context->realmId)
            ->where('status', 'Paid')
            ->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        $self->applyClientScope($invoiceQuery, $context, 'customer_qbo_id', ['customer_name']);

        $txnQuery = QuickBooksTransaction::where('realm_id', $context->realmId)
            ->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        $self->applyClientScope($txnQuery, $context, 'customer_qbo_id', ['entity_name']);

        $revenue = (float) $invoiceQuery->sum('total_amount');
        $expenses = (float) $txnQuery->sum('amount');

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'profit' => $revenue - $expenses,
        ];
    }
}
