<?php

namespace Tests\Feature;

use App\Models\QuickBooksBill;
use App\Models\QuickBooksCreditMemo;
use App\Models\QuickBooksPayment;
use App\Models\QuickBooksSalesReceipt;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\QuickBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bills, payments, sales receipts and credit memos.
 *
 * Fixtures follow the field structure of records fetched from a live sandbox
 * realm, not the API reference, so the mapping is tested against what
 * QuickBooks actually sends.
 */
class QuickBooksNewEntitiesTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_NEW';

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

    /**
     * @param  array<string, array<int, array>>  $rows  QuickBooks entity name => records
     */
    private function fakeRecords(array $rows): void
    {
        Http::fake(function (Request $request) use ($rows) {
            $url = urldecode($request->url());
            $this->requests[] = $url;

            if (str_contains($url, '/cdc')) {
                return Http::response(['CDCResponse' => [['QueryResponse' => []]]]);
            }

            preg_match('/FROM (\w+)/', $url, $m);
            $entity = $m[1] ?? '';

            return Http::response(['QueryResponse' => [$entity => $rows[$entity] ?? []]]);
        });
    }

    private function sync(string $entity): int
    {
        return app(QuickBooksService::class)->syncEntityPage($this->token(), $entity)['count'];
    }

    public function test_bills_store_the_vendor_amounts_status_and_both_kinds_of_line(): void
    {
        $this->fakeRecords(['Bill' => [
            [
                'Id' => '126', 'DocNumber' => 'B-1', 'TxnDate' => '2026-08-01', 'DueDate' => '2026-08-31',
                'VendorRef' => ['value' => '40', 'name' => 'Norton Lumber'],
                'APAccountRef' => ['value' => '33', 'name' => 'Accounts Payable'],
                'TotalAmt' => 250.00, 'Balance' => 0, 'PrivateNote' => 'Lumber order',
                'CurrencyRef' => ['value' => 'USD', 'name' => 'United States Dollar'],
                'Line' => [
                    ['Id' => '1', 'Description' => 'Pine boards', 'Amount' => 200.00, 'DetailType' => 'ItemBasedExpenseLineDetail',
                        'ItemBasedExpenseLineDetail' => ['BillableStatus' => 'NotBillable', 'ItemRef' => ['value' => '11', 'name' => 'Pine'], 'UnitPrice' => 20, 'Qty' => 10]],
                    ['Id' => '2', 'Description' => 'Delivery', 'Amount' => 50.00, 'DetailType' => 'AccountBasedExpenseLineDetail',
                        'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => '60', 'name' => 'Freight'], 'BillableStatus' => 'Billable']],
                ],
            ],
            ['Id' => '127', 'TxnDate' => '2026-08-01', 'DueDate' => '2000-01-01', 'VendorRef' => ['value' => '41', 'name' => 'Late Co'], 'TotalAmt' => 90, 'Balance' => 90, 'Line' => []],
            ['Id' => '128', 'TxnDate' => '2026-08-01', 'DueDate' => '2099-01-01', 'VendorRef' => ['value' => '42', 'name' => 'Future Co'], 'TotalAmt' => 30, 'Balance' => 30, 'Line' => []],
        ]]);

        $this->assertSame(3, $this->sync('bills'));

        $bill = QuickBooksBill::where('realm_id', $this->realm)->where('qbo_id', '126')->first();
        $this->assertSame('B-1', $bill->doc_number);
        $this->assertSame('Norton Lumber', $bill->vendor_name);
        $this->assertSame('40', $bill->vendor_qbo_id);
        $this->assertSame('250.00', $bill->total_amount);
        $this->assertSame('Paid', $bill->status);
        $this->assertSame('Accounts Payable', $bill->ap_account_name);
        $this->assertSame('Lumber order', $bill->description);
        $this->assertSame('USD', $bill->currency_ref);

        $this->assertCount(2, $bill->line_items);
        $this->assertSame('Pine', $bill->line_items[0]['item_name']);
        $this->assertSame(10, $bill->line_items[0]['quantity']);
        $this->assertNull($bill->line_items[0]['account_name']);
        $this->assertSame('Freight', $bill->line_items[1]['account_name']);
        $this->assertSame('Billable', $bill->line_items[1]['billable_status']);

        $this->assertSame('Overdue', QuickBooksBill::where('qbo_id', '127')->value('status'));
        $this->assertSame('Open', QuickBooksBill::where('qbo_id', '128')->value('status'));
    }

    public function test_payments_store_the_customer_and_the_invoices_they_settled(): void
    {
        $this->fakeRecords(['Payment' => [[
            'Id' => '116', 'TxnDate' => '2026-08-05',
            'CustomerRef' => ['value' => '1', 'name' => "Amy's Bird Sanctuary"],
            'DepositToAccountRef' => ['value' => '4'],
            'TotalAmt' => 300.00, 'UnappliedAmt' => 20, 'PaymentRefNum' => 'CHK-99',
            'PaymentMethodRef' => ['value' => '2', 'name' => 'Check'],
            'CurrencyRef' => ['value' => 'USD'],
            // The top-level link is the bank deposit, not an invoice.
            'LinkedTxn' => [['TxnId' => '500', 'TxnType' => 'Deposit']],
            'Line' => [
                ['Amount' => 200, 'LinkedTxn' => [['TxnId' => '130', 'TxnType' => 'Invoice']]],
                ['Amount' => 80, 'LinkedTxn' => [['TxnId' => '131', 'TxnType' => 'Invoice'], ['TxnId' => '130', 'TxnType' => 'Invoice']]],
                ['Amount' => 0, 'LinkedTxn' => [['TxnId' => '77', 'TxnType' => 'CreditMemo']]],
            ],
        ]]]);

        $this->assertSame(1, $this->sync('payments'));

        $payment = QuickBooksPayment::where('realm_id', $this->realm)->first();
        $this->assertSame("Amy's Bird Sanctuary", $payment->customer_name);
        $this->assertSame('1', $payment->customer_qbo_id);
        $this->assertSame('300.00', $payment->total_amount);
        $this->assertSame('20.00', $payment->unapplied_amount);
        $this->assertSame('Check', $payment->payment_method);
        $this->assertSame('CHK-99', $payment->payment_ref_number);
        $this->assertSame('4', $payment->deposit_account_qbo_id);
        // Deduplicated, invoices only: not the deposit, not the credit memo.
        $this->assertSame(['130', '131'], $payment->invoice_qbo_ids);
    }

    public function test_sales_receipts_store_the_sale_tax_payment_and_item_lines(): void
    {
        $this->fakeRecords(['SalesReceipt' => [[
            'Id' => '38', 'DocNumber' => '1003', 'TxnDate' => '2026-08-10',
            'CustomerRef' => ['value' => '5', 'name' => 'Dukes Basketball Camp'],
            'BillEmail' => ['Address' => 'Dukes_bball@intuit.com'],
            'TotalAmt' => 422.40, 'Balance' => 0,
            'TxnTaxDetail' => ['TotalTax' => 22.40],
            'PaymentMethodRef' => ['value' => '1', 'name' => 'Cash'],
            'DepositToAccountRef' => ['value' => '4', 'name' => 'Undeposited Funds'],
            'Line' => [
                ['Id' => '1', 'Description' => 'Rock fountain', 'Amount' => 400, 'DetailType' => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => ['ItemRef' => ['value' => '5', 'name' => 'Rock Fountain'], 'UnitPrice' => 400, 'Qty' => 1]],
                ['Amount' => 400, 'DetailType' => 'SubTotalLineDetail', 'SubTotalLineDetail' => []],
            ],
        ]]]);

        $this->assertSame(1, $this->sync('sales_receipts'));

        $receipt = QuickBooksSalesReceipt::where('realm_id', $this->realm)->first();
        $this->assertSame('1003', $receipt->doc_number);
        $this->assertSame('5', $receipt->customer_qbo_id);
        $this->assertSame('Dukes_bball@intuit.com', $receipt->customer_email);
        $this->assertSame('422.40', $receipt->total_amount);
        $this->assertSame('22.40', $receipt->total_tax);
        $this->assertSame('Cash', $receipt->payment_method);
        $this->assertSame('Undeposited Funds', $receipt->deposit_account_name);
        // The subtotal line is not an item.
        $this->assertCount(1, $receipt->line_items);
        $this->assertSame('Rock Fountain', $receipt->line_items[0]['item_name']);
    }

    public function test_credit_memos_store_the_credit_still_available(): void
    {
        $this->fakeRecords(['CreditMemo' => [[
            'Id' => '73', 'DocNumber' => '1026', 'TxnDate' => '2026-08-12',
            'CustomerRef' => ['value' => '3', 'name' => 'Cool Cars'],
            'TotalAmt' => 150.00, 'RemainingCredit' => 100, 'Balance' => 100,
            'TxnTaxDetail' => ['TotalTax' => 0],
            'Line' => [
                ['Id' => '1', 'Description' => 'Refund for returned part', 'Amount' => 150, 'DetailType' => 'SalesItemLineDetail',
                    'SalesItemLineDetail' => ['ItemRef' => ['value' => '9', 'name' => 'Parts'], 'UnitPrice' => 150, 'Qty' => 1]],
            ],
        ]]]);

        $this->assertSame(1, $this->sync('credit_memos'));

        $memo = QuickBooksCreditMemo::where('realm_id', $this->realm)->first();
        $this->assertSame('1026', $memo->doc_number);
        $this->assertSame('Cool Cars', $memo->customer_name);
        $this->assertSame('150.00', $memo->total_amount);
        $this->assertSame('100.00', $memo->remaining_credit);
        $this->assertSame('Parts', $memo->line_items[0]['item_name']);
    }

    public function test_the_new_entities_are_sync_steps_with_progress_labels(): void
    {
        foreach (['bills', 'payments', 'sales_receipts', 'credit_memos'] as $entity) {
            $this->assertContains($entity, QuickBooksService::PAGED_ENTITIES);
            $this->assertContains($entity, State::ENTITIES);
            $this->assertArrayHasKey($entity, State::LABELS);
        }

        // Single steps first, then data entities in paged order, which the
        // orchestrator and progress bar both rely on.
        $this->assertSame(
            QuickBooksService::PAGED_ENTITIES,
            array_values(array_diff(State::ENTITIES, ['company_info', 'client_matching']))
        );
    }

    public function test_a_change_request_asks_for_the_new_entities_too(): void
    {
        $this->fakeRecords([]);

        app(QuickBooksService::class)->syncChanges($this->token(), now()->subDay());

        $this->assertStringContainsString(
            'entities=Account,Customer,Invoice,Purchase,Bill,Payment,SalesReceipt,CreditMemo',
            $this->requests[0]
        );
    }

    public function test_disconnecting_purges_the_new_tables_too(): void
    {
        $this->fakeRecords([
            'Bill' => [['Id' => '1', 'TotalAmt' => 1, 'Line' => []]],
            'Payment' => [['Id' => '2', 'TotalAmt' => 1, 'Line' => []]],
            'SalesReceipt' => [['Id' => '3', 'TotalAmt' => 1, 'Line' => []]],
            'CreditMemo' => [['Id' => '4', 'TotalAmt' => 1, 'Line' => []]],
        ]);
        $token = $this->token();
        $service = app(QuickBooksService::class);

        foreach (['bills', 'payments', 'sales_receipts', 'credit_memos'] as $entity) {
            $service->syncEntityPage($token, $entity);
        }
        $this->assertSame(1, QuickBooksBill::where('realm_id', $this->realm)->count());

        $service->disconnect($token->user_id);

        $this->assertSame(0, QuickBooksBill::where('realm_id', $this->realm)->count());
        $this->assertSame(0, QuickBooksPayment::where('realm_id', $this->realm)->count());
        $this->assertSame(0, QuickBooksSalesReceipt::where('realm_id', $this->realm)->count());
        $this->assertSame(0, QuickBooksCreditMemo::where('realm_id', $this->realm)->count());
    }
}
