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
    // OAuth Flow  (admin only)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return the Intuit OAuth authorization URL.
     * Only admin users may connect a QuickBooks account.
     */
    public function connect(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Only administrators can connect QuickBooks.'], 403);
        }

        $url = $this->quickBooks->getAuthorizationUrl($request->user()->id);

        return response()->json(['url' => $url]);
    }

    /**
     * Intuit redirects the browser here after the user grants permission.
     * Web route — Intuit performs a browser redirect here.
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

        // Security: a missing/empty state parameter means CSRF — reject immediately.
        if (empty($state)) {
            Log::warning('QuickBooks callback: empty state parameter — possible CSRF attempt.', [
                'ip' => $request->ip(),
            ]);
            return redirect("{$frontendBase}/quickbooks/error?message=invalid_state");
        }

        // Recover user ID from the cache entry written by connect().
        // We intentionally do NOT fall back to Auth::id() — the state MUST match
        // what we stored so that a forged callback cannot be linked to an active session.
        $userId = Cache::pull("qb_oauth_state_{$state}");

        if (! $userId) {
            Log::warning('QuickBooks callback: state not found in cache — expired or forged.', [
                'state' => $state,
                'ip'    => $request->ip(),
            ]);
            return redirect("{$frontendBase}/quickbooks/error?message=unauthenticated");
        }

        try {
            $this->quickBooks->exchangeCodeForTokens($code, $realmId, $state, $userId);
        } catch (RuntimeException $e) {
            Log::error('QuickBooks callback error', ['error' => $e->getMessage(), 'user_id' => $userId]);
            $message = urlencode($e->getMessage());
            return redirect("{$frontendBase}/quickbooks/error?message={$message}");
        }

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

    // ──────────────────────────────────────────────────────────────────────
    // Status
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Connection status.
     *
     * Admin  → checks their own token and returns token metadata.
     * User   → checks if any admin has connected (company connection).
     *          Returns connected=true if the company has QBO, so the user
     *          knows their invoice data is available.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $token = $user->quickBooksToken;

            if (! $token) {
                return response()->json(['connected' => false, 'role' => 'admin']);
            }

            return response()->json([
                'connected'                => true,
                'role'                     => 'admin',
                'realm_id'                 => $token->realm_id,
                'token_expires_at'         => $token->token_expires_at?->toIso8601String(),
                'refresh_token_expires_at' => $token->refresh_token_expires_at?->toIso8601String(),
                'access_token_expired'     => $token->isAccessTokenExpired(),
            ]);
        }

        // Regular user — check for any admin's company token.
        $companyToken = QuickBooksToken::getCompanyToken();

        return response()->json([
            'connected'          => (bool) $companyToken,
            'role'               => 'user',
            'is_company_account' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sync / Disconnect  (admin only)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Trigger a manual sync for the authenticated admin's QBO account.
     */
    public function sync(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Only administrators can trigger a sync.'], 403);
        }

        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks account is not connected.'], 422);
        }

        dispatch(new SyncQuickBooksDataJob($token->id));

        return response()->json(['message' => 'Sync has been queued successfully.']);
    }

    /**
     * Revoke the QBO connection — admin only.
     */
    public function disconnect(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Only administrators can disconnect QuickBooks.'], 403);
        }

        $this->quickBooks->disconnect($request->user()->id);

        return response()->json(['message' => 'QuickBooks account disconnected successfully.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Data Endpoints — admin sees everything, users see their own records
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve the QuickBooks realm token relevant to the current user.
     *
     * Admin  → their own connected token.
     * User   → the company (admin) token.
     */
    private function resolveToken(Request $request): ?QuickBooksToken
    {
        $user = $request->user();

        return $user->isAdmin()
            ? $user->quickBooksToken
            : QuickBooksToken::getCompanyToken();
    }

    /**
     * Chart of Accounts — admin only.
     * Accounts are internal business data; regular users should not see them.
     */
    public function accounts(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

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
     * Invoices.
     *
     * Admin  → all invoices for their realm (with optional filters).
     * User   → only invoices where customer_email matches their account email,
     *           pulled from the company's (admin's) realm.
     */
    public function invoices(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => 'nullable|in:Open,Paid,Overdue',
            'customer' => 'nullable|string|max:100',
            'from'     => 'nullable|date_format:Y-m-d',
            'to'       => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $user    = $request->user();
        $isAdmin = $user->isAdmin();

        $invoices = QuickBooksInvoice::where('realm_id', $token->realm_id)
            ->when(! $isAdmin, fn ($q) => $q->where('customer_email', $user->email))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($isAdmin && $request->filled('customer'), fn ($q) => $q->where('customer_name', 'like', '%' . $request->customer . '%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('txn_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('txn_date', '<=', $request->to))
            ->orderByDesc('txn_date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($invoices);
    }

    /**
     * Transactions (Purchases/Expenses).
     *
     * Admin  → all transactions for their realm.
     * User   → transactions where entity_name matches their name.
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'type'     => 'nullable|string|max:50',
            'account'  => 'nullable|string|max:100',
            'from'     => 'nullable|date_format:Y-m-d',
            'to'       => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $user    = $request->user();
        $isAdmin = $user->isAdmin();

        $transactions = QuickBooksTransaction::where('realm_id', $token->realm_id)
            ->when(! $isAdmin, fn ($q) => $q->where('entity_name', 'like', '%' . $user->name . '%'))
            ->when($request->filled('type'), fn ($q) => $q->where('txn_type', $request->type))
            ->when($isAdmin && $request->filled('account'), fn ($q) => $q->where('account_name', 'like', '%' . $request->account . '%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('txn_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('txn_date', '<=', $request->to))
            ->orderByDesc('txn_date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($transactions);
    }

    /**
     * Financial summary.
     *
     * Admin  → full company summary (all customers).
     * User   → personal summary (their own invoices only).
     */
    public function summary(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $user    = $request->user();
        $isAdmin = $user->isAdmin();
        $realmId = $token->realm_id;

        $invoiceQuery = QuickBooksInvoice::where('realm_id', $realmId);
        $txnQuery     = QuickBooksTransaction::where('realm_id', $realmId);

        // Scope non-admin queries to their own records.
        if (! $isAdmin) {
            $invoiceQuery->where('customer_email', $user->email);
            $txnQuery->where('entity_name', 'like', '%' . $user->name . '%');
        }

        $totalRevenue       = (clone $invoiceQuery)->where('status', 'Paid')->sum('total_amount');
        $outstandingBalance = (clone $invoiceQuery)->whereIn('status', ['Open', 'Overdue'])->sum('balance');
        $totalExpenses      = (clone $txnQuery)->sum('amount');
        $overdueCount       = (clone $invoiceQuery)->where('status', 'Overdue')->count();

        return response()->json([
            'total_revenue'       => (float) $totalRevenue,
            'outstanding_balance' => (float) $outstandingBalance,
            'total_expenses'      => (float) $totalExpenses,
            'overdue_invoices'    => $overdueCount,
            'last_synced_at'      => QuickBooksInvoice::where('realm_id', $realmId)->max('synced_at'),
            'is_personal'         => ! $isAdmin,
        ]);
    }
}
