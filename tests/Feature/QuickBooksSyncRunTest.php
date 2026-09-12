<?php

namespace Tests\Feature;

use App\Jobs\SyncQuickBooksDataJob;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\QuickBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * A full sync run against a faked QuickBooks API.
 *
 * Covers the happy path end to end (records land, state is recorded) and the
 * partial-failure path, neither of which can be exercised against the real API
 * without a live connection.
 */
class QuickBooksSyncRunTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_RUN_1';

    private function token(): QuickBooksToken
    {
        $user = User::factory()->create();

        // created_at within the last 2 minutes, so refreshTokenIfNeeded()
        // short-circuits and no OAuth refresh is attempted.
        return QuickBooksToken::create([
            'user_id' => $user->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
        ]);
    }

    /**
     * Fake the IQL endpoint, dispatching on the entity named in the query.
     *
     * @param  array<string, mixed>  $overrides  entity => response body or status
     */
    private function fakeQuickBooks(array $overrides = []): void
    {
        $bodies = array_merge([
            'CompanyInfo' => ['QueryResponse' => ['CompanyInfo' => [[
                'CompanyName' => 'Acme Ltd',
                'LegalName' => 'Acme Limited',
                'Email' => ['Address' => 'books@acme.test'],
                'Country' => 'GB',
            ]]]],
            'Account' => ['QueryResponse' => ['Account' => [
                ['Id' => '1', 'Name' => 'Sales', 'AccountType' => 'Income',
                 'AccountSubType' => 'SalesOfProductIncome', 'Classification' => 'Revenue',
                 'CurrentBalance' => 1500.50, 'CurrencyRef' => ['value' => 'GBP'], 'Active' => true],
                ['Id' => '2', 'Name' => 'Office Supplies', 'AccountType' => 'Expense',
                 'CurrentBalance' => 200, 'CurrencyRef' => ['value' => 'GBP'], 'Active' => true],
            ]]],
            'Customer' => ['QueryResponse' => ['Customer' => [
                ['Id' => '10', 'DisplayName' => 'Beta Co', 'CompanyName' => 'Beta Co Ltd',
                 'PrimaryEmailAddr' => ['Address' => 'ap@beta.test'],
                 'PrimaryPhone' => ['FreeFormNumber' => '0123456789'],
                 'Balance' => 250.00, 'Active' => true],
            ]]],
            'Invoice' => ['QueryResponse' => ['Invoice' => [
                ['Id' => '100', 'DocNumber' => 'INV-001',
                 'CustomerRef' => ['name' => 'Beta Co', 'value' => '10'],
                 'BillEmail' => ['Address' => 'ap@beta.test'],
                 'TxnDate' => '2026-03-01', 'DueDate' => '2026-03-31',
                 'TotalAmt' => 1200.00, 'Balance' => 0.0,
                 'CurrencyRef' => ['value' => 'GBP'],
                 'Line' => [[
                     'Description' => 'Consulting', 'Amount' => 1200.00,
                     'SalesItemLineDetail' => ['Qty' => 10, 'UnitPrice' => 120,
                        'ItemRef' => ['name' => 'Consulting']],
                 ]]],
                ['Id' => '101', 'DocNumber' => 'INV-002',
                 'CustomerRef' => ['name' => 'Beta Co', 'value' => '10'],
                 'TxnDate' => '2026-04-01', 'DueDate' => '2099-01-01',
                 'TotalAmt' => 500.00, 'Balance' => 500.00,
                 'Line' => []],
            ]]],
            'Purchase' => ['QueryResponse' => ['Purchase' => [
                ['Id' => '200', 'PaymentType' => 'Cash', 'TxnDate' => '2026-03-05',
                 'EntityRef' => ['name' => 'Staples', 'value' => '55'],
                 'TotalAmt' => 75.25, 'PrivateNote' => 'Paper',
                 'CurrencyRef' => ['value' => 'GBP'],
                 'Line' => [[
                     'Amount' => 75.25,
                     'AccountBasedExpenseLineDetail' => ['AccountRef' => ['name' => 'Office Supplies']],
                 ]]],
            ]]],
        ], $overrides);

        Http::fake(function (Request $request) use ($bodies) {
            $url = urldecode($request->url());

            foreach ($bodies as $entity => $body) {
                if (str_contains($url, "FROM {$entity}")) {
                    return $body instanceof \Illuminate\Http\Client\Response
                        ? $body
                        : (is_int($body) ? Http::response('error', $body) : Http::response($body));
                }
            }

            return Http::response(['QueryResponse' => []]);
        });
    }

    public function test_a_full_sync_stores_records_and_marks_every_entity_complete(): void
    {
        $this->fakeQuickBooks();
        $token = $this->token();

        $counts = app(QuickBooksService::class)->syncAll($token);

        $this->assertSame(
            ['company_info' => 1, 'accounts' => 2, 'customers' => 1, 'invoices' => 2, 'transactions' => 1],
            $counts
        );

        $progress = State::progressFor($this->realm);
        $this->assertSame('complete', $progress['status']);
        $this->assertTrue($progress['complete']);
        $this->assertSame(100, $progress['progress']);
        $this->assertSame(0, $progress['entities_failed']);

        $this->assertDatabaseHas('quickbooks_accounts', ['realm_id' => $this->realm, 'qbo_id' => '1', 'name' => 'Sales']);
        $this->assertDatabaseHas('quickbooks_customers', ['realm_id' => $this->realm, 'email' => 'ap@beta.test']);
        $this->assertDatabaseHas('quickbooks_transactions', ['realm_id' => $this->realm, 'account_name' => 'Office Supplies']);

        // Company details land on the token, and the user is marked connected.
        $token->refresh();
        $this->assertSame('Acme Ltd', $token->company_name);
        $this->assertSame('books@acme.test', $token->company_email);
        $this->assertSame(User::QBO_STATUS_CONNECTED, $token->user->fresh()->qbo_status);
    }

    public function test_invoice_status_is_derived_from_balance_and_due_date(): void
    {
        $this->fakeQuickBooks();

        app(QuickBooksService::class)->syncAll($this->token());

        $this->assertSame('Paid', QuickBooksInvoice::where('qbo_id', '100')->value('status'));
        $this->assertSame('Open', QuickBooksInvoice::where('qbo_id', '101')->value('status'));

        // Line items are captured for the AI/reporting layer.
        $lines = QuickBooksInvoice::where('qbo_id', '100')->value('line_items');
        $this->assertSame('Consulting', $lines[0]['item_name']);
    }

    public function test_record_counts_are_recorded_per_entity(): void
    {
        $this->fakeQuickBooks();

        app(QuickBooksService::class)->syncAll($this->token());

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');

        $this->assertSame(2, $rows['accounts']->records_synced);
        $this->assertSame(2, $rows['invoices']->records_synced);
        $this->assertNotNull($rows['invoices']->last_synced_at);
    }

    /**
     * One failing entity must not deny the user every other figure.
     */
    public function test_a_failing_entity_does_not_abort_the_rest_of_the_run(): void
    {
        $this->fakeQuickBooks(['Invoice' => 401]);
        $token = $this->token();

        try {
            app(QuickBooksService::class)->syncAll($token);
            $this->fail('syncAll should rethrow so the job retries.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('completed with failures', $e->getMessage());
        }

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');

        $this->assertSame(State::STATUS_FAILED, $rows['invoices']->status);
        $this->assertStringContainsString('401', $rows['invoices']->error);

        // Entities after the failure still ran.
        $this->assertSame(State::STATUS_COMPLETE, $rows['transactions']->status);
        $this->assertSame(State::STATUS_COMPLETE, $rows['accounts']->status);
        $this->assertDatabaseHas('quickbooks_transactions', ['realm_id' => $this->realm, 'qbo_id' => '200']);

        // The UI is released rather than left spinning.
        $progress = State::progressFor($this->realm);
        $this->assertSame('partial', $progress['status']);
        $this->assertTrue($progress['complete']);
    }

    public function test_a_repeat_sync_updates_rather_than_duplicates(): void
    {
        $this->fakeQuickBooks();
        $token = $this->token();

        app(QuickBooksService::class)->syncAll($token);
        app(QuickBooksService::class)->syncAll($token);

        $this->assertSame(1, QuickBooksInvoice::where('qbo_id', '100')->count());
        $this->assertSame(2, QuickBooksInvoice::where('realm_id', $this->realm)->count());
    }

    /**
     * A job killed without reaching the per-entity handler (timeout, OOM,
     * worker restart) must not leave the poller spinning forever.
     */
    public function test_a_permanently_failed_job_closes_out_unfinished_entities(): void
    {
        $token = $this->token();
        State::markQueued($this->realm);
        State::markComplete($this->realm, 'company_info', 1);

        (new SyncQuickBooksDataJob($token->id))->failed(new RuntimeException('worker timed out'));

        $rows = State::where('realm_id', $this->realm)->get()->keyBy('entity');

        $this->assertSame(State::STATUS_COMPLETE, $rows['company_info']->status);
        $this->assertSame(State::STATUS_FAILED, $rows['invoices']->status);
        $this->assertTrue(State::progressFor($this->realm)['complete']);
    }

    public function test_disconnecting_purges_sync_state_for_the_realm(): void
    {
        $this->fakeQuickBooks();
        $token = $this->token();
        app(QuickBooksService::class)->syncAll($token);

        $this->assertDatabaseHas('quickbooks_sync_states', ['realm_id' => $this->realm]);

        app(QuickBooksService::class)->disconnect($token->user_id);

        $this->assertDatabaseMissing('quickbooks_sync_states', ['realm_id' => $this->realm]);
    }
}
