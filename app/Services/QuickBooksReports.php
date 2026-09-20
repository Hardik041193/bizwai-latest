<?php

namespace App\Services;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * QuickBooks' own Profit and Loss report, cached.
 *
 * Headline revenue, expense and profit figures come from here, not from summing
 * synced documents. QuickBooks books every line to an account (tax, cost of
 * goods, asset purchases, other expenses) and document totals cannot see that:
 * on the sandbox realm the closest document-based estimate still came out 6%
 * high on income and 12% high on expenses.
 *
 * No accounting method is sent, so QuickBooks applies the company's own report
 * basis preference and the figures match what its accountant sees. The basis
 * actually used is returned with every report.
 */
class QuickBooksReports
{
    /**
     * Upper bound on staleness when no sync runs to invalidate the cache.
     */
    private const CACHE_HOURS = 6;

    /**
     * Start date for an "everything to date" report, which QuickBooks needs
     * explicit dates for.
     */
    public const ALL_TIME_START = '1990-01-01';

    public function __construct(private readonly QuickBooksService $quickBooks) {}

    /**
     * Profit and loss for a period.
     *
     * `revenue`, `total_expenses` and `net_income` always satisfy
     * revenue - total_expenses = net_income, because they are built from
     * QuickBooks' own section totals: revenue is income plus other income, and
     * total_expenses is cost of goods sold plus expenses plus other expenses.
     *
     * @param  array<int, string>  $customerIds  empty for the whole company
     * @return array<string, mixed>
     */
    public function profitAndLoss(
        QuickBooksToken $token,
        CarbonInterface $start,
        CarbonInterface $end,
        array $customerIds = []
    ): array {
        $report = $this->fetch($token, $start, $end, $customerIds);
        $sections = $this->sections($report['Rows']['Row'] ?? []);
        $total = fn (string $group) => $this->amount($sections[$group]['Summary']['ColData'] ?? []);

        $income = $total('Income');
        $otherIncome = $total('OtherIncome');
        $costOfGoods = $total('COGS');
        $expenses = $total('Expenses');
        $otherExpenses = $total('OtherExpenses');

        $expenseAccounts = array_merge(
            $this->accountLines($sections['COGS'] ?? []),
            $this->accountLines($sections['Expenses'] ?? []),
            $this->accountLines($sections['OtherExpenses'] ?? []),
        );
        usort($expenseAccounts, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'basis' => $report['Header']['ReportBasis'] ?? null,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'has_data' => ! $this->isEmpty($report),
            'income' => $income,
            'cost_of_goods_sold' => $costOfGoods,
            'gross_profit' => $total('GrossProfit'),
            'expenses' => $expenses,
            'net_operating_income' => $total('NetOperatingIncome'),
            'other_income' => $otherIncome,
            'other_expenses' => $otherExpenses,
            'net_income' => $total('NetIncome'),
            'revenue' => round($income + $otherIncome, 2),
            'total_expenses' => round($costOfGoods + $expenses + $otherExpenses, 2),
            'expense_accounts' => $expenseAccounts,
        ];
    }

    /**
     * Income and net income per customer, largest income first.
     *
     * From the same report summarised by customer, so a breakdown always agrees
     * with the headline figures QuickBooks reports.
     *
     * @param  array<int, string>  $customerIds  empty for the whole company
     * @return array<int, array{customer_qbo_id: string, customer_name: string, income: float, net_income: float}>
     */
    public function incomeByCustomer(
        QuickBooksToken $token,
        CarbonInterface $start,
        CarbonInterface $end,
        array $customerIds = []
    ): array {
        $report = $this->fetch($token, $start, $end, $customerIds, ['summarize_column_by' => 'Customers']);
        $sections = $this->sections($report['Rows']['Row'] ?? []);
        $income = $sections['Income']['Summary']['ColData'] ?? [];
        $net = $sections['NetIncome']['Summary']['ColData'] ?? [];

        $customers = [];

        foreach ($report['Columns']['Column'] ?? [] as $index => $column) {
            $key = null;
            foreach ($column['MetaData'] ?? [] as $meta) {
                if (($meta['Name'] ?? null) === 'ColKey') {
                    $key = (string) $meta['Value'];
                }
            }

            // The first column labels the rows; totals and "not specified"
            // columns carry no customer id. Only real customer columns count.
            if ($index === 0 || $key === null || ! ctype_digit($key) || $key === '0') {
                continue;
            }

            $customers[] = [
                'customer_qbo_id' => $key,
                'customer_name' => $column['ColTitle'] ?? '',
                'income' => $this->number($income[$index]['value'] ?? ''),
                'net_income' => $this->number($net[$index]['value'] ?? ''),
            ];
        }

        usort($customers, fn ($a, $b) => $b['income'] <=> $a['income']);

        return $customers;
    }

    /**
     * Revenue and profit per period, from the same report the headline
     * figures come from, summarized by QuickBooks into calendar weeks,
     * months or years so the series always agrees with profitAndLoss() for
     * the same range.
     *
     * @param  string  $summarizeBy  QuickBooks' own bucket names: Week, Month or Year
     * @param  array<int, string>  $customerIds  empty for the whole company
     * @return array<int, array{label: string, revenue: float, profit: float}>
     */
    public function trend(
        QuickBooksToken $token,
        CarbonInterface $start,
        CarbonInterface $end,
        string $summarizeBy = 'Week',
        array $customerIds = []
    ): array {
        $report = $this->fetch($token, $start, $end, $customerIds, ['summarize_column_by' => $summarizeBy]);
        $sections = $this->sections($report['Rows']['Row'] ?? []);

        $income = $sections['Income']['Summary']['ColData'] ?? [];
        $otherIncome = $sections['OtherIncome']['Summary']['ColData'] ?? [];
        $costOfGoods = $sections['COGS']['Summary']['ColData'] ?? [];
        $expenses = $sections['Expenses']['Summary']['ColData'] ?? [];
        $otherExpenses = $sections['OtherExpenses']['Summary']['ColData'] ?? [];

        $weeks = [];

        foreach ($report['Columns']['Column'] ?? [] as $index => $column) {
            $title = $column['ColTitle'] ?? '';

            // The first column labels the rows; QuickBooks appends its own
            // "Total" column last. Neither is a week.
            if ($index === 0 || $title === '' || stripos($title, 'total') !== false) {
                continue;
            }

            $revenue = $this->number($income[$index]['value'] ?? '') + $this->number($otherIncome[$index]['value'] ?? '');
            $expense = $this->number($costOfGoods[$index]['value'] ?? '')
                + $this->number($expenses[$index]['value'] ?? '')
                + $this->number($otherExpenses[$index]['value'] ?? '');

            $weeks[] = [
                'label' => $title,
                'revenue' => round($revenue, 2),
                'profit' => round($revenue - $expense, 2),
            ];
        }

        return $weeks;
    }

    /**
     * @param  array<int, string>  $customerIds
     * @param  array<string, string>  $extra
     * @return array<string, mixed>
     */
    private function fetch(
        QuickBooksToken $token,
        CarbonInterface $start,
        CarbonInterface $end,
        array $customerIds,
        array $extra = []
    ): array {
        $customerIds = array_values(array_unique(array_map('strval', $customerIds)));
        sort($customerIds);

        $params = ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()] + $extra;

        if ($customerIds !== []) {
            $params['customer'] = implode(',', $customerIds);
        }

        // Keyed to the realm's latest completed sync step, so a sync that lands
        // new data invalidates every cached report for that company. A failed
        // request throws inside the callback and so is never cached.
        $key = 'quickbooks-report:'.sha1(json_encode([
            $token->realm_id,
            'ProfitAndLoss',
            $params,
            (string) QuickBooksSyncState::where('realm_id', $token->realm_id)->max('last_synced_at'),
        ]));

        return Cache::remember($key, now()->addHours(self::CACHE_HOURS), function () use ($token, $params) {
            try {
                $token = $this->quickBooks->refreshTokenIfNeeded($token);
                $accessToken = $token->access_token;
            } catch (DecryptException $e) {
                // access_token/refresh_token are stored with Laravel's
                // `encrypted` cast, keyed to APP_KEY. A MAC failure here means
                // the ciphertext cannot be read with the app's current key —
                // no retry or reauthorization inside the OAuth flow can fix
                // it, only reconnecting QuickBooks so a fresh token is issued
                // and re-encrypted. Surfacing it as "reconnect required" (the
                // same signal a rejected refresh token gives) tells the user
                // the accurate next step instead of "try again later".
                throw new QuickBooksReauthorizationRequired(
                    'QuickBooks connection is corrupted and must be reconnected: '.$e->getMessage()
                );
            }

            $response = Http::withToken($accessToken)
                ->accept('application/json')
                ->get("{$this->quickBooks->apiBaseUrl()}/v3/company/{$token->realm_id}/reports/ProfitAndLoss",
                    $params + ['minorversion' => '65']);

            if ($response->status() === 401) {
                throw new QuickBooksReauthorizationRequired(QuickBooksService::REJECTED_CONNECTION_MESSAGE);
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    "QuickBooks API error ({$response->status()}): ".$response->body()
                );
            }

            return (array) $response->json();
        });
    }

    /**
     * Every section carrying a group, at any depth, keyed by group.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function sections(array $rows): array
    {
        $sections = [];

        foreach ($rows as $row) {
            if (isset($row['group'])) {
                $sections[$row['group']] = $row;
            }

            if (isset($row['Rows']['Row'])) {
                $sections += $this->sections($row['Rows']['Row']);
            }
        }

        return $sections;
    }

    /**
     * The top-level accounts in a section: plain account lines, and parent
     * accounts, which QuickBooks sends as nested sections with their own total.
     *
     * @param  array<string, mixed>  $section
     * @return array<int, array{account: string, amount: float}>
     */
    private function accountLines(array $section): array
    {
        $lines = [];

        foreach ($section['Rows']['Row'] ?? [] as $row) {
            if (isset($row['ColData'])) {
                $lines[] = ['account' => $row['ColData'][0]['value'] ?? '', 'amount' => $this->amount($row['ColData'])];
            } elseif (isset($row['Summary']['ColData'])) {
                $lines[] = ['account' => $row['Header']['ColData'][0]['value'] ?? '', 'amount' => $this->amount($row['Summary']['ColData'])];
            }
        }

        return $lines;
    }

    /**
     * The amount in a row: its last cell.
     *
     * @param  array<int, array<string, mixed>>  $cells
     */
    private function amount(array $cells): float
    {
        return $this->number($cells === [] ? '' : (end($cells)['value'] ?? ''));
    }

    /**
     * QuickBooks sends amounts as strings, and an empty string for no amount.
     */
    private function number(mixed $value): float
    {
        return round((float) str_replace(',', '', (string) $value), 2);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function isEmpty(array $report): bool
    {
        foreach ($report['Header']['Option'] ?? [] as $option) {
            if (($option['Name'] ?? null) === 'NoReportData') {
                return ($option['Value'] ?? null) === 'true';
            }
        }

        return false;
    }
}
