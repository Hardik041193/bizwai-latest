<?php

namespace Tests\Feature;

use App\Models\QuickBooksBillPayment;
use App\Models\QuickBooksDeposit;
use App\Models\QuickBooksRefundReceipt;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransfer;
use App\Models\QuickBooksVendor;
use App\Models\QuickBooksVendorCredit;
use App\Models\User;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\GetBankMovements;
use App\Services\Ai\Tools\GetBillPayments;
use App\Services\Ai\Tools\GetProfitAndLossTransactions;
use App\Services\Ai\Tools\GetRefunds;
use App\Services\Ai\Tools\GetVendorCredits;
use App\Services\Ai\Tools\GetVendors;
use App\Services\QuickBooksService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vendors, bill payments, vendor credits, refunds, bank deposits and transfers:
 * syncing them from QuickBooks and answering chat questions from them, plus the
 * transaction drill-down behind a Profit and Loss figure. QuickBooks bodies
 * follow the shape the sandbox realm returns.
 */
class QuickBooksVendorAndBankingTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_VENDORS';

    private const AMY = [['qbo_id' => '1', 'name' => "Amy's Bird Sanctuary"]];

    /** @var array<int, string> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function token(string $role = 'admin', ?array $clients = []): QuickBooksToken
    {
        return QuickBooksToken::create([
            'user_id' => User::factory()->create(['role' => $role])->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
            'selected_clients' => $clients,
            'client_selected_at' => $clients === null ? null : now(),
        ]);
    }

    private function context(string $role = 'admin', ?array $clients = []): QuickBooksAiContext
    {
        return QuickBooksAiContext::forUser($this->token($role, $clients)->user->fresh());
    }

    /**
     * Rows as the tools read them, with every sync step marked complete.
     */
    private function seedRows(): void
    {
        $row = fn (array $values) => $values + ['realm_id' => $this->realm, 'created_at' => now(), 'updated_at' => now()];

        DB::table('quickbooks_vendors')->insert([
            $row(['qbo_id' => '46', 'display_name' => 'Norton Lumber', 'balance' => 205, 'active' => true]),
            $row(['qbo_id' => '31', 'display_name' => 'Brosnahan Insurance', 'balance' => 0, 'active' => true]),
            $row(['qbo_id' => '41', 'display_name' => 'Hicks Hardware', 'balance' => 0, 'active' => true]),
            $row(['qbo_id' => '50', 'display_name' => 'Unused Vendor', 'balance' => 0, 'active' => true]),
        ]);

        DB::table('quickbooks_bills')->insert([
            $row(['qbo_id' => '25', 'doc_number' => 'B-25', 'vendor_qbo_id' => '46', 'vendor_name' => 'Norton Lumber', 'txn_date' => '2026-10-05', 'total_amount' => 308.55, 'balance' => 205, 'status' => 'Open']),
            $row(['qbo_id' => '26', 'doc_number' => 'B-26', 'vendor_qbo_id' => '31', 'vendor_name' => 'Brosnahan Insurance', 'txn_date' => '2026-10-10', 'total_amount' => 2000, 'balance' => 0, 'status' => 'Paid']),
            // Last year: outside an October filter.
            $row(['qbo_id' => '27', 'doc_number' => 'B-27', 'vendor_qbo_id' => '31', 'vendor_name' => 'Brosnahan Insurance', 'txn_date' => '2025-03-01', 'total_amount' => 500, 'balance' => 0, 'status' => 'Paid']),
        ]);

        DB::table('quickbooks_transactions')->insert([
            $row(['qbo_id' => '90', 'txn_type' => 'CreditCard', 'txn_date' => '2026-10-12', 'account_name' => 'Supplies', 'entity_name' => 'Hicks Hardware', 'amount' => 599.26]),
            // Paid to someone who is not a vendor: it belongs to no vendor's spending.
            $row(['qbo_id' => '91', 'txn_type' => 'Cash', 'txn_date' => '2026-10-13', 'account_name' => 'Meals', 'entity_name' => 'Cool Cars', 'amount' => 40]),
        ]);

        DB::table('quickbooks_bill_payments')->insert([
            $row(['qbo_id' => '118', 'doc_number' => '1', 'vendor_qbo_id' => '46', 'vendor_name' => 'Norton Lumber', 'txn_date' => '2026-10-20', 'total_amount' => 103.55, 'pay_type' => 'CreditCard', 'account_name' => 'Mastercard', 'bill_qbo_ids' => json_encode(['25'])]),
            $row(['qbo_id' => '119', 'doc_number' => '6', 'vendor_qbo_id' => '31', 'vendor_name' => 'Brosnahan Insurance', 'txn_date' => '2026-10-25', 'total_amount' => 2000, 'pay_type' => 'Check', 'account_name' => 'Checking', 'bill_qbo_ids' => json_encode(['26'])]),
            $row(['qbo_id' => '120', 'doc_number' => '7', 'vendor_qbo_id' => '31', 'vendor_name' => 'Brosnahan Insurance', 'txn_date' => '2025-03-15', 'total_amount' => 500, 'pay_type' => 'Check', 'account_name' => 'Checking', 'bill_qbo_ids' => json_encode(['27'])]),
        ]);

        DB::table('quickbooks_vendor_credits')->insert([
            $row(['qbo_id' => '200', 'doc_number' => 'VC-1', 'vendor_qbo_id' => '46', 'vendor_name' => 'Norton Lumber', 'txn_date' => '2026-09-01', 'total_amount' => 90, 'balance' => 40]),
            $row(['qbo_id' => '201', 'doc_number' => 'VC-2', 'vendor_qbo_id' => '41', 'vendor_name' => 'Hicks Hardware', 'txn_date' => '2026-09-02', 'total_amount' => 25, 'balance' => 0]),
        ]);

        DB::table('quickbooks_refund_receipts')->insert([
            $row(['qbo_id' => '66', 'doc_number' => '1020', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'txn_date' => '2026-08-07', 'total_amount' => 87.5, 'payment_method' => 'Check', 'account_name' => 'Checking', 'line_items' => json_encode([['description' => 'Refund - Pest control was ineffective', 'amount' => 87.5]])]),
            $row(['qbo_id' => '67', 'doc_number' => '1021', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'txn_date' => '2025-02-01', 'total_amount' => 30, 'payment_method' => 'Cash', 'account_name' => 'Checking', 'line_items' => null]),
        ]);

        DB::table('quickbooks_deposits')->insert([
            $row(['qbo_id' => '121', 'txn_date' => '2026-10-09', 'total_amount' => 868.15, 'account_name' => 'Checking', 'line_count' => 5, 'description' => null]),
            $row(['qbo_id' => '122', 'txn_date' => '2026-10-05', 'total_amount' => 600, 'account_name' => 'Savings', 'line_count' => 1, 'description' => 'Opening Balance']),
            $row(['qbo_id' => '123', 'txn_date' => '2026-09-05', 'total_amount' => 100, 'account_name' => 'Checking', 'line_count' => 1, 'description' => null]),
        ]);

        DB::table('quickbooks_transfers')->insert([
            $row(['qbo_id' => '300', 'txn_date' => '2026-10-15', 'amount' => 200, 'from_account_name' => 'Checking', 'to_account_name' => 'Savings', 'description' => 'Money to savings']),
        ]);

        foreach (State::ENTITIES as $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 1);
        }
    }

    // Sync

    /**
     * One record of each new kind, shaped as QuickBooks sends them.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function quickBooksRecords(): array
    {
        return [
            'VendorCredit' => [[
                'Id' => '200', 'DocNumber' => 'VC-1', 'TxnDate' => '2026-09-01', 'TotalAmt' => 90, 'Balance' => 40,
                'VendorRef' => ['value' => '46', 'name' => 'Norton Lumber'], 'CurrencyRef' => ['value' => 'USD'],
            ]],
            'Vendor' => [[
                'Id' => '46', 'DisplayName' => 'Norton Lumber', 'CompanyName' => 'Norton Lumber and Building Materials', 'Balance' => 205, 'Active' => true,
                'PrimaryEmailAddr' => ['Address' => 'Materials@intuit.com'], 'PrimaryPhone' => ['FreeFormNumber' => '(650) 363-6578'], 'CurrencyRef' => ['value' => 'USD'],
            ]],
            'BillPayment' => [
                [
                    'Id' => '118', 'DocNumber' => '1', 'TxnDate' => '2026-10-20', 'TotalAmt' => 103.55, 'PayType' => 'CreditCard',
                    'VendorRef' => ['value' => '46', 'name' => 'Norton Lumber'],
                    'CreditCardPayment' => ['CCAccountRef' => ['value' => '41', 'name' => 'Mastercard']],
                    'Line' => [['Amount' => 103.55, 'LinkedTxn' => [['TxnId' => '25', 'TxnType' => 'Bill']]]],
                ],
                [
                    'Id' => '119', 'DocNumber' => '6', 'TxnDate' => '2026-10-25', 'TotalAmt' => 2000, 'PayType' => 'Check',
                    'VendorRef' => ['value' => '31', 'name' => 'Brosnahan Insurance'],
                    'CheckPayment' => ['BankAccountRef' => ['value' => '35', 'name' => 'Checking']],
                    'Line' => [['Amount' => 2000, 'LinkedTxn' => [['TxnId' => '26', 'TxnType' => 'Bill'], ['TxnId' => '9', 'TxnType' => 'VendorCredit']]]],
                ],
            ],
            'RefundReceipt' => [[
                'Id' => '66', 'DocNumber' => '1020', 'TxnDate' => '2026-08-07', 'TotalAmt' => 87.5,
                'CustomerRef' => ['value' => '15', 'name' => "Pye's Cakes"], 'PaymentMethodRef' => ['value' => '2', 'name' => 'Check'],
                'DepositToAccountRef' => ['value' => '35', 'name' => 'Checking'],
                'Line' => [
                    ['Amount' => 87.5, 'Description' => 'Refund - Pest control was ineffective', 'DetailType' => 'SalesItemLineDetail', 'SalesItemLineDetail' => ['ItemRef' => ['value' => '10', 'name' => 'Pest Control'], 'UnitPrice' => 35, 'Qty' => 2.5]],
                    ['Amount' => 87.5, 'DetailType' => 'SubTotalLineDetail', 'SubTotalLineDetail' => []],
                ],
            ]],
            'Deposit' => [[
                'Id' => '121', 'TxnDate' => '2026-10-09', 'TotalAmt' => 868.15, 'DepositToAccountRef' => ['value' => '35', 'name' => 'Checking'],
                'Line' => [['Amount' => 226.75], ['Amount' => 641.4]],
            ]],
            'Transfer' => [[
                'Id' => '300', 'TxnDate' => '2026-10-15', 'Amount' => 200, 'PrivateNote' => 'Money to savings',
                'FromAccountRef' => ['value' => '35', 'name' => 'Checking'], 'ToAccountRef' => ['value' => '36', 'name' => 'Savings'],
            ]],
        ];
    }

    public function test_a_full_sync_stores_vendors_payments_credits_refunds_deposits_and_transfers(): void
    {
        $records = $this->quickBooksRecords();

        Http::fake(function (Request $request) use ($records) {
            $url = urldecode($request->url());

            // The entity is the word after FROM, matched whole: "Vendor" must
            // not answer for "VendorCredit", nor "Bill" for "BillPayment".
            if (preg_match('/FROM (\w+)/', $url, $m) && isset($records[$m[1]])) {
                return Http::response(['QueryResponse' => [$m[1] => $records[$m[1]]]]);
            }

            return Http::response(['QueryResponse' => []]);
        });

        $counts = app(QuickBooksService::class)->syncAll($this->token());

        $this->assertSame(1, $counts['vendors']);
        $this->assertSame(2, $counts['bill_payments']);
        $this->assertSame(1, $counts['vendor_credits']);
        $this->assertSame(1, $counts['refund_receipts']);
        $this->assertSame(1, $counts['deposits']);
        $this->assertSame(1, $counts['transfers']);

        $vendor = QuickBooksVendor::sole();
        $this->assertSame('Norton Lumber', $vendor->display_name);
        $this->assertSame('Materials@intuit.com', $vendor->email);
        $this->assertSame('205.00', $vendor->balance);

        $byCard = QuickBooksBillPayment::where('qbo_id', '118')->sole();
        $this->assertSame('Mastercard', $byCard->account_name);
        $this->assertSame(['25'], $byCard->bill_qbo_ids);

        // Only bills count as bills paid; the vendor credit applied is not one.
        $byCheque = QuickBooksBillPayment::where('qbo_id', '119')->sole();
        $this->assertSame('Checking', $byCheque->account_name);
        $this->assertSame(['26'], $byCheque->bill_qbo_ids);

        $this->assertSame('40.00', QuickBooksVendorCredit::sole()->balance);

        $refund = QuickBooksRefundReceipt::sole();
        $this->assertSame("Pye's Cakes", $refund->customer_name);
        $this->assertSame('Checking', $refund->account_name);
        $this->assertCount(1, $refund->line_items);

        $deposit = QuickBooksDeposit::sole();
        $this->assertSame('868.15', $deposit->total_amount);
        $this->assertSame(2, $deposit->line_count);

        $transfer = QuickBooksTransfer::sole();
        $this->assertSame('Checking', $transfer->from_account_name);
        $this->assertSame('Savings', $transfer->to_account_name);

        foreach (['vendors', 'bill_payments', 'vendor_credits', 'refund_receipts', 'deposits', 'transfers'] as $entity) {
            $this->assertSame(State::STATUS_COMPLETE, State::where('realm_id', $this->realm)->where('entity', $entity)->value('status'));
        }
    }

    public function test_a_company_synced_before_these_existed_gets_a_full_sync_next(): void
    {
        $old = array_diff(QuickBooksService::PAGED_ENTITIES, ['vendors', 'bill_payments', 'vendor_credits', 'refund_receipts', 'deposits', 'transfers']);

        foreach ($old as $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 1);
        }

        State::where('realm_id', $this->realm)->update(['changes_through' => now()->subDay()]);

        // Changes-only sync is possible for the old set, but not once the new
        // record types are part of the list: they have never been imported.
        $this->assertNotNull(State::changesSince($this->realm, array_values($old), QuickBooksService::CDC_MAX_AGE_DAYS));
        $this->assertNull(State::changesSince($this->realm, QuickBooksService::PAGED_ENTITIES, QuickBooksService::CDC_MAX_AGE_DAYS));
    }

    // Vendors

    public function test_vendors_rank_by_spending_from_bills_and_direct_purchases(): void
    {
        $this->seedRows();

        $result = (new GetVendors)->handle(['period' => 'last_month'], $this->context());

        $this->assertSame(['Brosnahan Insurance', 'Hicks Hardware', 'Norton Lumber', 'Unused Vendor'], array_column($result['vendors'], 'vendor_name'));
        $this->assertSame([
            'vendor_name' => 'Hicks Hardware', 'active' => true, 'balance_owed_now' => 0.0,
            'bills_received' => 0.0, 'direct_purchases' => 599.26, 'total_spending' => 599.26, 'paid_against_bills' => 0.0,
        ], $result['vendors'][1]);
        $this->assertSame(2000.0, $result['vendors'][0]['total_spending']);
        $this->assertSame(3, $result['vendors_with_spending']);
        // 2000 + 599.26 + 308.55: the payment to a non-vendor is not included.
        $this->assertSame(2907.81, $result['totals']['total_spending']);
        $this->assertSame(2103.55, $result['totals']['paid_against_bills']);
    }

    public function test_vendor_spending_covers_all_time_without_a_period(): void
    {
        $this->seedRows();

        $result = (new GetVendors)->handle(['search' => 'brosnahan'], $this->context());

        $this->assertSame('all time', $result['period']);
        $this->assertSame(2500.0, $result['vendors'][0]['total_spending']);
        $this->assertSame(2500.0, $result['vendors'][0]['paid_against_bills']);
    }

    public function test_vendors_owed_money_list_largest_balance_first(): void
    {
        $this->seedRows();

        $result = (new GetVendors)->handle(['with_balance_only' => true, 'sort' => 'balance'], $this->context());

        $this->assertSame(['Norton Lumber'], array_column($result['vendors'], 'vendor_name'));
        $this->assertSame(205.0, $result['totals']['balance_owed_now']);
    }

    // Bill payments, vendor credits, refunds, bank movements

    public function test_bill_payments_show_what_was_paid_how_and_for_which_bills(): void
    {
        $this->seedRows();

        $result = (new GetBillPayments)->handle(['period' => 'last_month'], $this->context());

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(2103.55, $result['total_paid']);
        $this->assertSame([
            'vendor_name' => 'Brosnahan Insurance', 'txn_date' => '2026-10-25', 'total_amount' => 2000.0,
            'paid_by' => 'Check', 'paid_from_account' => 'Checking', 'reference' => '6', 'bills_paid' => 1, 'bill_numbers' => ['B-26'],
        ], $result['bill_payments'][0]);
        $this->assertSame(['vendor_name' => 'Brosnahan Insurance', 'payments' => 1, 'total_paid' => 2000.0], $result['by_vendor'][0]);
    }

    public function test_a_vendors_payment_history_covers_every_payment_to_them(): void
    {
        $this->seedRows();

        $result = (new GetBillPayments)->handle(['vendor' => 'brosnahan'], $this->context());

        $this->assertSame(['2026-10-25', '2025-03-15'], array_column($result['bill_payments'], 'txn_date'));
        $this->assertSame(2500.0, $result['total_paid']);
    }

    public function test_vendor_credits_report_what_is_still_unused(): void
    {
        $this->seedRows();

        $all = (new GetVendorCredits)->handle([], $this->context());
        $unused = (new GetVendorCredits)->handle(['unused_only' => true], $this->context());

        $this->assertSame(115.0, $all['total_credited']);
        $this->assertSame(40.0, $all['total_unused_credit']);
        $this->assertSame(['VC-1'], array_column($unused['vendor_credits'], 'doc_number'));
    }

    public function test_refunds_list_what_was_paid_back_and_why(): void
    {
        $this->seedRows();

        $result = (new GetRefunds)->handle(['period' => 'this_year'], $this->context());

        $this->assertSame(1, $result['total_count']);
        $this->assertSame(87.5, $result['total_refunded']);
        $this->assertSame(['Refund - Pest control was ineffective'], $result['refunds'][0]['refunded_for']);
    }

    public function test_refunds_show_only_a_scoped_users_clients(): void
    {
        $this->seedRows();

        $result = (new GetRefunds)->handle([], $this->context('user', self::AMY));

        $this->assertSame(["Amy's Bird Sanctuary"], array_column($result['refunds'], 'customer_name'));
        $this->assertSame(87.5, $result['total_refunded']);
    }

    public function test_bank_deposits_filter_by_period_and_account(): void
    {
        $this->seedRows();

        $lastMonth = (new GetBankMovements)->handle(['type' => 'deposits', 'period' => 'last_month'], $this->context());
        $savings = (new GetBankMovements)->handle(['type' => 'deposits', 'account' => 'savings'], $this->context());

        $this->assertSame(2, $lastMonth['total_count']);
        $this->assertSame(1468.15, $lastMonth['total_deposited']);
        $this->assertSame(['2026-10-09', '2026-10-05'], array_column($lastMonth['deposits'], 'txn_date'));
        $this->assertSame(600.0, $savings['total_deposited']);
    }

    public function test_transfers_match_an_account_on_either_side(): void
    {
        $this->seedRows();

        $result = (new GetBankMovements)->handle(['type' => 'transfers', 'account' => 'savings'], $this->context());

        $this->assertSame(200.0, $result['total_transferred']);
        $this->assertSame('Checking', $result['transfers'][0]['from_account']);
        $this->assertArrayNotHasKey('deposits', $result);
    }

    public function test_company_level_vendor_and_bank_tools_are_hidden_from_client_scoped_users(): void
    {
        $this->seedRows();

        $context = $this->context('user', self::AMY);

        foreach ([
            [new GetVendors, []],
            [new GetBillPayments, []],
            [new GetVendorCredits, []],
            [new GetBankMovements, ['type' => 'deposits']],
        ] as [$tool, $arguments]) {
            $this->assertSame('company_level_data', $tool->handle($arguments, $context)['error'], $tool->name());
        }
    }

    // Profit and Loss drill-down

    /**
     * A Profit and Loss Detail report: account sections nested under the
     * report's own Income and Expenses sections, one row per transaction line.
     */
    private function detailReport(): array
    {
        $column = fn (string $title, string $key) => ['ColTitle' => $title, 'MetaData' => [['Name' => 'ColKey', 'Value' => $key]]];
        $line = fn (string ...$cells) => ['type' => 'Data', 'ColData' => array_map(fn (string $v) => ['value' => $v], $cells)];
        $section = fn (string $title, array $rows, string $total = '') => [
            'type' => 'Section',
            'Header' => ['ColData' => [['value' => $title], ['value' => '']]],
            'Rows' => ['Row' => $rows],
            'Summary' => ['ColData' => [['value' => "Total for {$title}"], ['value' => $total]]],
        ];

        return [
            'Header' => ['ReportName' => 'ProfitAndLossDetail', 'ReportBasis' => 'Accrual', 'Option' => [['Name' => 'NoReportData', 'Value' => 'false']]],
            'Columns' => ['Column' => [
                $column('Date', 'tx_date'), $column('Transaction Type', 'txn_type'), $column('Num', 'doc_num'), $column('Name', 'name'),
                $column('Memo/Description', 'memo'), $column('Split', 'split_acc'), $column('Amount', 'subt_nat_amount'), $column('Balance', 'rbal_nat_amount'),
            ]],
            'Rows' => ['Row' => [
                $section('Ordinary Income/Expenses', [
                    $section('Income', [
                        $section('Design income', [
                            $line('2026-08-07', 'Invoice', '1010', 'Weiskopf Consulting', 'Custom Design', 'Accounts Receivable (A/R)', '375.00', '375.00'),
                            $line('2026-08-04', 'Sales Receipt', '1003', 'Dylan Sollfrank', 'Custom Design', 'Checking', '337.50', '712.50'),
                        ]),
                        $section('Landscaping Services', [
                            // A sub-account nested one level further down.
                            $section('Job Materials', [
                                $line('2026-08-06', 'Invoice', '1032', 'Travis Waldron', 'Garden Rocks', 'Accounts Receivable (A/R)', '84.00', '84.00'),
                            ]),
                        ]),
                    ]),
                    $section('Expenses', [
                        $section('Advertising', [
                            $line('2026-08-07', 'Expense', '', 'Lee Advertising', '', 'Checking', '74.86', '74.86'),
                        ]),
                    ]),
                ]),
                $section('Other Income/Expenses', [
                    $section('Other Expenses', [
                        $section('Depreciation', [
                            $line('2026-08-31', 'Journal Entry', '5', '', 'Monthly depreciation', '-Split-', '2,000.00', '2,000.00'),
                        ]),
                    ]),
                ]),
            ]],
        ];
    }

    private function fakeDetail(): void
    {
        Http::fake(function (Request $request) {
            $this->requests[] = urldecode($request->url());

            return Http::response($this->detailReport());
        });
    }

    public function test_the_drill_down_lists_the_transactions_behind_revenue(): void
    {
        $this->fakeDetail();

        $result = (new GetProfitAndLossTransactions)->handle(
            ['period' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'section' => 'income'],
            $this->context()
        );

        $this->assertStringContainsString('/reports/ProfitAndLossDetail', $this->requests[0]);
        $this->assertStringContainsString('start_date=2026-08-01', $this->requests[0]);
        $this->assertSame(3, $result['total_count']);
        $this->assertSame(796.5, $result['income_total']);
        $this->assertSame(0.0, $result['expenses_total']);
        $this->assertSame([
            'section' => 'income', 'account' => 'Design income', 'date' => '2026-08-04', 'type' => 'Sales Receipt',
            'number' => '1003', 'name' => 'Dylan Sollfrank', 'memo' => 'Custom Design', 'amount' => 337.5,
        ], $result['transactions'][0]);
        $this->assertSame('Job Materials', $result['transactions'][1]['account']);
    }

    public function test_the_drill_down_separates_costs_including_other_expenses(): void
    {
        $this->fakeDetail();

        $result = (new GetProfitAndLossTransactions)->handle(['period' => 'last_quarter', 'section' => 'expenses', 'sort' => 'amount'], $this->context());

        $this->assertSame(['Depreciation', 'Advertising'], array_column($result['transactions'], 'account'));
        $this->assertSame(2074.86, $result['expenses_total']);
        $this->assertNull($result['transactions'][0]['name']);
    }

    public function test_the_drill_down_filters_by_account_and_name(): void
    {
        $this->fakeDetail();

        $context = $this->context();

        $advertising = (new GetProfitAndLossTransactions)->handle(['period' => 'last_quarter', 'account' => 'advert'], $context);
        $travis = (new GetProfitAndLossTransactions)->handle(['period' => 'last_quarter', 'name' => 'travis'], $context);

        $this->assertSame(['Lee Advertising'], array_column($advertising['transactions'], 'name'));
        $this->assertSame(['1032'], array_column($travis['transactions'], 'number'));
    }

    public function test_a_scoped_users_drill_down_is_filtered_to_their_clients(): void
    {
        $this->fakeDetail();

        $result = (new GetProfitAndLossTransactions)->handle(['period' => 'last_quarter'], $this->context('user', self::AMY));

        $this->assertStringContainsString('customer=1', $this->requests[0]);
        $this->assertTrue($result['limited_to_your_clients']);
    }

    public function test_the_tools_are_registered_for_the_assistant(): void
    {
        $names = array_column((new ToolRegistry)->schemas(), 'name');

        foreach (['get_vendors', 'get_bill_payments', 'get_vendor_credits', 'get_refunds', 'get_bank_movements', 'get_profit_and_loss_transactions'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }
}
