<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;

class GetProfitAndLossTransactions implements AiTool
{
    use UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_profit_and_loss_transactions';
    }

    public function description(): string
    {
        return "The individual transactions behind a revenue or expense figure, from QuickBooks' own Profit and Loss Detail report: each invoice, sales receipt, bill, expense or journal entry line with its date, type, number, customer or vendor name, account and amount. Use it to show exactly which invoices or transactions make up revenue or expenses for a period, to explain how a figure was calculated, and to find unusually large items. Filter by section, account or name. Keep the period short (a month or a quarter) so the list stays readable.";
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
                'section' => ['type' => 'string', 'enum' => ['income', 'expenses'], 'description' => 'income: what makes up revenue. expenses: what makes up costs, including cost of goods sold. Omit for both.'],
                'account' => ['type' => 'string', 'description' => 'Only lines booked to an account whose name contains this text, e.g. "advertising".'],
                'name' => ['type' => 'string', 'description' => 'Only lines for a customer or vendor whose name contains this text.'],
                'sort' => ['type' => 'string', 'enum' => ['date', 'amount'], 'description' => 'date (default): oldest first. amount: largest first.'],
                'limit' => ['type' => 'integer', 'description' => 'Max lines to return (default 50, max 100).'],
            ],
            'required' => ['period'],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $range = DateRangeResolver::resolve($arguments['period'] ?? '', $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
        $limit = min(max((int) ($arguments['limit'] ?? 50), 1), 100);

        return $this->withReports($context, function (QuickBooksReports $reports, QuickBooksToken $token, array $customers) use ($range, $arguments, $limit) {
            $lines = collect($reports->profitAndLossTransactions($token, $range['start'], $range['end'], $customers));

            if (! empty($arguments['section'])) {
                $lines = $lines->where('section', $arguments['section']);
            }

            foreach (['account' => 'account', 'name' => 'name'] as $argument => $field) {
                if (! empty($arguments[$argument])) {
                    $lines = $lines->filter(fn (array $line) => stripos((string) $line[$field], $arguments[$argument]) !== false);
                }
            }

            $sorted = ($arguments['sort'] ?? 'date') === 'amount'
                ? $lines->sortByDesc(fn (array $line) => abs($line['amount']))
                : $lines->sortBy('date');

            return [
                'period_label' => $range['label'],
                'start_date' => $range['start']->toDateString(),
                'end_date' => $range['end']->toDateString(),
                'limited_to_your_clients' => $customers !== [],
                'transactions' => $sorted->take($limit)->values()->all(),
                // Cover every matching line, not only those listed.
                'total_count' => $lines->count(),
                'income_total' => round($lines->where('section', 'income')->sum('amount'), 2),
                'expenses_total' => round($lines->where('section', 'expenses')->sum('amount'), 2),
                'by_account' => $lines->groupBy('account')
                    ->map(fn ($group, $account) => ['account' => $account, 'section' => $group->first()['section'], 'lines' => $group->count(), 'amount' => round($group->sum('amount'), 2)])
                    ->sortByDesc(fn (array $row) => abs($row['amount']))
                    ->take(40)
                    ->values()
                    ->all(),
            ];
        });
    }
}
