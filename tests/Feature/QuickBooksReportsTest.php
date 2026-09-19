<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\QuickBooksReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * QuickBooks' Profit and Loss report: parsing, filtering and caching.
 *
 * Fixtures follow the structure of reports fetched from a live sandbox realm,
 * with its all-time accrual figures.
 */
class QuickBooksReportsTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_REPORTS';

    /** @var array<int, string> */
    private array $requests = [];

    private function token(): QuickBooksToken
    {
        return QuickBooksToken::create([
            'user_id' => User::factory()->create(['role' => 'admin'])->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
        ]);
    }

    private function reports(): QuickBooksReports
    {
        return app(QuickBooksReports::class);
    }

    private function cell(string $value, ?string $id = null): array
    {
        return array_filter(['value' => $value, 'id' => $id], fn ($v) => $v !== null);
    }

    private function section(string $group, string $total, array $rows = []): array
    {
        return [
            'type' => 'Section',
            'group' => $group,
            'Header' => ['ColData' => [$this->cell($group), $this->cell('')]],
            'Rows' => ['Row' => $rows],
            'Summary' => ['ColData' => [$this->cell("Total {$group}"), $this->cell($total)]],
        ];
    }

    private function line(string $account, string $amount, string $id): array
    {
        return ['type' => 'Data', 'ColData' => [$this->cell($account, $id), $this->cell($amount)]];
    }

    /**
     * The all-time accrual report of the sandbox realm.
     */
    private function profitAndLossBody(bool $empty = false): array
    {
        $blank = fn (string $value) => $empty ? '' : $value;

        return [
            'Header' => [
                'ReportName' => 'ProfitAndLoss', 'ReportBasis' => 'Accrual',
                'StartPeriod' => '2000-01-01', 'EndPeriod' => '2026-09-15', 'Currency' => 'USD',
                'Option' => [['Name' => 'AccountingStandard', 'Value' => 'GAAP'], ['Name' => 'NoReportData', 'Value' => $empty ? 'true' : 'false']],
            ],
            'Columns' => ['Column' => [['ColTitle' => '', 'ColType' => 'Account'], ['ColTitle' => 'Total', 'ColType' => 'Money']]],
            'Rows' => ['Row' => [
                $this->section('Income', $blank('10201.77'), $empty ? [] : [
                    $this->line('Design income', '2250.00', '82'),
                    $this->line('Services', '7951.77', '1'),
                ]),
                $this->section('COGS', $blank('405.00'), $empty ? [] : [$this->line('Cost of Goods Sold', '405.00', '80')]),
                ['type' => 'Section', 'group' => 'GrossProfit', 'Summary' => ['ColData' => [$this->cell('Gross Profit'), $this->cell($blank('9796.77'))]]],
                $this->section('Expenses', $blank('5237.31'), $empty ? [] : [
                    $this->line('Advertising', '74.86', '7'),
                    // A parent account arrives as a nested section with its own total.
                    [
                        'type' => 'Section',
                        'Header' => ['ColData' => [$this->cell('Legal & Professional Fees', '12'), $this->cell('')]],
                        'Rows' => ['Row' => [$this->line('Accounting', '640.00', '69'), $this->line('Lawyer', '530.00', '71')]],
                        'Summary' => ['ColData' => [$this->cell('Total Legal & Professional Fees'), $this->cell('1170.00')]],
                    ],
                    $this->line('Rent or Lease', '3992.45', '17'),
                ]),
                ['type' => 'Section', 'group' => 'NetOperatingIncome', 'Summary' => ['ColData' => [$this->cell('Net Operating Income'), $this->cell($blank('4559.46'))]]],
                $this->section('OtherExpenses', $blank('2916.00'), $empty ? [] : [$this->line('Depreciation', '2916.00', '95')]),
                ['type' => 'Section', 'group' => 'NetOtherIncome', 'Summary' => ['ColData' => [$this->cell('Net Other Income'), $this->cell($blank('-2916.00'))]]],
                ['type' => 'Section', 'group' => 'NetIncome', 'Summary' => ['ColData' => [$this->cell('Net Income'), $this->cell($blank('1643.46'))]]],
            ]],
        ];
    }

    private function byCustomerBody(): array
    {
        $column = fn (string $title, ?string $key) => array_filter([
            'ColTitle' => $title, 'ColType' => 'Money',
            'MetaData' => $key === null ? null : [['Name' => 'ColKey', 'Value' => $key]],
        ], fn ($v) => $v !== null);

        return [
            'Header' => ['ReportBasis' => 'Accrual', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            // Column keys as a live realm sends them: the label column, customer
            // ids, then "not_specified" and "total", neither of them a customer.
            'Columns' => ['Column' => [
                array_merge($column('', 'account'), ['ColType' => 'Account']),
                $column("Amy's Bird Sanctuary", '1'),
                $column("Bill's Windsurf Shop", '2'),
                $column('Cool Cars', '3'),
                $column('Not Specified', 'not_specified'),
                $column('TOTAL', 'total'),
            ]],
            'Rows' => ['Row' => [
                ['type' => 'Section', 'group' => 'Income', 'Summary' => ['ColData' => [
                    $this->cell('Total Income'), $this->cell('630.00'), $this->cell('260.00'), $this->cell('2194.00'), $this->cell('120.00'), $this->cell('3204.00'),
                ]]],
                ['type' => 'Section', 'group' => 'NetIncome', 'Summary' => ['ColData' => [
                    $this->cell('Net Income'), $this->cell('630.00'), $this->cell(''), $this->cell('1994.00'), $this->cell('-50.00'), $this->cell('2574.00'),
                ]]],
            ]],
        ];
    }

    private function fake(array $body, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($body, $status) {
            $this->requests[] = urldecode($request->url());

            return Http::response($body, $status);
        });
    }

    public function test_profit_and_loss_reads_every_total_and_the_basis_quickbooks_used(): void
    {
        $this->fake($this->profitAndLossBody());

        $pnl = $this->reports()->profitAndLoss($this->token(), Carbon::parse('2000-01-01'), Carbon::parse('2026-09-15'));

        $this->assertSame('Accrual', $pnl['basis']);
        $this->assertTrue($pnl['has_data']);
        $this->assertSame(10201.77, $pnl['income']);
        $this->assertSame(405.0, $pnl['cost_of_goods_sold']);
        $this->assertSame(9796.77, $pnl['gross_profit']);
        $this->assertSame(5237.31, $pnl['expenses']);
        $this->assertSame(2916.0, $pnl['other_expenses']);
        $this->assertSame(1643.46, $pnl['net_income']);

        $this->assertSame(10201.77, $pnl['revenue']);
        $this->assertSame(8558.31, $pnl['total_expenses']);
        // The identity every consumer relies on.
        $this->assertSame($pnl['net_income'], round($pnl['revenue'] - $pnl['total_expenses'], 2));
    }

    public function test_expense_accounts_combine_plain_lines_and_parent_accounts_largest_first(): void
    {
        $this->fake($this->profitAndLossBody());

        $accounts = $this->reports()->profitAndLoss($this->token(), Carbon::parse('2000-01-01'), Carbon::parse('2026-09-15'))['expense_accounts'];

        $this->assertSame([
            ['account' => 'Rent or Lease', 'amount' => 3992.45],
            ['account' => 'Depreciation', 'amount' => 2916.0],
            ['account' => 'Legal & Professional Fees', 'amount' => 1170.0],
            ['account' => 'Cost of Goods Sold', 'amount' => 405.0],
            ['account' => 'Advertising', 'amount' => 74.86],
        ], $accounts);
    }

    public function test_no_accounting_method_is_sent_so_the_company_basis_applies(): void
    {
        $this->fake($this->profitAndLossBody());

        $this->reports()->profitAndLoss($this->token(), Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));

        $this->assertStringNotContainsString('accounting_method', $this->requests[0]);
        $this->assertStringContainsString('start_date=2026-01-01', $this->requests[0]);
        $this->assertStringContainsString('end_date=2026-03-31', $this->requests[0]);
    }

    public function test_a_customer_filter_is_sent_as_one_sorted_comma_list(): void
    {
        $this->fake($this->profitAndLossBody());

        $this->reports()->profitAndLoss($this->token(), Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), ['2', '1', '2']);

        $this->assertStringContainsString('customer=1,2', $this->requests[0]);
    }

    public function test_an_empty_period_reports_no_data_and_zero_totals(): void
    {
        $this->fake($this->profitAndLossBody(empty: true));

        $pnl = $this->reports()->profitAndLoss($this->token(), Carbon::parse('2001-01-01'), Carbon::parse('2001-01-31'));

        $this->assertFalse($pnl['has_data']);
        $this->assertSame(0.0, $pnl['revenue']);
        $this->assertSame(0.0, $pnl['total_expenses']);
        $this->assertSame(0.0, $pnl['net_income']);
        $this->assertSame([], $pnl['expense_accounts']);
    }

    public function test_income_by_customer_reads_only_real_customer_columns(): void
    {
        $this->fake($this->byCustomerBody());

        $customers = $this->reports()->incomeByCustomer($this->token(), Carbon::parse('2000-01-01'), Carbon::parse('2026-09-15'));

        $this->assertStringContainsString('summarize_column_by=Customers', $this->requests[0]);
        $this->assertSame([
            ['customer_qbo_id' => '3', 'customer_name' => 'Cool Cars', 'income' => 2194.0, 'net_income' => 1994.0],
            ['customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'income' => 630.0, 'net_income' => 630.0],
            ['customer_qbo_id' => '2', 'customer_name' => "Bill's Windsurf Shop", 'income' => 260.0, 'net_income' => 0.0],
        ], $customers);
    }

    public function test_a_report_is_cached_until_the_company_next_syncs(): void
    {
        $this->fake($this->profitAndLossBody());
        $token = $this->token();
        $start = Carbon::parse('2026-01-01');
        $end = Carbon::parse('2026-03-31');

        $this->reports()->profitAndLoss($token, $start, $end);
        $this->reports()->profitAndLoss($token, $start, $end);
        Http::assertSentCount(1);

        // A different question is a different report.
        $this->reports()->profitAndLoss($token, $start, $end, ['1']);
        Http::assertSentCount(2);

        // New data landing invalidates it.
        $this->travel(1)->minutes();
        State::markSyncing($this->realm, 'invoices');
        State::markComplete($this->realm, 'invoices', 3);

        $this->reports()->profitAndLoss($token, $start, $end);
        Http::assertSentCount(3);
    }

    public function test_a_failed_report_is_not_cached(): void
    {
        $token = $this->token();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            return ++$calls === 1 ? Http::response('{"Fault":{}}', 500) : Http::response($this->profitAndLossBody());
        });

        try {
            $this->reports()->profitAndLoss($token, Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));
            $this->fail('A 500 should throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $pnl = $this->reports()->profitAndLoss($token, Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));

        $this->assertSame(1643.46, $pnl['net_income']);
        $this->assertSame(2, $calls);
    }

    public function test_a_rejected_connection_requires_reauthorization(): void
    {
        $this->fake(['Fault' => []], 401);

        $this->expectException(QuickBooksReauthorizationRequired::class);

        $this->reports()->profitAndLoss($this->token(), Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));
    }
}
