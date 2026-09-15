<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;

class GetProfitAndLoss implements AiTool
{
    use UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_profit_and_loss';
    }

    public function description(): string
    {
        return "Get revenue, expenses and profit for a period from QuickBooks' own Profit and Loss report, on the company's accounting basis (accrual or cash), with the main sections of the report.";
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

        return $this->withReports($context, function (QuickBooksReports $reports, QuickBooksToken $token, array $customers) use ($range) {
            $pnl = $reports->profitAndLoss($token, $range['start'], $range['end'], $customers);

            return [
                'period_label' => $range['label'],
                'start_date' => $range['start']->toDateString(),
                'end_date' => $range['end']->toDateString(),
                'accounting_basis' => $pnl['basis'],
                'limited_to_your_clients' => $customers !== [],
                'has_activity' => $pnl['has_data'],
                'revenue' => $pnl['revenue'],
                'expenses' => $pnl['total_expenses'],
                'profit' => $pnl['net_income'],
                'sections' => [
                    'income' => $pnl['income'],
                    'other_income' => $pnl['other_income'],
                    'cost_of_goods_sold' => $pnl['cost_of_goods_sold'],
                    'gross_profit' => $pnl['gross_profit'],
                    'operating_expenses' => $pnl['expenses'],
                    'net_operating_income' => $pnl['net_operating_income'],
                    'other_expenses' => $pnl['other_expenses'],
                ],
            ];
        });
    }

    /**
     * Revenue, expenses and profit for a range. Shared with
     * CompareFinancialPeriods so the two tools cannot disagree.
     *
     * @param  array{start: \Carbon\Carbon, end: \Carbon\Carbon, label: string}  $range
     * @param  array<int, string>  $customers
     * @return array{revenue: float, expenses: float, profit: float, accounting_basis: ?string}
     */
    public static function figures(QuickBooksReports $reports, QuickBooksToken $token, array $range, array $customers): array
    {
        $pnl = $reports->profitAndLoss($token, $range['start'], $range['end'], $customers);

        return [
            'revenue' => $pnl['revenue'],
            'expenses' => $pnl['total_expenses'],
            'profit' => $pnl['net_income'],
            'accounting_basis' => $pnl['basis'],
        ];
    }
}
