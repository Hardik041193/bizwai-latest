<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;
use Carbon\Carbon;

class GetFinancialTrend implements AiTool
{
    use UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_financial_trend';
    }

    public function description(): string
    {
        return "Revenue, expenses and profit for each month of a period, from QuickBooks' own Profit and Loss report on the company's accounting basis, in one lookup. Use it for any month-by-month view: monthly revenue or expenses, the best or worst months, growth over time, and whether expenses are rising faster than revenue. Also returns the highest and lowest month for each figure.";
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'last_months' => ['type' => 'integer', 'description' => 'How many months to cover, ending with the current month (1 to 24), e.g. 6 for the last six months. Use this or period.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'A named period to break into months instead of last_months. Defaults to this_year.',
                    'enum' => ['this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        // When both are sent, the named period is the more specific request.
        if (! empty($arguments['last_months']) && empty($arguments['period'])) {
            $months = min(max((int) $arguments['last_months'], 1), 24);
            $start = Carbon::now()->startOfMonth()->subMonthsNoOverflow($months - 1);
            $end = Carbon::now()->endOfDay();
        } else {
            $range = DateRangeResolver::resolve($arguments['period'] ?? 'this_year', $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $start = $range['start'];
            // Months that have not happened yet would only add empty rows.
            $end = $range['end']->min(Carbon::now()->endOfDay());
        }

        return $this->withReports($context, function (QuickBooksReports $reports, QuickBooksToken $token, array $customers) use ($start, $end) {
            $months = $reports->trend($token, $start, $end, 'Month', $customers);
            $pnl = $reports->profitAndLoss($token, $start, $end, $customers);

            $extreme = fn (string $figure, bool $highest) => $months === [] ? null : collect($months)
                ->sortBy($figure, SORT_REGULAR, $highest)
                ->map(fn (array $month) => ['month' => $month['label'], $figure => $month[$figure]])
                ->first();

            return [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'accounting_basis' => $pnl['basis'],
                'limited_to_your_clients' => $customers !== [],
                'months' => array_map(fn (array $month) => [
                    'month' => $month['label'],
                    'revenue' => $month['revenue'],
                    'expenses' => $month['expenses'],
                    'profit' => $month['profit'],
                ], $months),
                'totals' => [
                    'revenue' => $pnl['revenue'],
                    'expenses' => $pnl['total_expenses'],
                    'profit' => $pnl['net_income'],
                ],
                'highest_revenue_month' => $extreme('revenue', true),
                'lowest_revenue_month' => $extreme('revenue', false),
                'highest_expense_month' => $extreme('expenses', true),
                'highest_profit_month' => $extreme('profit', true),
                'lowest_profit_month' => $extreme('profit', false),
            ];
        });
    }
}
