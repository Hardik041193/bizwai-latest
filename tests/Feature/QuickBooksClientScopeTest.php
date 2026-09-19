<?php

namespace Tests\Feature;

use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Support\QuickBooksClientScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data scoping while the client match is still unresolved.
 *
 * Client matching moved out of the OAuth callback and into the background sync,
 * so there is now a window between connecting and that entity completing where
 * a token has no selection at all. The scope must fail closed in that window:
 * returning "no filter" would hand a client-scoped user the whole company's
 * financials.
 */
class QuickBooksClientScopeTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $realm, string $role = 'user'): QuickBooksToken
    {
        $user = User::factory()->create(['role' => $role]);

        return QuickBooksToken::create([
            'user_id' => $user->id,
            'realm_id' => $realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(100),
        ]);
    }

    private function invoice(string $realm, string $qboId, string $customer): void
    {
        QuickBooksInvoice::create([
            'realm_id' => $realm,
            'qbo_id' => $qboId,
            'customer_name' => $customer,
            'total_amount' => 1000,
            'balance' => 0,
        ]);
    }

    private function scopedCount(string $realm, QuickBooksToken $token): int
    {
        $query = QuickBooksInvoice::where('realm_id', $realm);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn(
            $query, $token, 'customer_qbo_id', 'customer_name'
        );

        return $query->count();
    }

    public function test_an_unresolved_scope_denies_a_non_admin_every_row(): void
    {
        $token = $this->token('REALM_SCOPE');
        $this->invoice('REALM_SCOPE', '900', 'Someone Else');

        $this->assertFalse($token->hasCompletedClientSelection());
        $this->assertSame(0, $this->scopedCount('REALM_SCOPE', $token));
    }

    public function test_an_unresolved_scope_still_lets_an_admin_see_everything(): void
    {
        $token = $this->token('REALM_SCOPE_A', 'admin');
        $this->invoice('REALM_SCOPE_A', '901', 'Anyone');

        $this->assertSame(1, $this->scopedCount('REALM_SCOPE_A', $token));
    }

    public function test_a_resolved_all_clients_selection_sees_everything(): void
    {
        $token = $this->token('REALM_SCOPE_ALL');
        $this->invoice('REALM_SCOPE_ALL', '902', 'Anyone');

        $token->update(['selected_clients' => [], 'client_selected_at' => now()]);

        $this->assertSame(1, $this->scopedCount('REALM_SCOPE_ALL', $token->fresh()));
    }

    public function test_a_resolved_specific_selection_filters_to_that_client(): void
    {
        $token = $this->token('REALM_SCOPE_ONE');
        $this->invoice('REALM_SCOPE_ONE', '903', 'Amy Bird');
        $this->invoice('REALM_SCOPE_ONE', '904', 'Someone Else');

        $token->update([
            'selected_clients' => [['qbo_id' => '10', 'name' => 'Amy Bird']],
            'client_selected_at' => now(),
        ]);

        $this->assertSame(1, $this->scopedCount('REALM_SCOPE_ONE', $token->fresh()));
    }
}
