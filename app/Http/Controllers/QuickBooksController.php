<?php

namespace App\Http\Controllers;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Jobs\SyncQuickBooksDataJob;
use App\Models\QuickBooksAccount;
use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksPayment;
use App\Models\QuickBooksSalesReceipt;
use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransaction;
use App\Services\QuickBooksReports;
use App\Services\QuickBooksService;
use App\Support\QuickBooksClientScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $code = $request->input('code');
        $realmId = $request->input('realmId');
        $state = $request->input('state', '');

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
                'ip' => $request->ip(),
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

        // Nothing else runs inline. Company info and client matching used to be
        // called here, but they are QuickBooks API calls sitting inside Intuit's
        // browser redirect, before any page of ours has loaded, so there is no
        // spinner that can cover them. Client matching in particular queried up
        // to 1000 customers. Both are now tracked entities of the background
        // sync, behind the progress bar on /quickbooks/connected.
        $token = QuickBooksToken::where('user_id', $userId)->first();

        if ($token) {
            // Dispatched here rather than by the frontend so that closing the
            // tab during the redirect no longer skips the sync entirely.
            QuickBooksSyncState::markQueued($token->realm_id);
            dispatch(new SyncQuickBooksDataJob($token->id));
        }

        return redirect("{$frontendBase}/quickbooks/connected");
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
            'realm_id' => $token->realm_id,
            'clients' => $clients,
        ]);
    }

    /**
     * Save the client chosen on the selection screen and start data sync.
     */
    public function saveClientSelection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'select_all' => 'sometimes|boolean',
            'clients' => 'array',
            'clients.*.qbo_id' => 'required|string|max:50',
            'clients.*.display_name' => 'required|string|max:255',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $selectAll = (bool) ($validated['select_all'] ?? false);

        $clients = $selectAll ? [] : array_map(fn ($c) => [
            'qbo_id' => $c['qbo_id'],
            'name' => $c['display_name'],
        ], $validated['clients'] ?? []);

        if (! $selectAll && count($clients) === 0) {
            return response()->json([
                'message' => 'Please select at least one client, or choose all clients.',
            ], 422);
        }

        $token = $this->quickBooks->selectClients($token, $clients);

        dispatch(new SyncQuickBooksDataJob($token->id));

        return response()->json([
            'message' => 'Client selection saved successfully.',
            'all_clients' => $token->isAllClientsSelected(),
            'selected_clients' => $token->selectedClients(),
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
            'selected_client_name' => null,
            'selected_clients' => null,
            'client_selected_at' => null,
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
                'role' => $user->role,
            ]);
        }

        return response()->json([
            'connected' => true,
            'role' => $user->role,
            'realm_id' => $token->realm_id,
            'company_name' => $token->company_name,
            'legal_name' => $token->legal_name,
            'company_email' => $token->company_email,
            'country' => $token->country,
            'needs_client_selection' => ! $token->hasCompletedClientSelection(),
            'all_clients' => $token->isAllClientsSelected(),
            'selected_clients' => $token->selectedClients(),
            'selected_client' => $token->hasSelectedClient() ? [
                'qbo_id' => $token->selected_client_qbo_id,
                'display_name' => $token->selected_client_name,
            ] : null,
            'token_expires_at' => $token->token_expires_at?->toIso8601String(),
            'refresh_token_expires_at' => $token->refresh_token_expires_at?->toIso8601String(),
            'access_token_expired' => $token->isAccessTokenExpired(),
            'is_company_account' => $user->isAdmin(),
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

        // A run is already in flight: report it rather than start another.
        // Overlapping runs are data-safe (every write is an upsert) but they
        // double the metered QuickBooks read calls for nothing.
        if (QuickBooksSyncState::isInProgress($token->realm_id)) {
            return response()->json([
                'message' => 'A sync is already running.',
                'progress' => QuickBooksSyncState::progressFor($token->realm_id),
            ]);
        }

        // Seed the state rows here, in the request, not in the job. The job may
        // not be picked up by a worker for a second or two, and in that gap the
        // frontend's first progress poll would otherwise read the previous
        // run's "complete" rows and clear its spinner on a sync that has not
        // begun.
        QuickBooksSyncState::markQueued($token->realm_id);

        dispatch(new SyncQuickBooksDataJob($token->id));

        return response()->json([
            'message' => 'Sync has been queued successfully.',
            'progress' => QuickBooksSyncState::progressFor($token->realm_id),
        ]);
    }

    /**
     * Per-entity sync progress for the authenticated user's realm.
     *
     * Polled by the frontend while a sync runs. Cheap by design (one indexed
     * read of at most one row per entity) because it is hit every couple of
     * seconds during onboarding.
     */
    public function syncProgress(Request $request): JsonResponse
    {
        $token = $request->user()->quickBooksToken;

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        return response()->json(array_merge(
            ['realm_id' => $token->realm_id],
            QuickBooksSyncState::progressFor($token->realm_id)
        ));
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
     * Profit and loss to date for the token's scope, or why it is missing.
     *
     * A failed report must not take the dashboard down: the caller still returns
     * the rest of the summary, and the reason says why these figures are absent.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function profitAndLossToDate(QuickBooksToken $token): array
    {
        return $this->profitAndLossForPeriod($token, Carbon::parse(QuickBooksReports::ALL_TIME_START), Carbon::now());
    }

    /**
     * Profit and loss for an arbitrary period, or why it is missing. Shares
     * the client-access and error handling with profitAndLossToDate() so
     * every caller reports the same reasons for missing figures.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function profitAndLossForPeriod(QuickBooksToken $token, Carbon $start, Carbon $end): array
    {
        $customers = QuickBooksClientScope::reportCustomersForToken($token);

        if ($customers === null) {
            return [null, 'client_access_pending'];
        }

        try {
            return [app(QuickBooksReports::class)->profitAndLoss($token, $start, $end, $customers), null];
        } catch (QuickBooksReauthorizationRequired $e) {
            return [null, 'quickbooks_reconnect_required'];
        } catch (RuntimeException $e) {
            Log::warning('QuickBooks report unavailable for the dashboard summary.', [
                'realm_id' => $token->realm_id,
                'error' => $e->getMessage(),
            ]);

            return [null, 'quickbooks_report_unavailable'];
        }
    }

    /**
     * Revenue/profit trend for a period, or why it is missing.
     *
     * @return array{0: array<int, array{label: string, revenue: float, profit: float}>|null, 1: string|null}
     */
    private function trendFor(QuickBooksToken $token, Carbon $start, Carbon $end, string $summarizeBy): array
    {
        $customers = QuickBooksClientScope::reportCustomersForToken($token);

        if ($customers === null) {
            return [null, 'client_access_pending'];
        }

        try {
            return [app(QuickBooksReports::class)->trend($token, $start, $end, $summarizeBy, $customers), null];
        } catch (QuickBooksReauthorizationRequired $e) {
            return [null, 'quickbooks_reconnect_required'];
        } catch (RuntimeException $e) {
            Log::warning('QuickBooks trend report unavailable.', [
                'realm_id' => $token->realm_id,
                'error' => $e->getMessage(),
            ]);

            return [null, 'quickbooks_report_unavailable'];
        }
    }

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
        // Filters by customer_qbo_id (rename-proof) with a name fallback for
        // rows synced before that column existed. See QuickBooksClientScope.
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($query, $token, 'customer_qbo_id', 'customer_name');
    }

    private function applySelectedClientToCustomers($query, QuickBooksToken $token): void
    {
        QuickBooksClientScope::applyToQboIdAndTwoNameColumns($query, $token, 'qbo_id', 'display_name', 'company_name');
    }

    private function applySelectedClientToTransactions($query, QuickBooksToken $token): void
    {
        // Purchases/expenses were entirely unscoped before this fix — a
        // client-scoped user could see the whole company's expenses.
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($query, $token, 'customer_qbo_id', 'entity_name');
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
            'search' => 'nullable|string|max:100',
            'active' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $customers = QuickBooksCustomer::where('realm_id', $token->realm_id);
        $this->applySelectedClientToCustomers($customers, $token);
        $customers = $customers
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
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
            'status' => 'nullable|in:Open,Paid,Overdue',
            'customer' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $invoices = QuickBooksInvoice::where('realm_id', $token->realm_id);
        $this->applySelectedClientToInvoices($invoices, $token);
        $invoices = $invoices
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_name', 'like', '%'.$request->customer.'%'))
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
            'type' => 'nullable|string|max:50',
            'account' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $transactions = QuickBooksTransaction::where('realm_id', $token->realm_id);
        $this->applySelectedClientToTransactions($transactions, $token);
        $transactions = $transactions
            ->when($request->filled('type'), fn ($q) => $q->where('txn_type', $request->type))
            ->when($request->filled('account'), fn ($q) => $q->where('account_name', 'like', '%'.$request->account.'%'))
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

        $customerCountQuery = QuickBooksCustomer::where('realm_id', $realmId);
        $this->applySelectedClientToCustomers($customerCountQuery, $token);

        $outstandingBalance = (clone $invoiceQuery)->whereIn('status', ['Open', 'Overdue'])->sum('balance');
        $overdueCount = (clone $invoiceQuery)->where('status', 'Overdue')->count();
        $invoiceTotal = (clone $invoiceQuery)->sum('total_amount');

        // Revenue and expenses come from QuickBooks' own Profit and Loss report
        // on the company's accounting basis. They used to be paid invoices and
        // cash purchases, which on the sandbox realm put revenue at under half
        // of what QuickBooks reports.
        [$figures, $figuresError] = $this->profitAndLossToDate($token);

        return response()->json([
            'total_revenue' => $figures['revenue'] ?? null,
            'outstanding_balance' => (float) $outstandingBalance,
            'total_expenses' => $figures['total_expenses'] ?? null,
            'net_income' => $figures['net_income'] ?? null,
            'accounting_basis' => $figures['basis'] ?? null,
            'figures_error' => $figuresError,
            'overdue_invoices' => $overdueCount,
            'total_invoices' => (clone $invoiceQuery)->count(),
            'total_customers' => (clone $customerCountQuery)->count(),
            'selected_client' => $token->hasSelectedClient() ? [
                'qbo_id' => $token->selected_client_qbo_id,
                'display_name' => $token->selected_client_name,
            ] : null,
            'all_clients' => $token->isAllClientsSelected(),
            'selected_clients' => $token->selectedClients(),
            'invoice_total' => (float) $invoiceTotal,
            'last_synced_at' => QuickBooksInvoice::where('realm_id', $realmId)->max('synced_at'),
            'company' => [
                'name' => $token->company_name,
                'legal_name' => $token->legal_name,
                'email' => $token->company_email,
                'country' => $token->country,
                'realm_id' => $token->realm_id,
            ],
            'is_personal' => ! $request->user()->isAdmin(),
        ]);
    }

    /**
     * Executive Dashboard summary.
     *
     * CEO-level cards, side metrics, a weekly revenue/profit trend and a
     * composite business health score, for the authenticated user's
     * connected QuickBooks company. Revenue, expenses and profit always come
     * from QuickBooks' own Profit and Loss report; nothing here is summed
     * from documents, for the same reason summary() does not (see
     * profitAndLossToDate()).
     */
    public function executiveDashboard(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $realmId = $token->realm_id;
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();

        $invoiceQuery = QuickBooksInvoice::where('realm_id', $realmId);
        $this->applySelectedClientToInvoices($invoiceQuery, $token);

        $customerQuery = QuickBooksCustomer::where('realm_id', $realmId);
        $this->applySelectedClientToCustomers($customerQuery, $token);

        $paymentQuery = QuickBooksPayment::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($paymentQuery, $token, 'customer_qbo_id', 'customer_name');

        $salesReceiptQuery = QuickBooksSalesReceipt::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($salesReceiptQuery, $token, 'customer_qbo_id', 'customer_name');

        $transactionQuery = QuickBooksTransaction::where('realm_id', $realmId);
        $this->applySelectedClientToTransactions($transactionQuery, $token);

        // Sync watermark — a headline figure built on a failed or still-running
        // sync is flagged rather than shown as if it were complete.
        $progress = QuickBooksSyncState::progressFor($realmId);
        $syncWarning = in_array($progress['status'], ['partial', 'syncing'], true) ? [
            'status' => $progress['status'],
            'failed_entities' => collect($progress['entities'])->where('status', 'failed')->pluck('label')->values(),
            'pending_entities' => collect($progress['entities'])->whereIn('status', ['pending', 'syncing'])->pluck('label')->values(),
        ] : null;

        [$mtd, $mtdError] = $this->profitAndLossForPeriod($token, $monthStart, $now);
        $revenue = $mtd['revenue'] ?? null;
        $expenses = $mtd['total_expenses'] ?? null;
        $netProfit = $mtd['net_income'] ?? null;
        $margin = ($revenue !== null && $revenue != 0 && $netProfit !== null)
            ? round($netProfit / $revenue * 100, 1)
            : null;
        $mtdNote = $mtdError ? $this->figuresErrorNote($mtdError) : null;

        // Bank balances and expense accounts are company-level, not tied to a
        // client — same gate bills use, mirrored here for a token.
        $seesWholeCompany = QuickBooksClientScope::tokenSeesWholeCompany($token);
        $cash = $seesWholeCompany
            ? (float) QuickBooksAccount::where('realm_id', $realmId)->where('account_type', 'Bank')->sum('current_balance')
            : null;

        $arOverdue = (float) (clone $invoiceQuery)
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<', $now->toDateString())
            ->sum('balance');

        $openInvoices = (clone $invoiceQuery)->where('balance', '>', 0)->where('status', '!=', 'Paid')->count();
        $activeCustomers = (clone $customerQuery)->where('active', true)->count();

        // Cash flow is a rough "money that moved" figure from the synced
        // documents themselves, not from the P&L report, which reports profit
        // on an accrual basis and has no notion of cash movement.
        $cashFlow = (float) (
            (clone $paymentQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            + (clone $salesReceiptQuery)->whereBetween('txn_date', [$monthStart, $now])->sum('total_amount')
            - (clone $transactionQuery)
                ->whereIn('txn_type', ['Purchase', 'Expense'])
                ->whereBetween('txn_date', [$monthStart, $now])
                ->sum('amount')
        );

        $trendStart = $now->copy()->subWeeks(5)->startOfWeek();
        [$trendWeeks, $trendError] = $this->trendFor($token, $trendStart, $now, 'Week');

        $health = $this->businessHealthScore($arOverdue, $revenue, $margin, $cash);

        return response()->json([
            'sync_warning' => $syncWarning,
            'ceo_overview' => [
                'cash' => $this->moneyCard($cash, $seesWholeCompany ? 'Watch trend' : null),
                'revenue_mtd' => $this->moneyCard($revenue, $mtdNote ?? 'On pace'),
                'net_profit' => $this->moneyCard($netProfit, $margin === null ? $mtdNote : "{$margin}% margin")
                    + ['margin_percentage' => $margin],
                'ar_overdue' => $this->moneyCard($arOverdue, $arOverdue > 0 ? 'Needs action' : 'On track'),
            ],
            'side_metrics' => [
                'revenue_mtd' => $this->abbreviateCurrency($revenue),
                'active_customers' => $activeCustomers,
                'cash_flow' => $this->abbreviateCurrency($cashFlow),
                'open_invoices' => $openInvoices,
            ],
            'charts' => [
                'revenue_profit_trend' => [
                    'labels' => $trendWeeks !== null ? array_column($trendWeeks, 'label') : [],
                    'datasets' => [
                        [
                            'name' => 'Revenue',
                            'color' => '#2563eb',
                            'data' => $trendWeeks !== null ? array_column($trendWeeks, 'revenue') : [],
                        ],
                        [
                            'name' => 'Profit',
                            'color' => '#10b981',
                            'data' => $trendWeeks !== null ? array_column($trendWeeks, 'profit') : [],
                        ],
                    ],
                    'error' => $trendError ? $this->figuresErrorNote($trendError) : null,
                ],
                'business_health_score' => $health,
            ],
        ]);
    }

    /**
     * Revenue/profit trend for the home dashboard's Weekly/Monthly/Yearly
     * toggle. Same QuickBooks report as the executive dashboard's trend,
     * just over a different range and bucket size.
     */
    public function revenueTrend(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|in:weekly,monthly,yearly',
        ]);

        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $now = Carbon::now();
        $period = $request->input('period', 'monthly');

        [$start, $summarizeBy] = match ($period) {
            'weekly' => [$now->copy()->subWeeks(11)->startOfWeek(), 'Week'],
            'yearly' => [$now->copy()->subYears(4)->startOfYear(), 'Year'],
            default => [$now->copy()->subMonths(11)->startOfMonth(), 'Month'],
        };

        [$trend, $error] = $this->trendFor($token, $start, $now, $summarizeBy);

        return response()->json([
            'period' => $period,
            'labels' => $trend !== null ? array_column($trend, 'label') : [],
            'revenue' => $trend !== null ? array_column($trend, 'revenue') : [],
            'profit' => $trend !== null ? array_column($trend, 'profit') : [],
            'error' => $error ? $this->figuresErrorNote($error) : null,
        ]);
    }

    /**
     * Home dashboard insights: revenue by customer, a 7-day sales comparison,
     * and this month's order count with a short sparkline. All month-to-date
     * or shorter, unlike summary()'s all-time figures, since these are meant
     * to read as "what's happening lately" rather than headline totals.
     */
    public function homeInsights(Request $request): JsonResponse
    {
        $token = $this->resolveToken($request);

        if (! $token) {
            return response()->json(['message' => 'QuickBooks is not connected.'], 422);
        }

        $realmId = $token->realm_id;
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();

        $invoiceQuery = QuickBooksInvoice::where('realm_id', $realmId);
        $this->applySelectedClientToInvoices($invoiceQuery, $token);

        $salesReceiptQuery = QuickBooksSalesReceipt::where('realm_id', $realmId);
        QuickBooksClientScope::applyToQboIdAndSingleNameColumn($salesReceiptQuery, $token, 'customer_qbo_id', 'customer_name');

        // ── Sales by category: revenue by customer, month to date ──
        $customers = QuickBooksClientScope::reportCustomersForToken($token);
        $salesByCategory = ['labels' => [], 'data' => []];
        $salesByCategoryError = null;

        if ($customers === null) {
            $salesByCategoryError = 'client_access_pending';
        } else {
            try {
                $byCustomer = app(QuickBooksReports::class)->incomeByCustomer($token, $monthStart, $now, $customers);
                $salesByCategory = $this->topCategoriesFrom($byCustomer);
            } catch (QuickBooksReauthorizationRequired $e) {
                $salesByCategoryError = 'quickbooks_reconnect_required';
            } catch (RuntimeException $e) {
                Log::warning('QuickBooks income-by-customer report unavailable for the home dashboard.', [
                    'realm_id' => $realmId,
                    'error' => $e->getMessage(),
                ]);
                $salesByCategoryError = 'quickbooks_report_unavailable';
            }
        }

        // ── Daily sales: this 7-day window vs. the previous one ──
        $todayStart = $now->copy()->startOfDay();
        $thisWeekStart = $todayStart->copy()->subDays(6);
        $lastWeekStart = $thisWeekStart->copy()->subDays(7);

        $daily = $this->dailyTotals($invoiceQuery, $salesReceiptQuery, $lastWeekStart, $now);

        $thisWeek = [];
        $lastWeek = [];
        $categories = [];

        for ($i = 0; $i < 7; $i++) {
            $thisDay = $thisWeekStart->copy()->addDays($i);
            $categories[] = $thisDay->format('D');
            $thisWeek[] = round($daily[$thisDay->toDateString()]['amount'] ?? 0, 2);
            $lastWeek[] = round($daily[$lastWeekStart->copy()->addDays($i)->toDateString()]['amount'] ?? 0, 2);
        }

        // ── Total orders: this month, with a 10-day sparkline of daily counts ──
        $ordersThisMonth = (clone $invoiceQuery)->whereBetween('txn_date', [$monthStart, $now])->count()
            + (clone $salesReceiptQuery)->whereBetween('txn_date', [$monthStart, $now])->count();

        $sparklineStart = $now->copy()->subDays(9)->startOfDay();
        $sparklineDaily = $sparklineStart->greaterThanOrEqualTo($lastWeekStart)
            ? $daily
            : $this->dailyTotals($invoiceQuery, $salesReceiptQuery, $sparklineStart, $now);

        $sparkline = [];
        for ($i = 0; $i < 10; $i++) {
            $sparkline[] = $sparklineDaily[$sparklineStart->copy()->addDays($i)->toDateString()]['count'] ?? 0;
        }

        return response()->json([
            'sales_by_category' => [
                'labels' => $salesByCategory['labels'],
                'data' => $salesByCategory['data'],
                'error' => $salesByCategoryError ? $this->figuresErrorNote($salesByCategoryError) : null,
            ],
            'daily_sales' => [
                'categories' => $categories,
                'this_week' => $thisWeek,
                'last_week' => $lastWeek,
            ],
            'total_orders' => [
                'total' => $ordersThisMonth,
                'sparkline' => $sparkline,
            ],
        ]);
    }

    /**
     * Top customers by income, the rest folded into "Others" so the donut
     * stays readable regardless of how many customers had activity.
     *
     * @param  array<int, array{customer_name: string, income: float}>  $byCustomer
     * @return array{labels: array<int, string>, data: array<int, float>}
     */
    private function topCategoriesFrom(array $byCustomer, int $limit = 4): array
    {
        $top = array_slice($byCustomer, 0, $limit);
        $rest = array_slice($byCustomer, $limit);

        $labels = array_map(fn ($c) => $c['customer_name'] !== '' ? $c['customer_name'] : 'Unnamed', $top);
        $data = array_map(fn ($c) => round($c['income'], 2), $top);

        if ($rest !== []) {
            $labels[] = 'Others';
            $data[] = round(array_sum(array_column($rest, 'income')), 2);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * Revenue and order counts per calendar day across invoices and sales
     * receipts combined, for a date range. Both are "sales" — an invoice
     * that later gets a payment, or a receipt paid at the point of sale.
     *
     * @return array<string, array{amount: float, count: int}>
     */
    private function dailyTotals($invoiceQuery, $salesReceiptQuery, Carbon $start, Carbon $end): array
    {
        $totals = [];

        $addRows = function ($rows) use (&$totals) {
            foreach ($rows as $row) {
                $totals[$row->d]['amount'] = ($totals[$row->d]['amount'] ?? 0) + (float) $row->amt;
                $totals[$row->d]['count'] = ($totals[$row->d]['count'] ?? 0) + (int) $row->cnt;
            }
        };

        $addRows((clone $invoiceQuery)
            ->whereBetween('txn_date', [$start, $end])
            ->selectRaw('DATE(txn_date) as d, SUM(total_amount) as amt, COUNT(*) as cnt')
            ->groupBy('d')
            ->get());

        $addRows((clone $salesReceiptQuery)
            ->whereBetween('txn_date', [$start, $end])
            ->selectRaw('DATE(txn_date) as d, SUM(total_amount) as amt, COUNT(*) as cnt')
            ->groupBy('d')
            ->get());

        return $totals;
    }

    /**
     * A composite 0-100 index from AR overdue ratio, profit margin and cash
     * runway. Any input that is unavailable (a pending sync, an unresolved
     * client scope, or a non-admin who cannot see cash) drops its own weight
     * from the total rather than failing the whole score.
     */
    private function businessHealthScore(float $arOverdue, ?float $revenue, ?float $margin, ?float $cash): array
    {
        $points = 0.0;
        $maxPoints = 0.0;

        if ($margin !== null) {
            $points += max(0, min($margin, 30)) / 30 * 40;
            $maxPoints += 40;
        }

        if ($revenue !== null) {
            $overdueRatio = $revenue > 0 ? min($arOverdue / $revenue, 1) : ($arOverdue > 0 ? 1 : 0);
            $points += (1 - $overdueRatio) * 30;
            $maxPoints += 30;
        }

        if ($cash !== null && $revenue !== null) {
            $monthlyExpenses = $revenue - ($margin !== null ? $revenue * $margin / 100 : 0);
            $runwayMonths = $monthlyExpenses > 0 ? $cash / $monthlyExpenses : 3;
            $points += min($runwayMonths, 3) / 3 * 30;
            $maxPoints += 30;
        }

        if ($maxPoints === 0.0) {
            return ['score' => null, 'status' => 'Not enough data yet', 'max_score' => 100];
        }

        $score = (int) round($points / $maxPoints * 100);

        $status = match (true) {
            $score >= 80 => 'Excellent',
            $score >= 65 => 'Good - Watch cash',
            $score >= 45 => 'Fair - Needs attention',
            default => 'Poor - Act now',
        };

        return ['score' => $score, 'status' => $status, 'max_score' => 100];
    }

    /**
     * @return array{value: float|null, formatted: string|null, subtext: string|null}
     */
    private function moneyCard(?float $value, ?string $subtext): array
    {
        return [
            'value' => $value,
            'formatted' => $this->abbreviateCurrency($value),
            'subtext' => $subtext,
        ];
    }

    private function abbreviateCurrency(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        if ($abs >= 1_000_000) {
            return $sign.'$'.round($abs / 1_000_000, 1).'M';
        }

        if ($abs >= 1_000) {
            return $sign.'$'.round($abs / 1_000, 1).'K';
        }

        return $sign.'$'.number_format($abs, 0);
    }

    private function figuresErrorNote(string $error): string
    {
        return match ($error) {
            'client_access_pending' => 'Access is still being set up',
            'quickbooks_reconnect_required' => 'Reconnect QuickBooks to load these figures',
            'quickbooks_report_unavailable' => 'QuickBooks report unavailable',
            default => 'Unavailable',
        };
    }
}
