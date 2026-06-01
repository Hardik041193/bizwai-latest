<?php

namespace App\Http\Controllers;

use App\Jobs\SyncQuickBooksDataJob;
use App\Models\QuickBooksAccount;
use App\Models\QuickBooksCustomer;
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
     * Return the Intuit OAuth authorization URL.
     * Any authenticated user may connect their own QuickBooks account.
     */
    public function connect(Request $request): JsonResponse
    {
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

        // Quick sync of company name only; full sync runs after client selection.
        try {
            $token = QuickBooksToken::where('user_id', $userId)->first();
            if ($token) {
                $this->quickBooks->syncCompanyInfo($token);
            }
        } catch (\Throwable $e) {
            Log::warning('QuickBooks company info sync after connect (non-fatal).', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }

        return redirect("{$frontendBase}/quickbooks/select-client");
    }

    /**
     * List QBO customers for the post-OAuth client picker (live from QuickBooks).
     */
    public function selectionClients(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        try {
            $clients = $this->quickBooks->fetchCustomersForSelection(
                $token,
                $request->input('search')
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'company_name' => $token->company_name ?? $token->legal_name,
            'realm_id'     => $token->realm_id,
            'clients'      => $clients,
        ]);
    }

    /**
     * Save the client chosen on the selection screen and start data sync.
     */
    public function saveClientSelection(Request $request): JsonResponse
    {
        $request->validate([
            'qbo_customer_id' => 'required|string|max:50',
            'display_name'    => 'required|string|max:255',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $token = $this->quickBooks->selectClient(
            $token,
            $request->input('qbo_customer_id'),
            $request->input('display_name')
        );

        dispatch(new SyncQuickBooksDataJob($token->id));

        return response()->json([
            'message' => 'Client selected successfully.',
            'selected_client' => [
                'qbo_id'       => $token->selected_client_qbo_id,
                'display_name' => $token->selected_client_name,
            ],
        ]);
    }

    /**
     * Clear client selection so the user can pick a different client.
     */
    public function clearClientSelection(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $token->update([
            'selected_client_qbo_id' => null,
            'selected_client_name'   => null,
            'client_selected_at'     => null,
        ]);

        return response()->json(['message' => 'Client selection cleared.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Status
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Connection status.
     *
     * Checks the authenticated user's own QuickBooks connection.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->quickBooksToken;

        if (! $token) {
            return response()->json([
                'connected' => false,
                'role'      => $user->role,
            ]);
        }

        return response()->json([
            'connected'                => true,
            'role'                     => $user->role,
            'realm_id'                 => $token->realm_id,
            'company_name'             => $token->company_name,
            'legal_name'               => $token->legal_name,
            'company_email'            => $token->company_email,
            'country'                  => $token->country,
            'needs_client_selection'   => ! $token->hasSelectedClient(),
            'selected_client'          => $token->hasSelectedClient() ? [
                'qbo_id'       => $token->selected_client_qbo_id,
                'display_name' => $token->selected_client_name,
            ] : null,
            'token_expires_at'         => $token->token_expires_at?->toIso8601String(),
            'refresh_token_expires_at' => $token->refresh_token_expires_at?->toIso8601String(),
            'access_token_expired'     => $token->isAccessTokenExpired(),
            'is_company_account'       => $user->isAdmin(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sync / Disconnect
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Trigger a manual sync for the authenticated user's own QBO account.
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
     * Revoke the authenticated user's own QBO connection.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $this->quickBooks->disconnect($request->user()->id);

        return response()->json(['message' => 'QuickBooks account disconnected successfully.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Data Endpoints — each user sees their own connected QuickBooks company
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve the QuickBooks realm token relevant to the current user.
     *
     * Every role resolves to its own connected QuickBooks token.
     */
    private function resolveToken(Request $request): ?QuickBooksToken
    {
        return $request->user()->quickBooksToken;
    }

    private function applySelectedClientToInvoices($query, QuickBooksToken $token): void
    {
        if ($token->hasSelectedClient()) {
            $query->where('customer_name', $token->selected_client_name);
        }
    }

    private function applySelectedClientToCustomers($query, QuickBooksToken $token): void
    {
        if (! $token->hasSelectedClient()) {
            return;
        }

        $name = $token->selected_client_name;

        $query->where(function ($q) use ($name) {
            $q->where('display_name', $name)
                ->orWhere('company_name', $name);
        });
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
     * Customers for the authenticated user's connected QuickBooks realm.
     */
    public function customers(Request $request): JsonResponse
    {
        $request->validate([
            'search'   => 'nullable|string|max:100',
            'active'   => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $customers = QuickBooksCustomer::where('realm_id', $token->realm_id);
        $this->applySelectedClientToCustomers($customers, $token);
        $customers = $customers
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where(function ($q2) use ($term) {
                    $q2->where('display_name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($request->filled('active'), fn ($q) => $q->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('display_name')
            ->paginate($request->integer('per_page', 25));

        return response()->json($customers);
    }

    /**
     * Invoices.
     *
     * Returns all invoices for the authenticated user's connected QBO realm.
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

        $invoices = QuickBooksInvoice::where('realm_id', $token->realm_id);
        $this->applySelectedClientToInvoices($invoices, $token);
        $invoices = $invoices
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_name', 'like', '%' . $request->customer . '%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('txn_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('txn_date', '<=', $request->to))
            ->orderByDesc('txn_date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($invoices);
    }

    /**
     * Transactions (Purchases/Expenses).
     *
     * Returns transactions for the authenticated user's connected QBO realm.
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
     * Financial summary.
     *
     * Summary for the authenticated user's connected QuickBooks company.
     */
    public function summary(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $realmId = $token->realm_id;

        $invoiceQuery = QuickBooksInvoice::where('realm_id', $realmId);
        $this->applySelectedClientToInvoices($invoiceQuery, $token);

        $txnQuery = QuickBooksTransaction::where('realm_id', $realmId);

        $customerCountQuery = QuickBooksCustomer::where('realm_id', $realmId);
        $this->applySelectedClientToCustomers($customerCountQuery, $token);

        $totalRevenue       = (clone $invoiceQuery)->where('status', 'Paid')->sum('total_amount');
        $outstandingBalance = (clone $invoiceQuery)->whereIn('status', ['Open', 'Overdue'])->sum('balance');
        $totalExpenses      = (clone $txnQuery)->sum('amount');
        $overdueCount       = (clone $invoiceQuery)->where('status', 'Overdue')->count();
        $invoiceTotal       = (clone $invoiceQuery)->sum('total_amount');

        return response()->json([
            'total_revenue'       => (float) $totalRevenue,
            'outstanding_balance' => (float) $outstandingBalance,
            'total_expenses'      => (float) $totalExpenses,
            'overdue_invoices'    => $overdueCount,
            'total_invoices'      => (clone $invoiceQuery)->count(),
            'total_customers'     => (clone $customerCountQuery)->count(),
            'selected_client'     => $token->hasSelectedClient() ? [
                'qbo_id'       => $token->selected_client_qbo_id,
                'display_name' => $token->selected_client_name,
            ] : null,
            'invoice_total'       => (float) $invoiceTotal,
            'last_synced_at'      => QuickBooksInvoice::where('realm_id', $realmId)->max('synced_at'),
            'company'             => [
                'name'        => $token->company_name,
                'legal_name'  => $token->legal_name,
                'email'       => $token->company_email,
                'country'     => $token->country,
                'realm_id'    => $token->realm_id,
            ],
            'is_personal'         => ! $request->user()->isAdmin(),
        ]);
    }
}
