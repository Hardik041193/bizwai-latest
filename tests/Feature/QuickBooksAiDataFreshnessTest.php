<?php

namespace Tests\Feature;

use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\GetCompanySummary;
use App\Services\Ai\Tools\GetCustomers;
use App\Services\Ai\Tools\GetInvoices;
use App\Services\Ai\Tools\GetTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tools that answer from synced tables say how complete that data is.
 */
class QuickBooksAiDataFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_FRESHNESS';

    private function context(): QuickBooksAiContext
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

        return QuickBooksAiContext::forUser($user->fresh());
    }

    private function completed(string ...$entities): void
    {
        foreach ($entities as $entity) {
            State::markSyncing($this->realm, $entity);
            State::markComplete($this->realm, $entity, 1);
        }
    }

    public function test_fully_imported_data_is_reported_complete(): void
    {
        $context = $this->context();
        $this->completed(...State::ENTITIES);

        $freshness = (new GetInvoices)->handle([], $context)['data_freshness'];

        $this->assertTrue($freshness['complete']);
        $this->assertSame([], $freshness['still_importing']);
        $this->assertSame([], $freshness['failed_to_import']);
        $this->assertNotNull($freshness['last_synced_at']);
    }

    public function test_a_sync_in_progress_is_reported_as_still_importing(): void
    {
        $context = $this->context();
        $this->completed(...State::ENTITIES);
        State::markQueued($this->realm);

        $freshness = (new GetInvoices)->handle([], $context)['data_freshness'];

        $this->assertFalse($freshness['complete']);
        $this->assertSame(['Invoices'], $freshness['still_importing']);
    }

    public function test_a_company_never_synced_is_reported_as_not_imported(): void
    {
        $freshness = (new GetCustomers)->handle([], $this->context())['data_freshness'];

        $this->assertFalse($freshness['complete']);
        $this->assertSame(['Customers'], $freshness['still_importing']);
        $this->assertNull($freshness['last_synced_at']);
    }

    public function test_a_failed_import_is_reported(): void
    {
        $context = $this->context();
        $this->completed(...State::ENTITIES);
        State::markFailed($this->realm, 'transactions', 'QuickBooks API error (500)');

        $freshness = (new GetTransactions)->handle([], $context)['data_freshness'];

        $this->assertFalse($freshness['complete']);
        $this->assertSame([], $freshness['still_importing']);
        $this->assertSame(['Expenses'], $freshness['failed_to_import']);
    }

    /**
     * A customers sync in progress says nothing about invoices already imported.
     */
    public function test_only_the_data_a_tool_reads_is_considered(): void
    {
        $context = $this->context();
        $this->completed(...State::ENTITIES);
        State::markSyncing($this->realm, 'customers');

        $this->assertTrue((new GetInvoices)->handle([], $context)['data_freshness']['complete']);
        $this->assertFalse((new GetCustomers)->handle([], $context)['data_freshness']['complete']);
    }

    public function test_the_company_summary_considers_the_customers_and_invoices_it_counts(): void
    {
        Http::fake(fn () => Http::response('{"Fault":{}}', 500));
        $context = $this->context();
        $this->completed(...State::ENTITIES);
        State::markFailed($this->realm, 'customers', 'QuickBooks API error (500)');
        State::markSyncing($this->realm, 'transactions');

        $freshness = (new GetCompanySummary)->handle([], $context)['data_freshness'];

        $this->assertFalse($freshness['complete']);
        $this->assertSame(['Customers'], $freshness['failed_to_import']);
        // Transactions are not counted in the summary, so their sync is irrelevant.
        $this->assertSame([], $freshness['still_importing']);
    }

    public function test_last_synced_is_the_oldest_of_the_data_read(): void
    {
        $this->freezeSecond();
        $this->completed('customers');
        $this->travel(2)->hours();
        $this->completed('invoices');

        // Connected after the time travel. A token created before it would look
        // two hours old and expired, and send the test off to refresh it with
        // Intuit over the network.
        $context = $this->context();
        Http::fake(fn () => Http::response('{"Fault":{}}', 500));
        $freshness = (new GetCompanySummary)->handle([], $context)['data_freshness'];

        $this->assertSame(
            now()->subHours(2)->toIso8601String(),
            $freshness['last_synced_at']
        );
    }
}
