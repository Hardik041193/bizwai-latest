<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\Ai\AiChatService;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\GetBills;
use App\Services\Ai\Tools\GetCreditMemos;
use App\Services\Ai\Tools\GetCustomers;
use App\Services\Ai\Tools\GetExpenses;
use App\Services\Ai\Tools\GetInvoices;
use App\Services\Ai\Tools\GetPayments;
use App\Services\Ai\Tools\GetTransactions;
use App\Services\QuickBooksReports;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wrong or missing chat answers found by replaying a tester's question list:
 * unpaid invoices reported as none, small expense categories invisible, a
 * customer's history answered with company expenses, rollup columns counted as
 * customers, and answers given from memory instead of a lookup.
 */
class AiChatAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_ACCURACY';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 10:00:00');

        $row = fn (array $values) => $values + ['realm_id' => $this->realm, 'created_at' => now(), 'updated_at' => now()];

        DB::table('quickbooks_invoices')->insert([
            // Synced as Open before its due date passed: it is overdue today.
            $row(['qbo_id' => '1', 'doc_number' => 'INV-1', 'customer_qbo_id' => '1', 'customer_name' => 'Harbor Clinic', 'txn_date' => '2026-08-01', 'due_date' => '2026-08-31', 'total_amount' => 1000, 'balance' => 1000, 'status' => 'Open']),
            // Part paid, and not due yet.
            $row(['qbo_id' => '2', 'doc_number' => 'INV-2', 'customer_qbo_id' => '2', 'customer_name' => 'Northwind IT', 'txn_date' => '2026-10-31', 'due_date' => '2026-11-30', 'total_amount' => 5000, 'balance' => 2000, 'status' => 'Open']),
            $row(['qbo_id' => '3', 'doc_number' => 'INV-3', 'customer_qbo_id' => '2', 'customer_name' => 'Northwind IT', 'txn_date' => '2026-08-31', 'due_date' => '2026-09-30', 'total_amount' => 13000, 'balance' => 0, 'status' => 'Paid']),
            // Overdue, but by less than 30 days.
            $row(['qbo_id' => '4', 'doc_number' => 'INV-4', 'customer_qbo_id' => '1', 'customer_name' => 'Harbor Clinic', 'txn_date' => '2026-10-10', 'due_date' => '2026-11-10', 'total_amount' => 300, 'balance' => 300, 'status' => 'Overdue']),
        ]);

        DB::table('quickbooks_bills')->insert([
            $row(['qbo_id' => '10', 'doc_number' => 'B-1', 'vendor_name' => 'Norton Lumber', 'txn_date' => '2026-07-01', 'due_date' => '2026-08-01', 'total_amount' => 250, 'balance' => 205, 'status' => 'Open']),
            $row(['qbo_id' => '11', 'doc_number' => 'B-2', 'vendor_name' => 'Norton Lumber', 'txn_date' => '2026-11-01', 'due_date' => '2026-11-25', 'total_amount' => 100, 'balance' => 100, 'status' => 'Open']),
            $row(['qbo_id' => '12', 'doc_number' => 'B-3', 'vendor_name' => 'Pacific Power', 'txn_date' => '2026-11-05', 'due_date' => '2026-12-20', 'total_amount' => 400, 'balance' => 400, 'status' => 'Open']),
            $row(['qbo_id' => '13', 'doc_number' => 'B-4', 'vendor_name' => 'Pacific Power', 'txn_date' => '2026-06-01', 'due_date' => '2026-06-30', 'total_amount' => 90, 'balance' => 0, 'status' => 'Paid']),
        ]);

        DB::table('quickbooks_transactions')->insert([
            $row(['qbo_id' => '20', 'txn_type' => 'CreditCard', 'txn_date' => '2026-10-02', 'account_name' => 'Advertising', 'entity_name' => 'Lamar Signs', 'amount' => 120]),
            $row(['qbo_id' => '21', 'txn_type' => 'Check', 'txn_date' => '2026-10-09', 'account_name' => 'Rent or Lease', 'entity_name' => 'Hall Properties', 'amount' => 900]),
            $row(['qbo_id' => '22', 'txn_type' => 'CreditCard', 'txn_date' => '2026-10-20', 'account_name' => 'Advertising', 'entity_name' => 'Lamar Signs', 'amount' => 80]),
        ]);

        DB::table('quickbooks_payments')->insert([
            $row(['qbo_id' => '30', 'customer_qbo_id' => '2', 'customer_name' => 'Northwind IT', 'txn_date' => '2026-10-05', 'total_amount' => 13000, 'unapplied_amount' => 0, 'invoice_qbo_ids' => json_encode(['3'])]),
            $row(['qbo_id' => '31', 'customer_qbo_id' => '1', 'customer_name' => 'Harbor Clinic', 'txn_date' => '2026-10-06', 'total_amount' => 500, 'unapplied_amount' => 500, 'invoice_qbo_ids' => json_encode([])]),
        ]);

        DB::table('quickbooks_credit_memos')->insert([
            $row(['qbo_id' => '40', 'doc_number' => 'CM-1', 'customer_qbo_id' => '1', 'customer_name' => 'Harbor Clinic', 'txn_date' => '2026-09-01', 'total_amount' => 150, 'remaining_credit' => 100]),
            $row(['qbo_id' => '41', 'doc_number' => 'CM-2', 'customer_qbo_id' => '2', 'customer_name' => 'Northwind IT', 'txn_date' => '2026-09-02', 'total_amount' => 40, 'remaining_credit' => 0]),
        ]);

        DB::table('quickbooks_customers')->insert([
            $row(['qbo_id' => '1', 'display_name' => 'Harbor Clinic', 'balance' => 1300, 'active' => true]),
            $row(['qbo_id' => '2', 'display_name' => 'Northwind IT', 'balance' => 2000, 'active' => true]),
            $row(['qbo_id' => '3', 'display_name' => 'Cool Cars', 'balance' => 0, 'active' => true]),
        ]);

        foreach (State::ENTITIES as $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 1);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);

        QuickBooksToken::create([
            'user_id' => $user->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
            'selected_clients' => [],
            'client_selected_at' => now(),
        ]);

        return $user->fresh();
    }

    private function context(): QuickBooksAiContext
    {
        return QuickBooksAiContext::forUser($this->admin());
    }

    // Invoices

    public function test_unpaid_invoices_include_overdue_ones_with_totals(): void
    {
        $result = (new GetInvoices)->handle(['status' => 'Unpaid'], $this->context());

        $this->assertSame(3, $result['total_count']);
        $this->assertSame(3300.0, $result['total_unpaid_balance']);
        $this->assertSame([
            ['customer_name' => 'Northwind IT', 'unpaid_invoices' => 1, 'balance' => 2000.0],
            ['customer_name' => 'Harbor Clinic', 'unpaid_invoices' => 2, 'balance' => 1300.0],
        ], $result['balance_by_customer']);
    }

    public function test_an_invoice_becomes_overdue_when_its_due_date_passes_after_the_sync(): void
    {
        $overdue = (new GetInvoices)->handle(['status' => 'Overdue'], $this->context());
        $notYetDue = (new GetInvoices)->handle(['status' => 'Open'], $this->context());

        $this->assertEqualsCanonicalizing(['INV-1', 'INV-4'], array_column($overdue['invoices'], 'doc_number'));
        $this->assertSame(['INV-2'], array_column($notYetDue['invoices'], 'doc_number'));

        $first = collect($overdue['invoices'])->firstWhere('doc_number', 'INV-1');
        $this->assertSame('Overdue', $first['status']);
        $this->assertSame(81, $first['days_overdue']);
    }

    public function test_invoices_filter_by_days_overdue_customer_and_payment_state(): void
    {
        $tool = new GetInvoices;

        $this->assertSame(['INV-1'], array_column($tool->handle(['min_days_overdue' => 30], $this->context())['invoices'], 'doc_number'));
        $this->assertSame(['INV-2'], array_column($tool->handle(['status' => 'PartiallyPaid'], $this->context())['invoices'], 'doc_number'));
        $this->assertSame(['INV-3'], array_column($tool->handle(['status' => 'Paid'], $this->context())['invoices'], 'doc_number'));

        $northwind = $tool->handle(['customer' => 'northwind'], $this->context());
        $this->assertSame(2, $northwind['total_count']);
        $this->assertSame(18000.0, $northwind['total_invoiced']);
        $this->assertSame(2000.0, $northwind['total_unpaid_balance']);
    }

    public function test_invoices_sort_by_amount_for_the_largest_ones(): void
    {
        $result = (new GetInvoices)->handle(['sort' => 'amount', 'limit' => 2], $this->context());

        $this->assertSame(['INV-3', 'INV-2'], array_column($result['invoices'], 'doc_number'));
        $this->assertSame(4, $result['total_count']);
    }

    // Bills

    public function test_unpaid_bills_total_what_is_owed_and_break_it_down_by_vendor(): void
    {
        $result = (new GetBills)->handle(['status' => 'Unpaid'], $this->context());

        $this->assertSame(3, $result['total_count']);
        $this->assertSame(705.0, $result['balance_owed']);
        $this->assertSame([
            ['vendor_name' => 'Pacific Power', 'bills' => 1, 'total_billed' => 400.0, 'balance_owed' => 400.0],
            ['vendor_name' => 'Norton Lumber', 'bills' => 2, 'total_billed' => 350.0, 'balance_owed' => 305.0],
        ], $result['by_vendor']);
    }

    public function test_bills_filter_by_overdue_age_and_by_falling_due_soon(): void
    {
        $tool = new GetBills;

        $this->assertSame(['B-1'], array_column($tool->handle(['status' => 'Overdue'], $this->context())['bills'], 'doc_number'));
        $this->assertSame(['B-1'], array_column($tool->handle(['min_days_overdue' => 30], $this->context())['bills'], 'doc_number'));
        $this->assertSame(['B-2'], array_column($tool->handle(['due_within_days' => 7], $this->context())['bills'], 'doc_number'));
    }

    // Purchases, payments, credits, customers

    public function test_purchases_filter_by_payee_and_account_with_a_total(): void
    {
        $byPayee = (new GetTransactions)->handle(['payee' => 'lamar'], $this->context());

        $this->assertSame(2, $byPayee['total_count']);
        $this->assertSame(200.0, $byPayee['total_amount']);

        $all = (new GetTransactions)->handle([], $this->context());

        $this->assertSame(1100.0, $all['total_amount']);
        $this->assertSame(['payee' => 'Hall Properties', 'transactions' => 1, 'amount' => 900.0], $all['by_payee'][0]);
        $this->assertSame(1, (new GetTransactions)->handle(['account' => 'rent'], $this->context())['total_count']);
    }

    public function test_the_purchases_tool_says_it_is_not_a_customers_history(): void
    {
        $this->assertStringContainsString("not a customer's history", (new GetTransactions)->description());
    }

    public function test_payments_can_be_limited_to_those_with_money_unapplied(): void
    {
        $result = (new GetPayments)->handle(['unapplied_only' => true], $this->context());

        $this->assertSame(['Harbor Clinic'], array_column($result['payments'], 'customer_name'));
        $this->assertSame(500.0, $result['total_unapplied']);
    }

    public function test_credit_memos_can_be_limited_to_those_with_credit_left(): void
    {
        $result = (new GetCreditMemos)->handle(['unused_only' => true], $this->context());

        $this->assertSame(['CM-1'], array_column($result['credit_memos'], 'doc_number'));
        $this->assertSame(100.0, $result['remaining_credit']);
    }

    public function test_customers_who_owe_money_list_largest_balance_first(): void
    {
        $result = (new GetCustomers)->handle(['with_balance_only' => true, 'sort' => 'balance'], $this->context());

        $this->assertSame(['Northwind IT', 'Harbor Clinic'], array_column($result['customers'], 'display_name'));
        $this->assertSame(3300.0, $result['total_balance']);
    }

    // Reports

    /**
     * A Profit and Loss report with twelve expense accounts, the smallest of
     * them a parent "Marketing" account holding two sub-accounts.
     */
    private function profitAndLossWithManyAccounts(): array
    {
        $line = fn (string $account, string $amount) => ['type' => 'Data', 'ColData' => [['value' => $account], ['value' => $amount]]];

        $rows = [];

        foreach (range(1, 11) as $n) {
            $rows[] = $line("Account {$n}", (string) (1000 * $n).'.00');
        }

        $rows[] = [
            'type' => 'Section',
            'Header' => ['ColData' => [['value' => 'Marketing'], ['value' => '']]],
            'Rows' => ['Row' => [$line('Advertising', '74.86'), $line('Trade Shows', '25.14')]],
            'Summary' => ['ColData' => [['value' => 'Total Marketing'], ['value' => '100.00']]],
        ];

        $total = fn (string $group, string $amount, array $rows = []) => [
            'type' => 'Section', 'group' => $group, 'Rows' => ['Row' => $rows],
            'Summary' => ['ColData' => [['value' => "Total {$group}"], ['value' => $amount]]],
        ];

        return [
            'Header' => ['ReportBasis' => 'Accrual', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Rows' => ['Row' => [
                $total('Income', '100000.00'),
                $total('Expenses', '66100.00', $rows),
                $total('NetIncome', '33900.00'),
            ]],
        ];
    }

    public function test_expenses_return_every_account_so_a_small_category_is_not_lost(): void
    {
        Http::fake(['*' => Http::response($this->profitAndLossWithManyAccounts())]);

        $result = (new GetExpenses)->handle(['period' => 'this_year'], $this->context());

        $this->assertCount(12, $result['by_account']);
        $this->assertContains(['account' => 'Marketing', 'amount' => 100.0], $result['by_account']);
    }

    public function test_expenses_search_accounts_and_sub_accounts_by_name(): void
    {
        Http::fake(['*' => Http::response($this->profitAndLossWithManyAccounts())]);

        $advertising = (new GetExpenses)->handle(['period' => 'this_year', 'account' => 'advertising'], $this->context());

        $this->assertSame([['account' => 'Advertising', 'parent' => 'Marketing', 'amount' => 74.86]], $advertising['matching_accounts']);
        $this->assertSame(74.86, $advertising['matching_total']);

        // The parent and both of its sub-accounts match; the total counts the
        // parent once, not the parent plus its own sub-accounts.
        $marketing = (new GetExpenses)->handle(['period' => 'this_year', 'account' => 'marketing'], $this->context());

        $this->assertCount(3, $marketing['matching_accounts']);
        $this->assertSame(100.0, $marketing['matching_total']);
    }

    public function test_an_expense_search_with_no_match_lists_the_account_names(): void
    {
        Http::fake(['*' => Http::response($this->profitAndLossWithManyAccounts())]);

        $result = (new GetExpenses)->handle(['period' => 'this_year', 'account' => 'office'], $this->context());

        $this->assertSame([], $result['matching_accounts']);
        $this->assertContains('Advertising', $result['all_account_names']);
        $this->assertArrayNotHasKey('matching_total', $result);
    }

    public function test_a_parent_customers_rollup_column_is_not_counted_as_a_customer(): void
    {
        $column = fn (string $title, string $key) => ['ColTitle' => $title, 'ColType' => 'Money', 'MetaData' => [['Name' => 'ColKey', 'Value' => $key]]];
        $cells = fn (string ...$values) => array_map(fn ($v) => ['value' => $v], $values);

        Http::fake(['*' => Http::response([
            'Header' => ['ReportBasis' => 'Accrual', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Columns' => ['Column' => [
                $column('', 'account'),
                $column('Northwind', '5'),
                $column('GM-Northwind IT', '6'),
                $column('Total Northwind', '5'),
                // A real customer whose name happens to start with "Total".
                $column('Total Fitness Gym', '7'),
                $column('TOTAL', 'total'),
            ]],
            'Rows' => ['Row' => [
                ['type' => 'Section', 'group' => 'Income', 'Summary' => ['ColData' => $cells('Total Income', '100.00', '400.00', '500.00', '50.00', '550.00')]],
                ['type' => 'Section', 'group' => 'NetIncome', 'Summary' => ['ColData' => $cells('Net Income', '100.00', '400.00', '500.00', '50.00', '550.00')]],
            ]],
        ])]);

        $token = QuickBooksToken::where('user_id', $this->admin()->id)->first();

        $customers = app(QuickBooksReports::class)->incomeByCustomer($token, Carbon::parse('2026-01-01'), Carbon::parse('2026-11-20'));

        $this->assertSame(['GM-Northwind IT', 'Northwind', 'Total Fitness Gym'], array_column($customers, 'customer_name'));
        $this->assertSame(550.0, array_sum(array_column($customers, 'income')));
    }

    // Conversation

    public function test_recent_answers_are_replayed_with_the_lookups_behind_them(): void
    {
        $lookup = fn (string $status) => [['name' => 'get_invoices', 'arguments' => ['status' => $status], 'result' => ['total_count' => 3]]];

        $stored = [];

        foreach (['Paid', 'Open', 'Overdue', 'Unpaid'] as $status) {
            $stored[] = ['role' => 'user', 'content' => "{$status}?", 'tool_calls' => null];
            $stored[] = ['role' => 'assistant', 'content' => "{$status} answer", 'tool_calls' => $lookup($status)];
        }

        $stored[] = ['role' => 'user', 'content' => 'Balance sheet?', 'tool_calls' => null];
        $stored[] = ['role' => 'assistant', 'content' => 'Not available yet.', 'tool_calls' => []];

        $history = AiChatService::historyFromStoredMessages($stored);

        // The oldest turn stays as plain text; the latest three carry lookups.
        $this->assertSame(['role' => 'assistant', 'content' => 'Paid answer'], $history[1]);

        $calls = array_values(array_filter($history, fn ($m) => ! empty($m['tool_calls'])));
        $results = array_values(array_filter($history, fn ($m) => $m['role'] === 'tool'));

        $this->assertCount(3, $calls);
        $this->assertCount(3, $results);
        $this->assertSame(['status' => 'Open'], $calls[0]['tool_calls'][0]['arguments']);
        $this->assertSame($calls[0]['tool_calls'][0]['id'], $results[0]['tool_call_id']);
        $this->assertSame('{"total_count":3}', $results[0]['content']);

        // Every lookup is followed by the answer that was given from it.
        $this->assertSame(['role' => 'assistant', 'content' => 'Unpaid answer'], $history[array_search($results[2], $history, true) + 1]);
        $this->assertSame(['role' => 'assistant', 'content' => 'Not available yet.'], end($history));
    }

    public function test_the_assistant_is_told_todays_date_and_sent_earlier_lookups(): void
    {
        config(['ai.provider' => 'openai', 'ai.openai.api_key' => 'test-key', 'ai.openai.model' => 'test-model']);

        $payloads = [];

        Http::fake(function (Request $request) use (&$payloads) {
            $payloads[] = $request->data();

            return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'This is not available yet.']]]]);
        });

        $user = $this->admin();
        $session = AiChatSession::create(['user_id' => $user->id]);
        $session->messages()->create(['role' => 'user', 'content' => 'Unpaid invoices?']);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => 'You have 3 unpaid invoices.',
            'tool_calls' => [['name' => 'get_invoices', 'arguments' => ['status' => 'Unpaid'], 'result' => ['total_count' => 3]]],
        ]);

        $this->actingAs($user)
            ->postJson("/api/ai-chat/sessions/{$session->id}/messages", ['message' => 'Show me my Balance Sheet.'])
            ->assertOk();

        $messages = $payloads[0]['messages'];

        $this->assertStringContainsString("Today's date is Friday, 20 November 2026.", $messages[0]['content']);
        $this->assertStringContainsString('not available in this assistant yet', $messages[0]['content']);
        $this->assertSame(['system', 'user', 'assistant', 'tool', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('get_invoices', $messages[2]['tool_calls'][0]['function']['name']);
        $this->assertSame($messages[2]['tool_calls'][0]['id'], $messages[3]['tool_call_id']);
    }
}
