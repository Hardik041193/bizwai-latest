<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Contracts\AiTool;

class CompareFinancialPeriods implements AiTool
{
    private const PERIOD_ENUM = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'];

    public function name(): string
    {
        return 'compare_financial_periods';
    }

    public function description(): string
    {
        return 'Compare revenue, expenses, and profit between two periods and return the percentage change.';
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

        $current = GetProfitAndLoss::compute($currentRange, $context);
        $previous = GetProfitAndLoss::compute($previousRange, $context);

        return [
            'current' => [
                'period_label' => $currentRange['label'],
                'start_date' => $currentRange['start']->toDateString(),
                'end_date' => $currentRange['end']->toDateString(),
                ...$current,
            ],
            'previous' => [
                'period_label' => $previousRange['label'],
                'start_date' => $previousRange['start']->toDateString(),
                'end_date' => $previousRange['end']->toDateString(),
                ...$previous,
            ],
            'change' => [
                'revenue_pct' => $this->percentChange($previous['revenue'], $current['revenue']),
                'expenses_pct' => $this->percentChange($previous['expenses'], $current['expenses']),
                'profit_pct' => $this->percentChange($previous['profit'], $current['profit']),
            ],
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
