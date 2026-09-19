<?php

namespace Tests\Feature;

use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\CompareFinancialPeriods;
use App\Services\Ai\Tools\GetCompanySummary;
use App\Services\Ai\Tools\GetExpenses;
use App\Services\Ai\Tools\GetProfitAndLoss;
use App\Services\Ai\Tools\GetRevenue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Revenue, expense and profit figures in the AI tools and the dashboard summary,
 * taken from QuickBooks' own Profit and Loss report.
 */
class QuickBooksAiReportToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_AI_REPORTS';

    /** @var array<int, string> */
    private array $requests = [];

    /**
     * @param  array<int, array{qbo_id: string, name: string}>|null  $clients  [] all clients; null not yet resolved
     */
    private function user(string $role, ?array $clients = []): User
    {
        $user = User::factory()->create(['role' => $role]);

        QuickBooksToken::create([
            'user_id' => $user->id,
            'realm_id' => $this->realm,
            'company_name' => 'Bizwai 1',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
            'selected_clients' => $clients,
            'client_selected_at' => $clients === null ? null : now(),
        ]);

        return $user->fresh();
    }

    private function context(User $user): QuickBooksAiContext
    {
        return QuickBooksAiContext::forUser($user);
    }

    /**
     * A Profit and Loss report with the given section totals.
     *
     * @param  array<int, array{0: string, 1: float}>  $expenseLines  account, amount
     */
    private function pnl(float $income, float $cogs, float $expenses, float $otherExpenses, array $expenseLines = []): array
    {
        $money = fn (float $v) => number_format($v, 2, '.', '');
        $section = fn (string $group, float $total, array $rows = []) => [
            'type' => 'Section', 'group' => $group, 'Rows' => ['Row' => $rows],
            'Summary' => ['ColData' => [['value' => "Total {$group}"], ['value' => $money($total)]]],
        ];

        return [
            'Header' => ['ReportBasis' => 'Accrual', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Rows' => ['Row' => [
                $section('Income', $income),
                $section('COGS', $cogs),
                $section('Expenses', $expenses, array_map(fn ($line, $i) => [
                    'type' => 'Data', 'ColData' => [['value' => $line[0], 'id' => (string) ($i + 1)], ['value' => $money($line[1])]],
                ], $expenseLines, array_keys($expenseLines))),
                $section('OtherExpenses', $otherExpenses),
                ['type' => 'Section', 'group' => 'NetIncome', 'Summary' => ['ColData' => [
                    ['value' => 'Net Income'], ['value' => $money($income - $cogs - $expenses - $otherExpenses)],
                ]]],
            ]],
        ];
    }

    private function byCustomer(): array
    {
        $column = fn (string $title, string $key) => ['ColTitle' => $title, 'ColType' => 'Money', 'MetaData' => [['Name' => 'ColKey', 'Value' => $key]]];

        return [
            'Header' => ['ReportBasis' => 'Accrual', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Columns' => ['Column' => [
                $column('', 'account'), $column("Amy's Bird Sanctuary", '1'), $column("Bill's Windsurf Shop", '2'),
                $column('Cool Cars', '3'), $column('Not Specified', 'not_specified'), $column('TOTAL', 'total'),
            ]],
            'Rows' => ['Row' => [
                ['type' => 'Section', 'group' => 'Income', 'Summary' => ['ColData' => [
                    ['value' => 'Total Income'], ['value' => '630.00'], ['value' => '260.00'], ['value' => '2194.00'], ['value' => '120.00'], ['value' => '3204.00'],
                ]]],
            ]],
        ];
    }

    /**
     * @param  array<string, array>  $bodies  start_date => report body, '*' for anything else
     */
    private function fakeReports(array $bodies, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($bodies, $status) {
            $url = urldecode($request->url());
            $this->requests[] = $url;

            if ($status !== 200) {
                return Http::response('{"Fault":{}}', $status);
            }

            if (str_contains($url, 'summarize_column_by=Customers')) {
                return Http::response($this->byCustomer());
            }

            preg_match('/start_date=(\d{4}-\d{2}-\d{2})/', $url, $m);

            return Http::response($bodies[$m[1] ?? ''] ?? $bodies['*']);
        });
    }

    private function sandboxReport(): array
    {
        return $this->pnl(10201.77, 405.00, 5237.31, 2916.00);
    }

    public function test_profit_and_loss_comes_from_the_quickbooks_report_with_its_basis(): void
    {
        $this->fakeReports(['*' => $this->sandboxReport()]);

        $result = (new GetProfitAndLoss)->handle(['period' => 'this_year'], $this->context($this->user('admin')));

        $this->assertSame('Accrual', $result['accounting_basis']);
        $this->assertSame(10201.77, $result['revenue']);
        $this->assertSame(8558.31, $result['expenses']);
        $this->assertSame(1643.46, $result['profit']);
        $this->assertFalse($result['limited_to_your_clients']);
        $this->assertStringNotContainsString('customer=', $this->requests[0]);
    }

    public function test_a_client_scoped_user_gets_figures_filtered_to_their_clients(): void
    {
        $this->fakeReports(['*' => $this->sandboxReport()]);
        $user = $this->user('user', [['qbo_id' => '2', 'name' => "Bill's Windsurf Shop"], ['qbo_id' => '1', 'name' => "Amy's Bird Sanctuary"]]);

        $result = (new GetProfitAndLoss)->handle(['period' => 'this_year'], $this->context($user));

        $this->assertTrue($result['limited_to_your_clients']);
        $this->assertStringContainsString('customer=1,2', $this->requests[0]);
    }

    public function test_an_unresolved_scope_gets_no_figures_and_asks_quickbooks_nothing(): void
    {
        $this->fakeReports(['*' => $this->sandboxReport()]);

        $result = (new GetProfitAndLoss)->handle(['period' => 'this_year'], $this->context($this->user('user', null)));

        $this->assertSame('client_access_pending', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_rejected_connection_asks_the_user_to_reconnect(): void
    {
        $this->fakeReports(['*' => []], 401);

        $result = (new GetExpenses)->handle(['period' => 'this_year'], $this->context($this->user('admin')));

        $this->assertSame('quickbooks_reconnect_required', $result['error']);
    }

    public function test_an_unavailable_report_is_reported_not_thrown(): void
    {
        $this->fakeReports(['*' => []], 500);

        $result = (new GetRevenue)->handle(['period' => 'this_year'], $this->context($this->user('admin')));

        $this->assertSame('quickbooks_report_unavailable', $result['error']);
    }

    public function test_comparing_periods_takes_both_from_the_report(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $this->fakeReports([
            '2026-09-01' => $this->pnl(1000, 0, 400, 0),
            '2026-08-01' => $this->pnl(800, 0, 500, 0),
        ]);

        $result = (new CompareFinancialPeriods)->handle(
            ['current_period' => 'this_month', 'previous_period' => 'last_month'],
            $this->context($this->user('admin'))
        );

        $this->assertSame('Accrual', $result['accounting_basis']);
        $this->assertSame(['revenue' => 1000.0, 'expenses' => 400.0, 'profit' => 600.0],
            array_intersect_key($result['current'], array_flip(['revenue', 'expenses', 'profit'])));
        $this->assertSame(['revenue' => 800.0, 'expenses' => 500.0, 'profit' => 300.0],
            array_intersect_key($result['previous'], array_flip(['revenue', 'expenses', 'profit'])));
        $this->assertSame(['revenue_pct' => 25.0, 'expenses_pct' => -20.0, 'profit_pct' => 100.0], $result['change']);
    }

    public function test_revenue_breaks_down_by_customer_without_exposing_ids(): void
    {
        $this->fakeReports(['*' => $this->pnl(3204, 0, 0, 0)]);

        $result = (new GetRevenue)->handle(['period' => 'this_year'], $this->context($this->user('admin')));

        $this->assertSame(3204.0, $result['revenue']);
        $this->assertSame([
            ['customer_name' => 'Cool Cars', 'income' => 2194.0],
            ['customer_name' => "Amy's Bird Sanctuary", 'income' => 630.0],
            ['customer_name' => "Bill's Windsurf Shop", 'income' => 260.0],
        ], $result['by_customer']);
    }

    public function test_expenses_list_the_largest_accounts_from_the_report(): void
    {
        $this->fakeReports(['*' => $this->pnl(0, 0, 300, 0, [['Advertising', 100], ['Rent or Lease', 200]])]);

        $result = (new GetExpenses)->handle(['period' => 'this_year'], $this->context($this->user('admin')));

        $this->assertSame(300.0, $result['expenses']);
        $this->assertSame([
            ['account' => 'Rent or Lease', 'amount' => 200.0],
            ['account' => 'Advertising', 'amount' => 100.0],
        ], $result['by_account']);
    }

    private function invoices(): void
    {
        foreach ([['900', 'Paid', 0], ['901', 'Overdue', 75]] as [$id, $status, $balance]) {
            DB::table('quickbooks_invoices')->insert([
                'realm_id' => $this->realm, 'qbo_id' => $id, 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary",
                'total_amount' => 100, 'balance' => $balance, 'status' => $status,
                'txn_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_the_company_summary_takes_revenue_and_expenses_from_the_report(): void
    {
        $this->invoices();
        $this->fakeReports(['*' => $this->sandboxReport()]);

        $result = (new GetCompanySummary)->handle([], $this->context($this->user('admin')));

        $this->assertSame(10201.77, $result['total_revenue']);
        $this->assertSame(8558.31, $result['total_expenses']);
        $this->assertSame(1643.46, $result['net_income']);
        $this->assertSame('Accrual', $result['accounting_basis']);
        $this->assertSame(2, $result['total_invoices']);
        $this->assertSame(1, $result['overdue_invoices']);
        $this->assertStringContainsString('start_date=1990-01-01', $this->requests[0]);
    }

    public function test_the_company_summary_still_answers_when_the_report_fails(): void
    {
        $this->invoices();
        $this->fakeReports(['*' => []], 500);

        $result = (new GetCompanySummary)->handle([], $this->context($this->user('admin')));

        $this->assertNull($result['total_revenue']);
        $this->assertArrayHasKey('figures_error', $result);
        $this->assertSame(2, $result['total_invoices']);
    }

    public function test_the_dashboard_summary_takes_revenue_and_expenses_from_the_report(): void
    {
        $this->fakeReports(['*' => $this->sandboxReport()]);
        Sanctum::actingAs($this->user('admin'));

        // assertJsonPath compares strictly; assertJson would treat null, 0 and
        // false as equal, which is exactly the difference these tests guard.
        $this->getJson('/api/quickbooks/summary')
            ->assertOk()
            ->assertJsonPath('total_revenue', 10201.77)
            ->assertJsonPath('total_expenses', 8558.31)
            ->assertJsonPath('net_income', 1643.46)
            ->assertJsonPath('accounting_basis', 'Accrual')
            ->assertJsonPath('figures_error', null);
    }

    public function test_the_dashboard_summary_survives_a_rejected_connection(): void
    {
        $this->invoices();
        $this->fakeReports(['*' => []], 401);
        Sanctum::actingAs($this->user('admin'));

        // Null, not 0: the dashboard must read "Unavailable", never $0.00.
        $this->getJson('/api/quickbooks/summary')
            ->assertOk()
            ->assertJsonPath('total_revenue', null)
            ->assertJsonPath('total_expenses', null)
            ->assertJsonPath('figures_error', 'quickbooks_reconnect_required')
            ->assertJsonPath('total_invoices', 2);
    }
}
