<?php

namespace Tests\Feature;

use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\GetInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Client scoping in the AI tools.
 *
 * The controller's scope was made to fail closed in R1b: until a user's client
 * match resolves, a non-admin sees nothing. The AI tools carry their own copy of
 * that logic, and it must behave the same, or the chat becomes a way round it.
 */
class QuickBooksAiScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_AI_SCOPE';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['900', '10', "Amy's Bird Sanctuary"], ['901', '20', 'Someone Else']] as [$id, $customerId, $name]) {
            DB::table('quickbooks_invoices')->insert([
                'realm_id' => $this->realm, 'qbo_id' => $id, 'customer_qbo_id' => $customerId,
                'customer_name' => $name, 'total_amount' => 100, 'balance' => 0, 'status' => 'Paid',
                'txn_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, array{qbo_id: string, name: string}>|null  $clients  null = scope not resolved
     */
    private function user(string $role, ?array $clients): User
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

        return $user->fresh();
    }

    private function invoicesVisibleTo(User $user): int
    {
        return (new GetInvoices)->handle([], QuickBooksAiContext::forUser($user))['total_count'];
    }

    public function test_an_unresolved_scope_denies_a_non_admin_every_row(): void
    {
        $this->assertSame(0, $this->invoicesVisibleTo($this->user('user', null)));
    }

    public function test_an_unresolved_scope_still_lets_an_admin_see_everything(): void
    {
        $this->assertSame(2, $this->invoicesVisibleTo($this->user('admin', null)));
    }

    public function test_a_resolved_all_clients_selection_sees_everything(): void
    {
        $this->assertSame(2, $this->invoicesVisibleTo($this->user('user', [])));
    }

    public function test_a_specific_selection_sees_only_that_client(): void
    {
        $this->assertSame(1, $this->invoicesVisibleTo($this->user('user', [['qbo_id' => '10', 'name' => "Amy's Bird Sanctuary"]])));
    }
}
