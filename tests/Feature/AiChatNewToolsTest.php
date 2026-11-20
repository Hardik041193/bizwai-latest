<?php

namespace Tests\Feature;

use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\GetAccountBalances;
use App\Services\Ai\Tools\GetAging;
use App\Services\Ai\Tools\GetBalanceSheet;
use App\Services\Ai\Tools\GetCashFlow;
use App\Services\Ai\Tools\GetFinancialTrend;
use App\Services\Ai\Tools\GetProfitAndLoss;
use App\Services\Ai\Tools\GetSyncStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chat tools for what the assistant could not answer before: the balance sheet,
 * cash flow, bank balances, aging, month-by-month figures and sync status.
 * Report bodies follow the shape the sandbox realm returns.
 */
class AiChatNewToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_NEW_TOOLS';

    private const AMY = [['qbo_id' => '1', 'name' => "Amy's Bird Sanctuary"]];

    /** @var array<int, string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 10:00:00');

        $row = fn (array $values) => $values + ['realm_id' => $this->realm, 'created_at' => now(), 'updated_at' => now()];

        DB::table('quickbooks_accounts')->insert([
            $row(['qbo_id' => '35', 'name' => 'Checking', 'account_type' => 'Bank', 'classification' => 'Asset', 'current_balance' => 1201, 'active' => true]),
            $row(['qbo_id' => '36', 'name' => 'Savings', 'account_type' => 'Bank', 'classification' => 'Asset', 'current_balance' => 800, 'active' => true]),
            $row(['qbo_id' => '37', 'name' => 'Old Account', 'account_type' => 'Bank', 'classification' => 'Asset', 'current_balance' => 999, 'active' => false]),
            $row(['qbo_id' => '41', 'name' => 'Mastercard', 'account_type' => 'Credit Card', 'classification' => 'Liability', 'current_balance' => 157.72, 'active' => true]),
            // An expense account carries no running balance and is left out.
            $row(['qbo_id' => '7', 'name' => 'Advertising', 'account_type' => 'Expense', 'classification' => 'Expense', 'current_balance' => 0, 'active' => true]),
        ]);

        DB::table('quickbooks_invoices')->insert([
            $row(['qbo_id' => '1', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'due_date' => '2026-12-05', 'total_amount' => 100, 'balance' => 100, 'status' => 'Open']),
            $row(['qbo_id' => '2', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'due_date' => '2026-11-10', 'total_amount' => 50, 'balance' => 50, 'status' => 'Open']),
            $row(['qbo_id' => '3', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'due_date' => '2026-10-05', 'total_amount' => 400, 'balance' => 400, 'status' => 'Overdue']),
            $row(['qbo_id' => '4', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'due_date' => '2026-06-01', 'total_amount' => 900, 'balance' => 900, 'status' => 'Overdue']),
            $row(['qbo_id' => '5', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'due_date' => '2026-06-01', 'total_amount' => 70, 'balance' => 0, 'status' => 'Paid']),
        ]);

        DB::table('quickbooks_bills')->insert([
            $row(['qbo_id' => '10', 'vendor_name' => 'Norton Lumber', 'due_date' => '2026-08-31', 'total_amount' => 205, 'balance' => 205, 'status' => 'Open']),
        ]);

        foreach (State::ENTITIES as $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 3);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<int, array{qbo_id: string, name: string}>|null  $clients  [] all clients; null not yet resolved
     */
    private function context(string $role = 'admin', ?array $clients = []): QuickBooksAiContext
    {
        $user = User::factory()->create(['role' => $role]);

        QuickBooksToken::create([
            'user_id' => $user->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
            'selected_clients' => $clients,
            'client_selected_at' => $clients === null ? null : now(),
        ]);

        return QuickBooksAiContext::forUser($user->fresh());
    }

    private function fake(array $body): void
    {
        Http::fake(function (Request $request) use ($body) {
            $this->requests[] = urldecode($request->url());

            return Http::response($body);
        });
    }

    /**
     * @param  array<int, string>  $values  one per money column
     */
    private function cells(string $label, array $values): array
    {
        return array_merge([['value' => $label]], array_map(fn (string $v) => ['value' => $v], $values));
    }

    private function section(string $group, string $label, array $totals, array $rows = []): array
    {
        return [
            'type' => 'Section', 'group' => $group,
            'Header' => ['ColData' => $this->cells($label, array_fill(0, count($totals), ''))],
            'Rows' => ['Row' => $rows],
            'Summary' => ['ColData' => $this->cells("Total {$label}", $totals)],
        ];
    }

    private function line(string $label, array $values, ?string $group = null): array
    {
        return array_filter(['type' => 'Data', 'group' => $group, 'ColData' => $this->cells($label, $values)]);
    }

    private function columns(array $titles): array
    {
        return ['Column' => array_merge(
            [['ColTitle' => '', 'ColType' => 'Account']],
            array_map(fn (string $title) => ['ColTitle' => $title, 'ColType' => 'Money'], $titles),
        )];
    }

    /**
     * The sandbox realm's balance sheet. $equity lets a test make it positive.
     */
    private function balanceSheet(string $equity = '-7694.04', string $liabilitiesAndEquity = '23437.37'): array
    {
        return [
            'Header' => ['ReportName' => 'BalanceSheet', 'ReportBasis' => 'Accrual', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Columns' => $this->columns(['Total']),
            'Rows' => ['Row' => [
                $this->section('TotalAssets', 'ASSETS', ['23437.37'], [
                    $this->section('CurrentAssets', 'Current Assets', ['9942.37'], [
                        $this->section('BankAccounts', 'Bank Accounts', ['2001.00'], [$this->line('Checking', ['1201.00']), $this->line('Savings', ['800.00'])]),
                        $this->section('AR', 'Accounts Receivable', ['5282.60'], [$this->line('Accounts Receivable (A/R)', ['5282.60'])]),
                        $this->section('OtherCurrentAssets', 'Other Current Assets', ['2658.77'], [$this->line('Undeposited Funds', ['2658.77'])]),
                    ]),
                    $this->section('FixedAssets', 'Fixed Assets', ['13495.00'], [$this->line('Truck', ['13495.00'])]),
                ]),
                $this->section('TotalLiabilitiesAndEquity', 'LIABILITIES AND EQUITY', [$liabilitiesAndEquity], [
                    $this->section('Liabilities', 'Liabilities', ['31131.41'], [
                        $this->section('CurrentLiabilities', 'Current Liabilities', ['6131.41'], [
                            $this->section('AP', 'Accounts Payable', ['1602.67'], [$this->line('Accounts Payable (A/P)', ['1602.67'])]),
                            $this->section('CreditCards', 'Credit Cards', ['157.72'], [$this->line('Mastercard', ['157.72'])]),
                            $this->section('OtherCurrentLiabilities', 'Other Current Liabilities', ['4371.02'], [$this->line('Loan Payable', ['4371.02'])]),
                        ]),
                        $this->section('LongTermLiabilities', 'Long-Term Liabilities', ['25000.00'], [$this->line('Notes Payable', ['25000.00'])]),
                    ]),
                    $this->section('Equity', 'Equity', [$equity], [
                        $this->line('Retained Earnings', ['1642.46']),
                        $this->line('Net Income', ['1.00'], 'NetIncome'),
                    ]),
                ]),
            ]],
        ];
    }

    // Balance sheet

    public function test_the_balance_sheet_gives_totals_sections_and_ratios(): void
    {
        $this->fake($this->balanceSheet());

        $result = (new GetBalanceSheet)->handle([], $this->context());

        $this->assertStringContainsString('/reports/BalanceSheet', $this->requests[0]);
        $this->assertStringContainsString('end_date=2026-11-20', $this->requests[0]);
        $this->assertSame('2026-11-20', $result['as_of_date']);
        $this->assertSame(23437.37, $result['total_assets']);
        $this->assertSame(31131.41, $result['total_liabilities']);
        $this->assertSame(-7694.04, $result['total_equity']);
        $this->assertSame(2001.0, $result['assets']['bank_accounts']);
        $this->assertSame(5282.6, $result['assets']['accounts_receivable']);
        $this->assertSame(1602.67, $result['liabilities']['accounts_payable']);
        $this->assertSame(25000.0, $result['liabilities']['long_term_liabilities']);
        $this->assertSame(3810.96, $result['ratios']['working_capital']);
        $this->assertSame(1.62, $result['ratios']['current_ratio']);
        $this->assertContains(['account' => 'Checking', 'parent' => 'Bank Accounts', 'amount' => 1201.0], $result['asset_accounts']);
    }

    public function test_debt_to_equity_is_not_quoted_against_negative_equity(): void
    {
        $this->fake($this->balanceSheet());

        $ratios = (new GetBalanceSheet)->handle([], $this->context())['ratios'];

        $this->assertNull($ratios['debt_to_equity']);
        $this->assertStringContainsString('Not meaningful', $ratios['debt_to_equity_note']);
    }

    public function test_debt_to_equity_is_liabilities_over_equity_when_equity_is_positive(): void
    {
        $this->fake($this->balanceSheet('15565.71', '46697.12'));

        $this->assertSame(2.0, (new GetBalanceSheet)->handle([], $this->context())['ratios']['debt_to_equity']);
    }

    public function test_the_balance_sheet_history_gives_month_end_totals(): void
    {
        $months = ['Sep 2026', 'Oct 2026', 'Nov 2026'];

        Http::fake(function (Request $request) use ($months) {
            $this->requests[] = urldecode($request->url());

            if (! str_contains($request->url(), 'summarize_column_by=Month')) {
                return Http::response($this->balanceSheet());
            }

            return Http::response([
                'Header' => ['ReportName' => 'BalanceSheet', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
                'Columns' => $this->columns($months),
                'Rows' => ['Row' => [
                    $this->section('TotalAssets', 'ASSETS', ['100.00', '150.00', '120.00']),
                    $this->section('TotalLiabilitiesAndEquity', 'LIABILITIES AND EQUITY', ['100.00', '150.00', '120.00'], [
                        $this->section('Liabilities', 'Liabilities', ['40.00', '60.00', '50.00']),
                        $this->section('Equity', 'Equity', ['60.00', '90.00', '70.00']),
                    ]),
                ]],
            ]);
        });

        $result = (new GetBalanceSheet)->handle(['history_months' => 3], $this->context());

        $this->assertStringContainsString('start_date=2026-09-01', $this->requests[1]);
        $this->assertSame([
            ['label' => 'Sep 2026', 'total_assets' => 100.0, 'total_liabilities' => 40.0, 'total_equity' => 60.0],
            ['label' => 'Oct 2026', 'total_assets' => 150.0, 'total_liabilities' => 60.0, 'total_equity' => 90.0],
            ['label' => 'Nov 2026', 'total_assets' => 120.0, 'total_liabilities' => 50.0, 'total_equity' => 70.0],
        ], $result['month_end_history']);
    }

    // Cash flow

    private function cashFlow(array $titles, array $operating, array $investing, array $financing, array $change, array $ending): array
    {
        return [
            'Header' => ['ReportName' => 'CashFlow', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Columns' => $this->columns($titles),
            'Rows' => ['Row' => [
                $this->section('OperatingActivities', 'OPERATING ACTIVITIES', $operating, [$this->line('Net Income', $operating, 'NetIncome')]),
                $this->section('InvestingActivities', 'INVESTING ACTIVITIES', $investing),
                $this->section('FinancingActivities', 'FINANCING ACTIVITIES', $financing),
                ['type' => 'Section', 'group' => 'CashIncrease', 'Summary' => ['ColData' => $this->cells('Net cash increase for period', $change)]],
                ['type' => 'Section', 'group' => 'EndingCash', 'Summary' => ['ColData' => $this->cells('Cash at end of period', $ending)]],
            ]],
        ];
    }

    public function test_cash_flow_reads_each_activity_and_the_net_change(): void
    {
        $this->fake($this->cashFlow(['Total'], ['1500.00'], ['-13495.00'], ['25000.00'], ['13005.00'], ['2001.00']));

        $result = (new GetCashFlow)->handle(['period' => 'last_month'], $this->context());

        $this->assertStringContainsString('/reports/CashFlow', $this->requests[0]);
        $this->assertStringContainsString('start_date=2026-10-01', $this->requests[0]);
        $this->assertSame(1500.0, $result['net_cash_from_operating_activities']);
        $this->assertSame(-13495.0, $result['net_cash_from_investing_activities']);
        $this->assertSame(25000.0, $result['net_cash_from_financing_activities']);
        $this->assertSame(13005.0, $result['net_change_in_cash']);
        $this->assertSame(2001.0, $result['cash_at_end_of_period']);
        $this->assertArrayNotHasKey('months', $result);
    }

    public function test_cash_flow_by_month_names_the_month_cash_fell_most(): void
    {
        Http::fake(fn (Request $request) => Http::response(
            str_contains($request->url(), 'summarize_column_by=Month')
                ? $this->cashFlow(['Sep 2026', 'Oct 2026', 'Nov 2026', 'Total'], ['100.00', '-900.00', '50.00', '-750.00'], ['', '', '', ''], ['', '', '', ''], ['100.00', '-900.00', '50.00', '-750.00'], ['', '', '', ''])
                : $this->cashFlow(['Total'], ['-750.00'], [''], [''], ['-750.00'], ['2001.00'])
        ));

        $result = (new GetCashFlow)->handle(['last_months' => 3], $this->context());

        $this->assertSame(['Sep 2026', 'Oct 2026', 'Nov 2026'], array_column($result['months'], 'month'));
        $this->assertSame(['month' => 'Oct 2026', 'net_change_in_cash' => -900.0], $result['month_with_largest_cash_decrease']);
    }

    public function test_cash_flow_says_when_quickbooks_has_no_activity_for_the_period(): void
    {
        // As the sandbox realm returns it: no money column at all.
        $this->fake([
            'Header' => ['ReportName' => 'CashFlow', 'Option' => [['Name' => 'NoReportData', 'Value' => 'true']]],
            'Columns' => ['Column' => [['ColTitle' => '', 'ColType' => 'Account']]],
            'Rows' => ['Row' => [['type' => 'Section', 'group' => 'CashIncrease', 'Summary' => ['ColData' => [['value' => 'Net cash increase for period']]]]]],
        ]);

        $result = (new GetCashFlow)->handle(['period' => 'last_year'], $this->context());

        $this->assertFalse($result['has_activity']);
        $this->assertArrayNotHasKey('net_change_in_cash', $result);
    }

    // Monthly trend and accounting basis

    private function profitAndLoss(array $titles, array $income, array $expenses, string $basis = 'Accrual'): array
    {
        $net = array_map(fn ($i, $e) => number_format((float) $i - (float) $e, 2, '.', ''), $income, $expenses);

        return [
            'Header' => ['ReportName' => 'ProfitAndLoss', 'ReportBasis' => $basis, 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Columns' => $this->columns($titles),
            'Rows' => ['Row' => [
                $this->section('Income', 'Income', $income),
                $this->section('Expenses', 'Expenses', $expenses),
                ['type' => 'Section', 'group' => 'NetIncome', 'Summary' => ['ColData' => $this->cells('Net Income', $net)]],
            ]],
        ];
    }

    public function test_the_trend_gives_every_month_in_one_lookup_with_the_best_and_worst(): void
    {
        Http::fake(function (Request $request) {
            $this->requests[] = urldecode($request->url());

            return Http::response(str_contains($request->url(), 'summarize_column_by=Month')
                ? $this->profitAndLoss(['Sep 2026', 'Oct 2026', 'Nov 2026', 'Total'], ['1000.00', '4000.00', '2000.00', '7000.00'], ['500.00', '4500.00', '100.00', '5100.00'])
                : $this->profitAndLoss(['Total'], ['7000.00'], ['5100.00']));
        });

        $result = (new GetFinancialTrend)->handle(['last_months' => 3], $this->context());

        $this->assertStringContainsString('start_date=2026-09-01', $this->requests[0]);
        $this->assertStringContainsString('end_date=2026-11-20', $this->requests[0]);
        $this->assertSame([
            ['month' => 'Sep 2026', 'revenue' => 1000.0, 'expenses' => 500.0, 'profit' => 500.0],
            ['month' => 'Oct 2026', 'revenue' => 4000.0, 'expenses' => 4500.0, 'profit' => -500.0],
            ['month' => 'Nov 2026', 'revenue' => 2000.0, 'expenses' => 100.0, 'profit' => 1900.0],
        ], $result['months']);
        $this->assertSame(['month' => 'Oct 2026', 'revenue' => 4000.0], $result['highest_revenue_month']);
        $this->assertSame(['month' => 'Oct 2026', 'expenses' => 4500.0], $result['highest_expense_month']);
        $this->assertSame(['month' => 'Oct 2026', 'profit' => -500.0], $result['lowest_profit_month']);
        $this->assertSame(['revenue' => 7000.0, 'expenses' => 5100.0, 'profit' => 1900.0], $result['totals']);
    }

    public function test_a_trend_over_a_year_in_progress_stops_at_today(): void
    {
        $this->fake($this->profitAndLoss(['Total'], ['0.00'], ['0.00']));

        (new GetFinancialTrend)->handle(['period' => 'this_year'], $this->context());

        $this->assertStringContainsString('start_date=2026-01-01', $this->requests[0]);
        $this->assertStringContainsString('end_date=2026-11-20', $this->requests[0]);
    }

    public function test_profit_and_loss_can_be_asked_for_on_a_cash_basis(): void
    {
        $this->fake($this->profitAndLoss(['Total'], ['5080.27'], ['6984.39'], 'Cash'));

        $result = (new GetProfitAndLoss)->handle(['period' => 'last_year', 'accounting_method' => 'Cash'], $this->context());

        $this->assertStringContainsString('accounting_method=Cash', $this->requests[0]);
        $this->assertSame('Cash', $result['accounting_basis']);
        $this->assertSame(5080.27, $result['revenue']);
    }

    public function test_revenue_and_expenses_honour_the_basis_asked_for(): void
    {
        $this->fake($this->profitAndLoss(['Total'], ['5080.27'], ['6984.39'], 'Cash'));

        $revenue = (new \App\Services\Ai\Tools\GetRevenue)->handle(['period' => 'last_year', 'accounting_method' => 'Cash'], $this->context());
        $expenses = (new \App\Services\Ai\Tools\GetExpenses)->handle(['period' => 'last_year', 'accounting_method' => 'Cash'], $this->context());

        $this->assertSame('Cash', $revenue['accounting_basis']);
        $this->assertSame('Cash', $expenses['accounting_basis']);

        foreach ($this->requests as $request) {
            $this->assertStringContainsString('accounting_method=Cash', $request);
        }
    }

    public function test_a_named_period_wins_when_last_months_is_sent_with_it(): void
    {
        $this->fake($this->profitAndLoss(['Total'], ['0.00'], ['0.00']));

        (new GetFinancialTrend)->handle(['last_months' => 12, 'period' => 'this_year'], $this->context());

        $this->assertStringContainsString('start_date=2026-01-01', $this->requests[0]);
    }

    public function test_no_accounting_method_is_sent_unless_one_is_asked_for(): void
    {
        $this->fake($this->profitAndLoss(['Total'], ['10.00'], ['5.00']));

        (new GetProfitAndLoss)->handle(['period' => 'last_year', 'accounting_method' => 'Tax'], $this->context());

        $this->assertStringNotContainsString('accounting_method', $this->requests[0]);
    }

    // Bank balances, aging, sync status

    public function test_the_cash_balance_is_what_active_bank_accounts_hold(): void
    {
        $result = (new GetAccountBalances)->handle(['account_type' => 'Bank'], $this->context());

        $this->assertSame(['Checking', 'Savings'], array_column($result['accounts'], 'name'));
        $this->assertSame(2001.0, $result['cash_in_bank_accounts']);
        $this->assertSame(2001.0, $result['total_balance']);
    }

    public function test_account_balances_list_balance_sheet_accounts_largest_first_without_a_mixed_total(): void
    {
        $result = (new GetAccountBalances)->handle([], $this->context());

        $this->assertSame(['Checking', 'Savings', 'Mastercard'], array_column($result['accounts'], 'name'));
        $this->assertNull($result['total_balance']);
        $this->assertSame(2001.0, $result['cash_in_bank_accounts']);
    }

    public function test_receivable_aging_buckets_what_customers_owe_by_days_overdue(): void
    {
        $result = (new GetAging)->handle(['type' => 'receivable'], $this->context());

        $this->assertSame([
            'not_yet_due' => 100.0,
            'overdue_1_30_days' => 50.0,
            'overdue_31_60_days' => 400.0,
            'overdue_61_90_days' => 0.0,
            'overdue_over_90_days' => 900.0,
            'total_overdue' => 1350.0,
            'overdue_more_than_30_days' => 1300.0,
            'overdue_more_than_60_days' => 900.0,
            'total_owed' => 1450.0,
        ], $result['totals']);
        $this->assertSame(['Cool Cars', "Amy's Bird Sanctuary"], array_column($result['by_customer'], 'customer_name'));
        $this->assertSame(1300.0, $result['by_customer'][0]['total_owed']);
    }

    public function test_receivable_aging_covers_only_a_scoped_users_clients(): void
    {
        $result = (new GetAging)->handle(['type' => 'receivable'], $this->context('user', self::AMY));

        $this->assertSame(["Amy's Bird Sanctuary"], array_column($result['by_customer'], 'customer_name'));
        $this->assertSame(150.0, $result['totals']['total_owed']);
    }

    public function test_payable_aging_buckets_what_is_owed_to_vendors(): void
    {
        $result = (new GetAging)->handle(['type' => 'payable'], $this->context());

        $this->assertSame(205.0, $result['totals']['overdue_61_90_days']);
        $this->assertSame(205.0, $result['totals']['overdue_more_than_60_days']);
        $this->assertSame(['Norton Lumber'], array_column($result['by_vendor'], 'vendor_name'));
    }

    public function test_sync_status_reports_each_import_and_names_what_failed(): void
    {
        $clean = (new GetSyncStatus)->handle([], $this->context());

        $this->assertTrue($clean['all_imports_finished_without_failure']);
        $this->assertSame([], $clean['failed']);
        $this->assertNotNull($clean['last_completed_at']);
        $this->assertCount(count(State::ENTITIES), $clean['steps']);

        State::markSyncing($this->realm, 'bills');
        State::markFailed($this->realm, 'bills', 'QuickBooks API error (500)');

        $failed = (new GetSyncStatus)->handle([], $this->context());

        $this->assertFalse($failed['all_imports_finished_without_failure']);
        $this->assertSame(['Bills'], $failed['failed']);
        // The raw error text is internal and stays out of the result.
        $this->assertStringNotContainsString('API error', json_encode($failed));
    }

    // Access

    public function test_company_level_tools_are_hidden_from_client_scoped_users(): void
    {
        Http::fake();

        $context = $this->context('user', self::AMY);

        foreach ([
            [new GetBalanceSheet, []],
            [new GetCashFlow, []],
            [new GetAccountBalances, []],
            [new GetAging, ['type' => 'payable']],
        ] as [$tool, $arguments]) {
            $this->assertSame('company_level_data', $tool->handle($arguments, $context)['error'], $tool->name());
        }

        Http::assertNothingSent();
    }

    public function test_company_level_tools_wait_for_an_unresolved_scope(): void
    {
        Http::fake();

        $context = $this->context('user', null);

        $this->assertSame('client_access_pending', (new GetBalanceSheet)->handle([], $context)['error']);
        $this->assertSame('client_access_pending', (new GetAccountBalances)->handle([], $context)['error']);

        Http::assertNothingSent();
    }

    public function test_the_new_tools_are_registered_for_the_assistant(): void
    {
        $names = array_column((new ToolRegistry)->schemas(), 'name');

        foreach (['get_balance_sheet', 'get_cash_flow', 'get_account_balances', 'get_aging', 'get_financial_trend', 'get_sync_status'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }
}
