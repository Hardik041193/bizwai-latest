<?php

namespace Tests\Feature;

use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksToken;
use App\Models\User;
use App\Services\QuickBooksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * STARTPOSITION paging.
 *
 * QuickBooks caps a response at 1000 records. Every query used to append its own
 * MAXRESULTS and keep whatever came back, so a realm with more than 1000 of
 * anything was silently truncated: no error, just missing rows and wrong totals.
 */
class QuickBooksPaginationTest extends TestCase
{
    use RefreshDatabase;

    private string $realm = 'REALM_PAGE';

    protected function setUp(): void
    {
        parent::setUp();
        // Small pages keep the fixture readable; the paging logic is identical.
        config(['quickbooks.max_results' => 2]);
    }

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
     * Serve $total customers, $pageSize at a time, honouring STARTPOSITION.
     *
     * @return array{requests: \Illuminate\Support\Collection}
     */
    private function fakePagedCustomers(int $total, int $pageSize): object
    {
        $seen = collect();

        Http::fake(function (Request $request) use ($total, $pageSize, $seen) {
            $url = urldecode($request->url());

            if (! str_contains($url, 'FROM Customer')) {
                return Http::response(['QueryResponse' => []]);
            }

            preg_match('/STARTPOSITION (\d+)/', $url, $m);
            $start = (int) ($m[1] ?? 1);
            $seen->push($start);

            $rows = [];
            for ($i = $start; $i < $start + $pageSize && $i <= $total; $i++) {
                $rows[] = [
                    'Id' => (string) $i,
                    'DisplayName' => "Customer {$i}",
                    'PrimaryEmailAddr' => ['Address' => "c{$i}@example.test"],
                    'Balance' => 10,
                    'Active' => true,
                ];
            }

            return Http::response(['QueryResponse' => ['Customer' => $rows]]);
        });

        return (object) ['starts' => $seen];
    }

    public function test_it_follows_pages_until_a_short_page_arrives(): void
    {
        $probe = $this->fakePagedCustomers(total: 5, pageSize: 2);

        $synced = app(QuickBooksService::class)->syncCustomers($this->token());

        // Without paging this would have stopped at 2.
        $this->assertSame(5, $synced);
        $this->assertSame(5, QuickBooksCustomer::where('realm_id', $this->realm)->count());

        // Pages 1,3,5 are full or partial; 5 returns one row, which ends it.
        $this->assertSame([1, 3, 5], $probe->starts->all());
    }

    public function test_an_exact_multiple_of_the_page_size_needs_one_extra_empty_page(): void
    {
        // 4 records at 2 per page: the second page is full, so the loop cannot
        // know it is done until a third, empty page comes back.
        $probe = $this->fakePagedCustomers(total: 4, pageSize: 2);

        $synced = app(QuickBooksService::class)->syncCustomers($this->token());

        $this->assertSame(4, $synced);
        $this->assertSame([1, 3, 5], $probe->starts->all());
    }

    public function test_a_single_short_page_makes_exactly_one_request(): void
    {
        $probe = $this->fakePagedCustomers(total: 1, pageSize: 2);

        $this->assertSame(1, app(QuickBooksService::class)->syncCustomers($this->token()));
        $this->assertSame([1], $probe->starts->all());
    }

    public function test_an_empty_result_makes_one_request_and_stores_nothing(): void
    {
        $probe = $this->fakePagedCustomers(total: 0, pageSize: 2);

        $this->assertSame(0, app(QuickBooksService::class)->syncCustomers($this->token()));
        $this->assertSame(0, QuickBooksCustomer::where('realm_id', $this->realm)->count());
        $this->assertSame([1], $probe->starts->all());
    }

    public function test_the_page_size_is_capped_at_the_quickbooks_limit(): void
    {
        config(['quickbooks.max_results' => 5000]);
        $captured = null;

        Http::fake(function (Request $request) use (&$captured) {
            $captured = urldecode($request->url());

            return Http::response(['QueryResponse' => ['Customer' => []]]);
        });

        app(QuickBooksService::class)->syncCustomers($this->token());

        $this->assertStringContainsString('MAXRESULTS 1000', $captured);
        $this->assertStringNotContainsString('MAXRESULTS 5000', $captured);
    }

    public function test_callers_do_not_add_their_own_maxresults(): void
    {
        // Two MAXRESULTS clauses would be invalid IQL, so paging must be the
        // only thing appending one.
        $captured = null;

        Http::fake(function (Request $request) use (&$captured) {
            $captured = urldecode($request->url());

            return Http::response(['QueryResponse' => ['Account' => []]]);
        });

        app(QuickBooksService::class)->syncAccounts($this->token());

        $this->assertSame(1, substr_count($captured, 'MAXRESULTS'));
        $this->assertSame(1, substr_count($captured, 'STARTPOSITION'));
    }
}
