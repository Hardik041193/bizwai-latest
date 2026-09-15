<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;

class GetRevenue implements AiTool
{
    use UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_revenue';
    }

    public function description(): string
    {
        return "Get revenue for a period from QuickBooks' own Profit and Loss report, on the company's accounting basis, with the top 10 customers by income.";
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
            $topCustomers = array_slice($reports->incomeByCustomer($token, $range['start'], $range['end'], $customers), 0, 10);

            return [
                'period_label' => $range['label'],
                'start_date' => $range['start']->toDateString(),
                'end_date' => $range['end']->toDateString(),
                'accounting_basis' => $pnl['basis'],
                'limited_to_your_clients' => $customers !== [],
                'revenue' => $pnl['revenue'],
                'income' => $pnl['income'],
                'other_income' => $pnl['other_income'],
                // Income only: other income is not attributed to customers.
                'by_customer' => array_map(fn (array $customer) => [
                    'customer_name' => $customer['customer_name'],
                    'income' => $customer['income'],
                ], $topCustomers),
            ];
        });
    }
}
