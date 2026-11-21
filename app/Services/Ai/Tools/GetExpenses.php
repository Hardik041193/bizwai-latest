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
        return "Get expenses for a period from QuickBooks' own Profit and Loss report, on the company's accounting basis, with every expense account (category) and its amount, largest first. To find what was spent on one category, such as marketing, advertising, office, rent, payroll or cost of goods sold, pass `account`: it searches account and sub-account names. If nothing matches, the result lists every account name so you can pick the closest one and say which account you used.";
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
                'account' => ['type' => 'string', 'description' => 'Optional search text matched against expense account and sub-account names, e.g. "advertising".'],
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

        $search = trim((string) ($arguments['account'] ?? ''));

        return $this->withReports($context, function (QuickBooksReports $reports, QuickBooksToken $token, array $customers) use ($range, $search) {
            $pnl = $reports->profitAndLoss($token, $range['start'], $range['end'], $customers);

            if ($search !== '') {
                return [
                    'period_label' => $range['label'],
                    'start_date' => $range['start']->toDateString(),
                    'end_date' => $range['end']->toDateString(),
                    'accounting_basis' => $pnl['basis'],
                    'limited_to_your_clients' => $customers !== [],
                    'total_expenses' => $pnl['total_expenses'],
                ] + $this->matchingAccounts($pnl['expense_account_details'], $search);
            }

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
                // Every account, not a top few: a small category the user asks
                // about by name must be in here to be found.
                'by_account' => $pnl['expense_accounts'],
            ];
        });
    }

    /**
     * Accounts whose name, or whose parent account's name, contains the search
     * text, with one total that never counts a sub-account twice.
     *
     * @param  array<int, array{account: string, parent: ?string, amount: float}>  $accounts
     * @return array<string, mixed>
     */
    private function matchingAccounts(array $accounts, string $search): array
    {
        $matches = fn (?string $name) => $name !== null && stripos($name, $search) !== false;

        $found = array_values(array_filter($accounts, fn (array $line) => $matches($line['account']) || $matches($line['parent'])));

        if ($found === []) {
            return [
                'search' => $search,
                'matching_accounts' => [],
                'no_match' => 'No expense account name contains this text. Choose the closest from all_account_names and search again, or tell the user which accounts exist.',
                'all_account_names' => array_values(array_unique(array_column($accounts, 'account'))),
            ];
        }

        // A sub-account's amount is already inside its parent's total, so it
        // only adds to the total when its parent is not itself a match.
        $names = array_column($found, 'account');
        $total = 0.0;

        foreach ($found as $line) {
            if ($line['parent'] === null || ! in_array($line['parent'], $names, true)) {
                $total += $line['amount'];
            }
        }

        return [
            'search' => $search,
            'matching_accounts' => $found,
            'matching_total' => round($total, 2),
        ];
    }
}
