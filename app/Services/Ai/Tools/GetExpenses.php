<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;

class GetExpenses implements AiTool
{
    use UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_expenses';
    }

    public function description(): string
    {
        return "Get expenses for a period from QuickBooks' own Profit and Loss report, on the company's accounting basis, with the 10 largest expense accounts.";
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
                'expenses' => $pnl['total_expenses'],
                'cost_of_goods_sold' => $pnl['cost_of_goods_sold'],
                'operating_expenses' => $pnl['expenses'],
                'other_expenses' => $pnl['other_expenses'],
                'by_account' => array_slice($pnl['expense_accounts'], 0, 10),
            ];
        });
    }
}
