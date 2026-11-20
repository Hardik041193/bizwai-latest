<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;
use Carbon\Carbon;

class GetCashFlow implements AiTool
{
    use RequiresWholeCompany, UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_cash_flow';
    }

    public function description(): string
    {
        return "QuickBooks' own Statement of Cash Flows for a period: net cash from operating, investing and financing activities, the net change in cash, and cash at the end of the period. Set by_month to get the cash movement for each month, for the cash flow statement over several months, the month with the highest outflow, or why cash went down. Company-level data, not available to users limited to specific clients. For money received from customers use get_payments; for the current bank balance use get_account_balances.";
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'last_months' => ['type' => 'integer', 'description' => 'How many months to cover, ending with the current month (1 to 24), e.g. 6. Use this or period.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'A named period instead of last_months. Defaults to this_month.',
                    'enum' => ['this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'by_month' => ['type' => 'boolean', 'description' => 'Also break the period down month by month.'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        if ($error = $this->wholeCompanyError($context, 'Cash flow figures')) {
            return $error;
        }

        // When both are sent, the named period is the more specific request.
        if (! empty($arguments['last_months']) && empty($arguments['period'])) {
            $months = min(max((int) $arguments['last_months'], 1), 24);
            $start = Carbon::now()->startOfMonth()->subMonthsNoOverflow($months - 1);
            $end = Carbon::now()->endOfDay();
        } else {
            $range = DateRangeResolver::resolve($arguments['period'] ?? 'this_month', $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $start = $range['start'];
            $end = $range['end']->min(Carbon::now()->endOfDay());
        }

        $byMonth = ! empty($arguments['by_month']) || (! empty($arguments['last_months']) && $start->diffInMonths($end) >= 1);

        return $this->withReports($context, function (QuickBooksReports $reports, QuickBooksToken $token) use ($start, $end, $byMonth) {
            $flow = $reports->cashFlow($token, $start, $end);

            if (! $flow['has_data']) {
                return [
                    'start_date' => $flow['start_date'],
                    'end_date' => $flow['end_date'],
                    'has_activity' => false,
                    'message' => 'QuickBooks reports no cash flow activity for this period.',
                ];
            }

            $result = [
                'start_date' => $flow['start_date'],
                'end_date' => $flow['end_date'],
                'has_activity' => true,
                'net_cash_from_operating_activities' => $flow['operating_activities'],
                'net_cash_from_investing_activities' => $flow['investing_activities'],
                'net_cash_from_financing_activities' => $flow['financing_activities'],
                'net_change_in_cash' => $flow['net_cash_change'],
                'cash_at_start_of_period' => $flow['cash_at_start'],
                'cash_at_end_of_period' => $flow['cash_at_end'],
            ];

            if ($byMonth) {
                $months = $reports->cashFlowByMonth($token, $start, $end);

                $result['months'] = array_map(fn (array $month) => [
                    'month' => $month['label'],
                    'net_cash_from_operating_activities' => $month['operating_activities'],
                    'net_cash_from_investing_activities' => $month['investing_activities'],
                    'net_cash_from_financing_activities' => $month['financing_activities'],
                    'net_change_in_cash' => $month['net_cash_change'],
                ], $months);

                $lowest = collect($months)->sortBy('net_cash_change')->first();
                $result['month_with_largest_cash_decrease'] = $lowest && $lowest['net_cash_change'] < 0
                    ? ['month' => $lowest['label'], 'net_change_in_cash' => $lowest['net_cash_change']]
                    : null;
            }

            return $result;
        });
    }
}
