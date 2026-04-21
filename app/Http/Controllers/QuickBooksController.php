<?php

namespace App\Http\Controllers;

use App\Jobs\SyncQuickBooksDataJob;
use App\Models\QuickBooksAccount;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransaction;
use App\Services\QuickBooksService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class QuickBooksController extends Controller
{
    public function __construct(private readonly QuickBooksService $quickBooks) {}

    // ──────────────────────────────────────────────────────────────────────
    // OAuth Flow
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return the Intuit OAuth authorization URL for the SPA to redirect to.
     * The SPA should do: window.location.href = response.data.url
     *
     * User ID is stored in Cache keyed by the OAuth state parameter so the
     * callback can identify who authorized — works with stateless Bearer-token SPA auth.
     */
    public function connect(Request $request): JsonResponse
    {
        $url = $this->quickBooks->getAuthorizationUrl($request->user()->id);

        return response()->json(['url' => $url]);
    }

    /**
     * Intuit redirects the browser here after the user grants permission.
     * This is a web route (not API) because Intuit performs a browser redirect.
     * On success, redirects the SPA to /quickbooks/connected.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendBase = rtrim(config('app.url'), '/');

        if ($request->has('error')) {
            $error = urlencode($request->input('error_description', $request->input('error')));
            return redirect("{$frontendBase}/quickbooks/error?message={$error}");
        }

        $code    = $request->input('code');
        $realmId = $request->input('realmId');
        $state   = $request->input('state', '');

        // Recover user ID from Cache (written during connect() via the OAuth state key).
        // Falls back to Auth::id() only when session-based login is active (e.g. testing).
        $userId = Cache::pull("qb_oauth_state_{$state}") ?? Auth::id();

        if (! $userId) {
            return redirect("{$frontendBase}/quickbooks/error?message=unauthenticated");
        }

        try {
            $this->quickBooks->exchangeCodeForTokens($code, $realmId, $state, $userId);
        } catch (RuntimeException $e) {
            Log::error('QuickBooks callback error', ['error' => $e->getMessage(), 'user_id' => $userId]);
            $message = urlencode($e->getMessage());
            return redirect("{$frontendBase}/quickbooks/error?message={$message}");
        }

        // Trigger an initial background sync — wrapped so a sync failure
        // never prevents the success redirect from reaching the user.
        try {
            $token = QuickBooksToken::where('user_id', $userId)->first();
            if ($token) {
                dispatch(new SyncQuickBooksDataJob($token->id));
            }
        } catch (\Throwable $e) {
            Log::warning('QuickBooks initial sync failed after connect (non-fatal).', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }

        return redirect("{$frontendBase}/quickbooks/connected");
    }

    /**
     * Return the connection status for the authenticated user.
     */
    public function status(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected'                 => true,
            'realm_id'                  => $token->realm_id,
            'token_expires_at'          => $token->token_expires_at?->toIso8601String(),
            'refresh_token_expires_at'  => $token->refresh_token_expires_at?->toIso8601String(),
            'access_token_expired'      => $token->isAccessTokenExpired(),
        ]);
    }

    /**
     * Trigger a manual sync for the authenticated user's QBO account.
     * Rate-limited to 5 requests per minute via route definition.
     */
    public function sync(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks account is not connected.'], 422);
        }

        dispatch(new SyncQuickBooksDataJob($token->id));

        return response()->json(['message' => 'Sync has been queued successfully.']);
    }

    /**
     * Revoke the token and remove the QBO connection for the authenticated user.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $this->quickBooks->disconnect($request->user()->id);

        return response()->json(['message' => 'QuickBooks account disconnected successfully.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Data Endpoints
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return paginated list of synced Chart of Accounts.
     */
    public function accounts(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks account is not connected.'], 422);
        }

        $accounts = QuickBooksAccount::where('realm_id', $token->realm_id)
            ->when($request->filled('type'), fn ($q) => $q->where('account_type', $request->type))
            ->when($request->filled('active'), fn ($q) => $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return response()->json($accounts);
    }

    /**
     * Return paginated list of synced Invoices.
     */
    public function invoices(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks account is not connected.'], 422);
        }

        $invoices = QuickBooksInvoice::where('realm_id', $token->realm_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_name', 'like', '%' . $request->customer . '%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('txn_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('txn_date', '<=', $request->to))
            ->orderByDesc('txn_date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($invoices);
    }

    /**
     * Return paginated list of synced Transactions.
     */
    public function transactions(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks account is not connected.'], 422);
        }

        $transactions = QuickBooksTransaction::where('realm_id', $token->realm_id)
            ->when($request->filled('type'), fn ($q) => $q->where('txn_type', $request->type))
            ->when($request->filled('account'), fn ($q) => $q->where('account_name', 'like', '%' . $request->account . '%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('txn_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('txn_date', '<=', $request->to))
            ->orderByDesc('txn_date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($transactions);
    }

    /**
     * Return high-level financial summary stats for the SPA dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks account is not connected.'], 422);
        }

        $realmId = $token->realm_id;

        $totalRevenue = QuickBooksInvoice::where('realm_id', $realmId)
            ->where('status', 'Paid')
            ->sum('total_amount');

        $outstandingBalance = QuickBooksInvoice::where('realm_id', $realmId)
            ->where('status', '!=', 'Paid')
            ->sum('balance');

        $totalExpenses = QuickBooksTransaction::where('realm_id', $realmId)
            ->sum('amount');

        $overdueCount = QuickBooksInvoice::where('realm_id', $realmId)
            ->where('status', 'Overdue')
            ->count();

        return response()->json([
            'total_revenue'       => (float) $totalRevenue,
            'outstanding_balance' => (float) $outstandingBalance,
            'total_expenses'      => (float) $totalExpenses,
            'overdue_invoices'    => $overdueCount,
            'last_synced_at'      => QuickBooksInvoice::where('realm_id', $realmId)
                ->max('synced_at'),
        ]);
    }
}
