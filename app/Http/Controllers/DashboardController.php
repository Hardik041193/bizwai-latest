<?php

namespace App\Http\Controllers;

use App\Models\ContactUs;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    /**
     * A QuickBooks company is considered stale once its newest synced record
     * is older than this many days.
     */
    private const SYNC_STALE_DAYS = 7;

    /**
     * Guard: only admins may access any action in this controller.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Admin access required.');
        }
    }

    /**
     * Headline user counts for the admin dashboard cards.
     *
     * GET /api/admin/dashboard/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Exclude admin role from all counts
        $baseQuery = User::where('role', '!=', 'admin');

        $totalUsers    = (clone $baseQuery)->count();

        // Active Users = status 1 (Approved)
        $activeUsers   = (clone $baseQuery)->where('status', 1)->count();

        // QBO Active Users = qbo_status 1 (Connected)
        $qboActive     = (clone $baseQuery)->where('qbo_status', 1)->count();

        // Pending Verification = email_verified_at is NULL
        $pendingVerif  = (clone $baseQuery)->whereNull('email_verified_at')->count();

        return response()->json([
            'total_users'   => $totalUsers,
            'active_users'  => $activeUsers,
            'qbo_active'    => $qboActive,
            'pending_verif' => $pendingVerif,
        ]);
    }

    /**
     * Chart data for the admin dashboard: user growth trend, platform health
     * score and the quick-insight rail. Everything here describes the
     * platform itself, never a customer's financials.
     *
     * GET /api/admin/dashboard/charts?months=12
     */
    public function charts(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'months' => ['nullable', 'integer', 'min:3', 'max:24'],
        ]);

        $months      = $request->integer('months', 12);
        $windowEnd   = Carbon::now()->endOfMonth();
        $windowStart = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $signupsByMonth     = $this->monthlySignups($windowStart, $windowEnd);
        $connectionsByMonth = $this->monthlyConnections($windowStart, $windowEnd);

        $labels      = [];
        $signups     = [];
        $connections = [];

        for ($cursor = $windowStart->copy(); $cursor->lte($windowEnd); $cursor->addMonth()) {
            $period = $cursor->format('Y-m');

            $labels[]      = $cursor->format('M Y');
            $signups[]     = (int) ($signupsByMonth[$period] ?? 0);
            $connections[] = (int) ($connectionsByMonth[$period] ?? 0);
        }

        return response()->json([
            'months'         => $months,
            'user_growth'    => [
                'labels'      => $labels,
                'signups'     => $signups,
                'connections' => $connections,
            ],
            'health_score'   => $this->healthScore(),
            'quick_insights' => $this->quickInsights(),
        ]);
    }

    /**
     * New non-admin signups grouped by YYYY-MM, keyed by period.
     */
    private function monthlySignups(Carbon $from, Carbon $to): Collection
    {
        return User::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as period, COUNT(*) as total")
            ->where('role', '!=', 'admin')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('period')
            ->pluck('total', 'period');
    }

    /**
     * New QuickBooks connections grouped by YYYY-MM, keyed by period.
     */
    private function monthlyConnections(Carbon $from, Carbon $to): Collection
    {
        return QuickBooksToken::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as period, COUNT(*) as total")
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->groupBy('period')
            ->pluck('total', 'period');
    }

    /**
     * Composite 0-100 platform health score built from three live signals:
     * how many users have QuickBooks connected, how many have verified their
     * email, and how many connected companies still sync on schedule.
     *
     * @return array{score:int, label:string, breakdown:array<string,int>}
     */
    private function healthScore(): array
    {
        $totalUsers = User::where('role', '!=', 'admin')->count();

        // Adoption: share of users with a live QuickBooks connection.
        $adoption = $totalUsers > 0
            ? User::where('role', '!=', 'admin')->where('qbo_status', 1)->count() / $totalUsers
            : 0.0;

        // Verification: share of users who confirmed their email address.
        $verification = $totalUsers > 0
            ? User::where('role', '!=', 'admin')->whereNotNull('email_verified_at')->count() / $totalUsers
            : 0.0;

        // Sync freshness: share of connected companies synced recently.
        $connectedRealms = $this->connectedRealms();
        $staleRealms     = $this->staleRealms($connectedRealms);

        $freshness = $connectedRealms->isNotEmpty()
            ? (count($connectedRealms) - count($staleRealms)) / count($connectedRealms)
            : 0.0;

        $score = (int) round(100 * (0.40 * $adoption + 0.30 * $verification + 0.30 * $freshness));

        return [
            'score'     => $score,
            'label'     => $this->healthLabel($score),
            'breakdown' => [
                'adoption'     => (int) round($adoption * 100),
                'verification' => (int) round($verification * 100),
                'freshness'    => (int) round($freshness * 100),
            ],
        ];
    }

    /**
     * Plain-English reading of the health score.
     */
    private function healthLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Healthy',
            $score >= 60 => 'Good - Watch adoption',
            $score >= 40 => 'Needs attention',
            default      => 'At risk',
        };
    }

    /**
     * Platform counters for the quick-insight rail.
     *
     * @return array<string, int>
     */
    private function quickInsights(): array
    {
        $connectedRealms = $this->connectedRealms();

        return [
            'new_users_30d'       => User::where('role', '!=', 'admin')
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->count(),
            'unread_messages'     => ContactUs::where('status', 'unread')->count(),
            'connected_companies' => $connectedRealms->count(),
            'stale_syncs'         => count($this->staleRealms($connectedRealms)),
        ];
    }

    /**
     * Realm ids of every company currently connected to QuickBooks.
     *
     * @return Collection<int, string>
     */
    private function connectedRealms(): Collection
    {
        return QuickBooksToken::query()->distinct()->pluck('realm_id');
    }

    /**
     * Realm ids that have not pulled fresh QuickBooks data recently, either
     * because they never synced or because the last sync has aged out.
     *
     * @param  Collection<int, string>  $realmIds
     * @return array<int, string>
     */
    private function staleRealms(Collection $realmIds): array
    {
        if ($realmIds->isEmpty()) {
            return [];
        }

        $cutoff = Carbon::now()->subDays(self::SYNC_STALE_DAYS);

        $lastSyncedAt = QuickBooksInvoice::query()
            ->selectRaw('realm_id, MAX(synced_at) as last_synced_at')
            ->whereIn('realm_id', $realmIds)
            ->groupBy('realm_id')
            ->pluck('last_synced_at', 'realm_id');

        return $realmIds
            ->filter(function (string $realmId) use ($lastSyncedAt, $cutoff) {
                $syncedAt = $lastSyncedAt[$realmId] ?? null;

                return $syncedAt === null || Carbon::parse($syncedAt)->lt($cutoff);
            })
            ->values()
            ->all();
    }
}
