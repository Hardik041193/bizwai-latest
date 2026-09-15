<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;

class CompareFinancialPeriods implements AiTool
{
    use UsesQuickBooksReports;

    private const PERIOD_ENUM = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'];

    public function name(): string
    {
        return 'compare_financial_periods';
    }

    public function description(): string
    {
        return "Compare revenue, expenses and profit between two periods, from QuickBooks' own Profit and Loss report on the company's accounting basis, and return the percentage change.";
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'current_period' => ['type' => 'string', 'enum' => self::PERIOD_ENUM],
                'previous_period' => ['type' => 'string', 'enum' => self::PERIOD_ENUM],
                'current_start_date' => ['type' => 'string', 'description' => 'Required when current_period is "custom". Format Y-m-d.'],
                'current_end_date' => ['type' => 'string', 'description' => 'Required when current_period is "custom". Format Y-m-d.'],
                'previous_start_date' => ['type' => 'string', 'description' => 'Required when previous_period is "custom". Format Y-m-d.'],
                'previous_end_date' => ['type' => 'string', 'description' => 'Required when previous_period is "custom". Format Y-m-d.'],
            ],
            'required' => ['current_period', 'previous_period'],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $currentRange = DateRangeResolver::resolve(
            $arguments['current_period'] ?? '',
            $arguments['current_start_date'] ?? null,
            $arguments['current_end_date'] ?? null
        );
        $previousRange = DateRangeResolver::resolve(
            $arguments['previous_period'] ?? '',
            $arguments['previous_start_date'] ?? null,
            $arguments['previous_end_date'] ?? null
        );

        return $this->withReports($context, function (QuickBooksReports $reports, QuickBooksToken $token, array $customers) use ($currentRange, $previousRange) {
            $current = GetProfitAndLoss::figures($reports, $token, $currentRange, $customers);
            $previous = GetProfitAndLoss::figures($reports, $token, $previousRange, $customers);

            return [
                'accounting_basis' => $current['accounting_basis'],
                'limited_to_your_clients' => $customers !== [],
                'current' => $this->period($currentRange, $current),
                'previous' => $this->period($previousRange, $previous),
                'change' => [
                    'revenue_pct' => $this->percentChange($previous['revenue'], $current['revenue']),
                    'expenses_pct' => $this->percentChange($previous['expenses'], $current['expenses']),
                    'profit_pct' => $this->percentChange($previous['profit'], $current['profit']),
                ],
            ];
        });
    }

    /**
     * @param  array{start: \Carbon\Carbon, end: \Carbon\Carbon, label: string}  $range
     * @param  array{revenue: float, expenses: float, profit: float}  $figures
     */
    private function period(array $range, array $figures): array
    {
        return [
            'period_label' => $range['label'],
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'revenue' => $figures['revenue'],
            'expenses' => $figures['expenses'],
            'profit' => $figures['profit'],
        ];
    }

    private function percentChange(float $from, float $to): ?float
    {
        if ($from == 0.0) {
            return null;
        }

        return round((($to - $from) / abs($from)) * 100, 2);
    }
}
