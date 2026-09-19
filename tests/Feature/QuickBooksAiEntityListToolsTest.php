<?php

namespace Tests\Feature;

use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\GetBills;
use App\Services\Ai\Tools\GetCreditMemos;
use App\Services\Ai\Tools\GetPayments;
use App\Services\Ai\Tools\GetSalesReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chat tools that list bills, payments, sales receipts and credit memos.
 */
class QuickBooksAiEntityListToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_LISTS';

    private const AMY = [['qbo_id' => '1', 'name' => "Amy's Bird Sanctuary"]];

    protected function setUp(): void
    {
        parent::setUp();

        $row = fn (array $values) => $values + ['realm_id' => $this->realm, 'created_at' => now(), 'updated_at' => now()];

        DB::table('quickbooks_invoices')->insert([
            $row(['qbo_id' => '130', 'doc_number' => '1021', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'total_amount' => 459, 'balance' => 239, 'status' => 'Overdue']),
            $row(['qbo_id' => '131', 'doc_number' => '1025', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'total_amount' => 205, 'balance' => 0, 'status' => 'Paid']),
            $row(['qbo_id' => '140', 'doc_number' => '1030', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'total_amount' => 900, 'balance' => 0, 'status' => 'Paid']),
        ]);

        DB::table('quickbooks_bills')->insert([
            $row(['qbo_id' => '126', 'doc_number' => 'B-1', 'vendor_name' => 'Norton Lumber', 'txn_date' => '2026-08-01', 'total_amount' => 250, 'balance' => 90, 'status' => 'Overdue']),
            $row(['qbo_id' => '127', 'doc_number' => 'B-2', 'vendor_name' => 'Robertson & Associates', 'txn_date' => '2026-07-01', 'total_amount' => 300, 'balance' => 0, 'status' => 'Paid']),
            $row(['qbo_id' => '128', 'doc_number' => 'B-3', 'vendor_name' => "Hall's Plumbing", 'txn_date' => '2026-06-01', 'total_amount' => 30, 'balance' => 30, 'status' => 'Open']),
        ]);

        DB::table('quickbooks_payments')->insert([
            $row(['qbo_id' => '116', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'txn_date' => '2026-08-05', 'total_amount' => 205, 'invoice_qbo_ids' => json_encode(['131'])]),
            // Linked, unusually, to another client's invoice as well: the other
            // client's invoice number must not come through.
            $row(['qbo_id' => '117', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'txn_date' => '2026-08-20', 'total_amount' => 220, 'invoice_qbo_ids' => json_encode(['130', '140'])]),
            $row(['qbo_id' => '118', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'txn_date' => '2026-08-10', 'total_amount' => 900, 'invoice_qbo_ids' => json_encode(['140'])]),
        ]);

        DB::table('quickbooks_sales_receipts')->insert([
            $row(['qbo_id' => '38', 'doc_number' => '1003', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'txn_date' => '2026-08-10', 'total_amount' => 100]),
            $row(['qbo_id' => '39', 'doc_number' => '1004', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'txn_date' => '2025-01-01', 'total_amount' => 250]),
        ]);

        DB::table('quickbooks_credit_memos')->insert([
            $row(['qbo_id' => '73', 'doc_number' => '1026', 'customer_qbo_id' => '1', 'customer_name' => "Amy's Bird Sanctuary", 'txn_date' => '2026-08-12', 'total_amount' => 150, 'remaining_credit' => 100]),
            $row(['qbo_id' => '74', 'doc_number' => '1027', 'customer_qbo_id' => '2', 'customer_name' => 'Cool Cars', 'txn_date' => '2026-08-13', 'total_amount' => 40, 'remaining_credit' => 0]),
        ]);

        foreach (State::ENTITIES as $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 1);
        }
    }

    /**
     * @param  array<int, array{qbo_id: string, name: string}>|null  $clients  [] all clients; null not yet resolved
     */
    private function context(string $role, ?array $clients = []): QuickBooksAiContext
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

    public function test_bills_list_for_a_caller_who_sees_the_whole_company(): void
    {
        $admin = $this->context('admin');

        $all = (new GetBills)->handle([], $admin);
        $this->assertSame(3, $all['total_count']);
        $this->assertSame(120.0, $all['balance_owed']);
        $this->assertSame('Norton Lumber', $all['bills'][0]['vendor_name']);
        $this->assertTrue($all['data_freshness']['complete']);

        $this->assertSame(1, (new GetBills)->handle(['status' => 'Overdue'], $admin)['total_count']);
        $this->assertSame(1, (new GetBills)->handle(['vendor' => 'lumber'], $admin)['total_count']);

        // An all-clients user sees the whole company too.
        $this->assertSame(3, (new GetBills)->handle([], $this->context('user', []))['total_count']);
    }

    public function test_bills_are_company_level_and_hidden_from_client_scoped_users(): void
    {
        $result = (new GetBills)->handle([], $this->context('user', self::AMY));

        $this->assertSame('company_level_data', $result['error']);
        $this->assertArrayNotHasKey('bills', $result);
    }

    public function test_bills_wait_for_an_unresolved_scope(): void
    {
        $result = (new GetBills)->handle([], $this->context('user', null));

        $this->assertSame('client_access_pending', $result['error']);
        $this->assertArrayNotHasKey('bills', $result);
    }

    public function test_payments_show_only_the_callers_clients_and_never_another_clients_invoice(): void
    {
        $result = (new GetPayments)->handle([], $this->context('user', self::AMY));

        $this->assertSame(2, $result['total_count']);
        $this->assertSame(425.0, $result['total_received']);

        $byDate = collect($result['payments'])->keyBy('txn_date');
        $this->assertSame(['1025'], $byDate['2026-08-05']['invoices_paid']);
        // Invoice 1030 belongs to Cool Cars, so it does not appear.
        $this->assertSame(['1021'], $byDate['2026-08-20']['invoices_paid']);

        // Invoice numbers come from synced invoices, so an invoices sync still in
        // progress qualifies a payments answer too.
        State::markSyncing($this->realm, 'invoices');
        $this->assertSame(
            ['Invoices'],
            (new GetPayments)->handle([], $this->context('user', self::AMY))['data_freshness']['still_importing']
        );
    }

    public function test_an_admin_sees_every_payment_and_every_linked_invoice(): void
    {
        $result = (new GetPayments)->handle([], $this->context('admin'));

        $this->assertSame(3, $result['total_count']);
        $byDate = collect($result['payments'])->keyBy('txn_date');
        $this->assertSame(['1021', '1030'], $byDate['2026-08-20']['invoices_paid']);
    }

    public function test_sales_receipts_are_scoped_and_filter_by_period(): void
    {
        $this->assertSame(1, (new GetSalesReceipts)->handle([], $this->context('user', self::AMY))['total_count']);

        $admin = $this->context('admin');
        $this->assertSame(2, (new GetSalesReceipts)->handle([], $admin)['total_count']);

        $august = (new GetSalesReceipts)->handle(['period' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31'], $admin);
        $this->assertSame(1, $august['total_count']);
        $this->assertSame(100.0, $august['total_amount']);
    }

    public function test_credit_memos_report_the_credit_still_available(): void
    {
        $result = (new GetCreditMemos)->handle([], $this->context('user', self::AMY));

        $this->assertSame(1, $result['total_count']);
        $this->assertSame(150.0, $result['total_credited']);
        $this->assertSame(100.0, $result['remaining_credit']);
    }

    public function test_list_results_say_when_their_data_is_still_importing(): void
    {
        $admin = $this->context('admin');
        State::markQueued($this->realm);

        $freshness = (new GetBills)->handle([], $admin)['data_freshness'];

        $this->assertFalse($freshness['complete']);
        $this->assertSame(['Bills'], $freshness['still_importing']);
    }

    public function test_the_list_tools_are_registered_for_the_assistant(): void
    {
        $names = array_column((new ToolRegistry)->schemas(), 'name');

        foreach (['get_bills', 'get_payments', 'get_sales_receipts', 'get_credit_memos'] as $name) {
            $this->assertContains($name, $names);
        }
    }
}
