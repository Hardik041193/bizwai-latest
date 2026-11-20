<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksToken;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;
use Carbon\Carbon;
use InvalidArgumentException;

class GetBalanceSheet implements AiTool
{
    use RequiresWholeCompany, UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_balance_sheet';
    }

    public function description(): string
    {
        return "QuickBooks' own Balance Sheet as of a date: total assets, total liabilities and owner's equity, with current assets, bank accounts, accounts receivable, fixed assets, current liabilities, accounts payable, long-term liabilities, every account line, and the working capital, current ratio and debt-to-equity ratio already worked out. Set history_months to also get assets, liabilities and equity at the end of each of the last months, for changes over time. Company-level data, not available to users limited to specific clients.";
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'as_of_date' => ['type' => 'string', 'description' => 'The date to report as of, format Y-m-d. Defaults to today.'],
                'history_months' => ['type' => 'integer', 'description' => 'Also return month-end totals for this many months up to the as-of date (1 to 24), e.g. 6.'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        if ($error = $this->wholeCompanyError($context, 'Balance sheet figures')) {
            return $error;
        }

        $asOf = $this->asOfDate($arguments['as_of_date'] ?? null);
        $historyMonths = empty($arguments['history_months']) ? 0 : min(max((int) $arguments['history_months'], 1), 24);

        return $this->withReports($context, function (QuickBooksReports $reports, QuickBooksToken $token) use ($asOf, $historyMonths) {
            $sheet = $reports->balanceSheet($token, $asOf);

            $result = [
                'as_of_date' => $sheet['as_of'],
                'accounting_basis' => $sheet['basis'],
                'has_activity' => $sheet['has_data'],
                'total_assets' => $sheet['total_assets'],
                'total_liabilities' => $sheet['total_liabilities'],
                'total_equity' => $sheet['total_equity'],
                'assets' => [
                    'current_assets' => $sheet['current_assets'],
                    'bank_accounts' => $sheet['bank_accounts'],
                    'accounts_receivable' => $sheet['accounts_receivable'],
                    'other_current_assets' => $sheet['other_current_assets'],
                    'fixed_assets' => $sheet['fixed_assets'],
                    'other_assets' => $sheet['other_assets'],
                ],
                'liabilities' => [
                    'current_liabilities' => $sheet['current_liabilities'],
                    'accounts_payable' => $sheet['accounts_payable'],
                    'credit_cards' => $sheet['credit_cards'],
                    'other_current_liabilities' => $sheet['other_current_liabilities'],
                    'long_term_liabilities' => $sheet['long_term_liabilities'],
                ],
                'ratios' => [
                    'working_capital' => $sheet['working_capital'],
                    'current_ratio' => $sheet['current_ratio'],
                    'debt_to_equity' => $sheet['debt_to_equity'],
                    'debt_to_equity_note' => $sheet['debt_to_equity'] === null
                        ? 'Not meaningful: equity is zero or negative, so say that instead of quoting a ratio.'
                        : 'Total liabilities divided by total equity.',
                ],
                'asset_accounts' => $sheet['asset_accounts'],
                'liability_accounts' => $sheet['liability_accounts'],
                'equity_accounts' => $sheet['equity_accounts'],
            ];

            if ($historyMonths > 0) {
                $result['month_end_history'] = $reports->balanceSheetByMonth(
                    $token,
                    $asOf->copy()->startOfMonth()->subMonthsNoOverflow($historyMonths - 1),
                    $asOf
                );
            }

            return $result;
        });
    }

    private function asOfDate(?string $date): Carbon
    {
        if (empty($date)) {
            return Carbon::today();
        }

        $parsed = Carbon::createFromFormat('!Y-m-d', $date);

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('as_of_date must be a valid date in Y-m-d format.');
        }

        // A balance sheet as of a future date is today's.
        return $parsed->min(Carbon::today());
    }
}
