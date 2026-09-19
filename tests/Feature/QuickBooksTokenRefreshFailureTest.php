<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Jobs\SyncQuickBooksEntityJob;
use App\Models\QuickBooksSyncState as State;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\GetProfitAndLoss;
use App\Services\QuickBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use QuickBooksOnline\API\Exception\ServiceException;
use RuntimeException;
use Tests\TestCase;

/**
 * What happens when Intuit refuses to refresh an access token.
 *
 * The SDK throws ServiceException, which extends \Exception rather than
 * RuntimeException, so it used to slip past every caller's error handling. The
 * refusal here is the one a live realm returned: HTTP 400, invalid_grant.
 */
class QuickBooksTokenRefreshFailureTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_REFRESH';

    private const REJECTION = 'Refresh OAuth 2 Access token with Refresh Token failed. Body: [{"error":"invalid_grant","error_description":"Incorrect or invalid refresh token"}].';

    /**
     * A connection whose access token has expired, so using it needs a refresh.
     */
    private function expiredAccessToken(string $role = 'admin'): QuickBooksToken
    {
        $token = QuickBooksToken::create([
            'user_id' => User::factory()->create(['role' => $role])->id,
            'realm_id' => $this->realm,
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->subHour(),
            'refresh_token_expires_at' => now()->addDays(100),
            'selected_clients' => [],
            'client_selected_at' => now(),
        ]);

        // Past the grace period in which a newly issued token is trusted as is.
        DB::table('quickbooks_tokens')->where('id', $token->id)->update(['created_at' => now()->subHours(2)]);

        return $token->fresh();
    }

    /**
     * Intuit's side of the refresh, answered with $exception, called at most $times.
     */
    private function intuitRefuses(ServiceException $exception, int $times = 1): void
    {
        $this->partialMock(QuickBooksService::class, function ($mock) use ($exception, $times) {
            $mock->shouldAllowMockingProtectedMethods()
                ->shouldReceive('requestTokenRefresh')
                ->times($times)
                ->andThrow($exception);
        });
    }

    public function test_a_rejected_refresh_requires_reconnecting_and_is_not_asked_again(): void
    {
        // Once only: the second use must stop before reaching Intuit.
        $this->intuitRefuses(new ServiceException(self::REJECTION, 400), times: 1);
        $token = $this->expiredAccessToken();
        $service = app(QuickBooksService::class);

        try {
            $service->refreshTokenIfNeeded($token);
            $this->fail('A rejected refresh should require reauthorization.');
        } catch (QuickBooksReauthorizationRequired $e) {
            $this->assertStringContainsString('reconnect', $e->getMessage());
        }

        $this->assertTrue($token->fresh()->isRefreshTokenExpired());

        $this->expectException(QuickBooksReauthorizationRequired::class);
        $service->refreshTokenIfNeeded($token->fresh());
    }

    public function test_an_outage_is_not_mistaken_for_a_rejection(): void
    {
        $this->intuitRefuses(new ServiceException('Refresh OAuth 2 Access token with Refresh Token failed. Body: [Service Unavailable].', 503));
        $token = $this->expiredAccessToken();

        try {
            app(QuickBooksService::class)->refreshTokenIfNeeded($token);
            $this->fail('An outage should raise a runtime error.');
        } catch (QuickBooksReauthorizationRequired $e) {
            $this->fail('An outage must not be reported as needing reauthorization.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refresh failed', $e->getMessage());
        }

        // The connection may still work next time, so it is left alone.
        $this->assertFalse($token->fresh()->isRefreshTokenExpired());
    }

    public function test_the_dashboard_summary_asks_for_a_reconnect_instead_of_failing(): void
    {
        Http::fake();
        $this->intuitRefuses(new ServiceException(self::REJECTION, 400));
        $token = $this->expiredAccessToken();
        Sanctum::actingAs($token->user);

        $this->getJson('/api/quickbooks/summary')
            ->assertOk()
            ->assertJsonPath('total_revenue', null)
            ->assertJsonPath('figures_error', 'quickbooks_reconnect_required');
    }

    public function test_an_ai_tool_asks_for_a_reconnect_instead_of_failing(): void
    {
        Http::fake();
        $this->intuitRefuses(new ServiceException(self::REJECTION, 400));
        $token = $this->expiredAccessToken();

        $result = (new GetProfitAndLoss)->handle(['period' => 'this_year'], QuickBooksAiContext::forUser($token->user));

        $this->assertSame('quickbooks_reconnect_required', $result['error']);
    }

    public function test_a_sync_stops_at_once_instead_of_retrying_a_refusal(): void
    {
        Http::fake();
        $this->intuitRefuses(new ServiceException(self::REJECTION, 400));
        $token = $this->expiredAccessToken();
        State::markQueued($this->realm);

        (new SyncQuickBooksEntityJob($token->id, 'accounts'))->handle(app(QuickBooksService::class));

        $this->assertSame(
            0,
            State::where('realm_id', $this->realm)->where('status', '!=', State::STATUS_FAILED)->count(),
            'every step of the run should be closed out'
        );
        Http::assertNothingSent();
    }
}
