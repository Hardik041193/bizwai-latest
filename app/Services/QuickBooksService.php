<?php

namespace App\Services;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Models\QuickBooksAccount;
use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksSyncState;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use QuickBooksOnline\API\DataService\DataService;
use RuntimeException;

class QuickBooksService
{
    /**
     * Safety ceiling on pages per query: 1000 records each, so 200k records.
     * Far above any realistic realm, and a bound on a misbehaving response.
     */
    private const MAX_PAGES = 200;

    /**
     * Sync steps fetched page by page. Every other step runs in one go.
     */
    public const PAGED_ENTITIES = ['accounts', 'customers', 'invoices', 'transactions'];

    /**
     * QuickBooks' change data capture looks back at most 30 days. One day short
     * of that leaves margin for clock skew and a run that starts late.
     */
    public const CDC_MAX_AGE_DAYS = 29;

    /**
     * QuickBooks returns at most 1000 objects from one change data capture
     * request, so a response that reaches it may have been cut short.
     */
    private const CDC_MAX_OBJECTS = 1000;

    private const REJECTED_CONNECTION_MESSAGE =
        'QuickBooks API error (401): the connection was rejected. The user must reconnect their account.';

    /**
     * Build a DataService instance for authorization URL generation only
     * (no realm_id needed at this stage).
     */
    private function makeOAuthDataService(): DataService
    {
        return DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => config('quickbooks.client_id'),
            'ClientSecret' => config('quickbooks.client_secret'),
            'RedirectURI' => config('quickbooks.redirect_uri'),
            'scope' => config('quickbooks.scope'),
            'baseUrl' => config('quickbooks.base_url'),
        ]);
    }

    /**
     * Build a fully authorized DataService for API calls.
     */
    public function getDataService(QuickBooksToken $token): DataService
    {
        $token = $this->refreshTokenIfNeeded($token);

        return DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => config('quickbooks.client_id'),
            'ClientSecret' => config('quickbooks.client_secret'),
            'RedirectURI' => config('quickbooks.redirect_uri'),
            'scope' => config('quickbooks.scope'),
            'baseUrl' => config('quickbooks.base_url'),
            'accessTokenKey' => $token->access_token,
            'refreshTokenKey' => $token->refresh_token,
            'QBORealmID' => $token->realm_id,
        ]);
    }

    /**
     * Generate the Intuit OAuth 2.0 authorization URL.
     *
     * Stores user_id in Cache keyed by the SDK-generated state value so the
     * callback web route can identify which user completed the flow.
     * This works for stateless SPA Bearer-token auth where sessions are not
     * shared between API requests and browser redirects.
     */
    public function getAuthorizationUrl(int $userId): string
    {
        $dataService = $this->makeOAuthDataService();

        /** @var \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper $helper */
        $helper = $dataService->getOAuth2LoginHelper();

        $url = $helper->getAuthorizationCodeURL();
        $state = $helper->getState();

        // Cache for 15 minutes — more than enough for the user to complete OAuth.
        Cache::put("qb_oauth_state_{$state}", $userId, now()->addMinutes(15));

        return $url;
    }

    /**
     * Exchange the authorization code returned by Intuit for access + refresh tokens.
     * Looks up the user ID from cache using the state parameter (CSRF protection).
     *
     * @throws RuntimeException if state is invalid or expired
     */
    public function exchangeCodeForTokens(
        string $code,
        string $realmId,
        string $state,
        int $userId
    ): QuickBooksToken {
        $dataService = $this->makeOAuthDataService();

        /** @var \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper $helper */
        $helper = $dataService->getOAuth2LoginHelper();
        $accessToken = $helper->exchangeAuthorizationCodeForToken($code, $realmId);

        $accessExpiresAt = $this->resolveExpiry(
            fn () => $accessToken->getAccessTokenExpiresAt(), 3600
        );
        $refreshExpiresAt = $this->resolveExpiry(
            fn () => $accessToken->getRefreshTokenExpiresAt(), 8726400
        );

        return DB::transaction(function () use (
            $userId,
            $realmId,
            $accessToken,
            $accessExpiresAt,
            $refreshExpiresAt
        ) {
            $token = QuickBooksToken::updateOrCreate(
                ['user_id' => $userId],
                [
                    'realm_id' => $realmId,
                    'access_token' => $accessToken->getAccessToken(),
                    'refresh_token' => $accessToken->getRefreshToken(),
                    'token_expires_at' => $accessExpiresAt,
                    'refresh_token_expires_at' => $refreshExpiresAt,
                    'selected_client_qbo_id' => null,
                    'selected_client_name' => null,
                    'selected_clients' => null,
                    'client_selected_at' => null,
                ]
            );

            User::find($userId)?->markQuickBooksConnected();

            return $token;
        });
    }

    /**
     * Refresh the access token if it is expired or about to expire.
     * Updates the stored token record automatically.
     */
    public function refreshTokenIfNeeded(QuickBooksToken $token): QuickBooksToken
    {
        // Token was just issued — skip all expiry checks entirely.
        if ($token->created_at && $token->created_at->diffInMinutes(now()) < 2) {
            return $token;
        }

        // Re-read the token with a pessimistic lock to prevent concurrent refreshes.
        // If two requests hit this method simultaneously, only one will actually
        // call the QBO API; the other will wait and then find the token already fresh.
        return DB::transaction(function () use ($token) {
            /** @var QuickBooksToken $fresh */
            $fresh = QuickBooksToken::where('id', $token->id)->lockForUpdate()->first();

            if (! $fresh) {
                throw new RuntimeException('QuickBooks token no longer exists.');
            }

            // After acquiring the lock the token may already have been refreshed
            // by another process — re-check expiry on the locked copy.
            if (! $fresh->isAccessTokenExpired()) {
                return $fresh;
            }

            if ($fresh->isRefreshTokenExpired()) {
                throw new QuickBooksReauthorizationRequired(
                    'QuickBooks refresh token has expired. The user must reconnect their account.'
                );
            }

            $dataService = DataService::Configure([
                'auth_mode' => 'oauth2',
                'ClientID' => config('quickbooks.client_id'),
                'ClientSecret' => config('quickbooks.client_secret'),
                'RedirectURI' => config('quickbooks.redirect_uri'),
                'scope' => config('quickbooks.scope'),
                'baseUrl' => config('quickbooks.base_url'),
                'accessTokenKey' => $fresh->access_token,
                'refreshTokenKey' => $fresh->refresh_token,
                'QBORealmID' => $fresh->realm_id,
            ]);

            /** @var \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper $helper */
            $helper = $dataService->getOAuth2LoginHelper();
            $newToken = $helper->refreshToken();

            $fresh->update([
                'access_token' => $newToken->getAccessToken(),
                'refresh_token' => $newToken->getRefreshToken(),
                'token_expires_at' => $this->resolveExpiry(
                    fn () => $newToken->getAccessTokenExpiresAt(), 3600
                ),
                'refresh_token_expires_at' => $this->resolveExpiry(
                    fn () => $newToken->getRefreshTokenExpiresAt(), 8726400
                ),
            ]);

            return $fresh->fresh();
        });
    }

    /**
     * Resolve a token expiry returned by the Intuit SDK.
     *
     * OAuth2AccessToken::getAccessTokenExpiresAt() does NOT return a number of
     * seconds, despite the constructor docblock describing the underlying
     * property that way. It returns a formatted date string, built by
     * getDateFromSeconds() as date('Y/m/d H:i:s', ...).
     *
     * The previous code cast that string to int. PHP reads the leading digits,
     * so "2026/09/12 19:16:17" became the integer 2026, and every token was
     * stored with a ~34 minute lifetime instead of 1 hour for the access token
     * and 101 days for the refresh token. Once the refresh token was considered
     * expired the user had to reconnect QuickBooks entirely, and every
     * scheduled sync failed with "refresh token has expired".
     *
     * @param  callable():string  $accessor  throws SdkException when unset
     * @param  int  $fallbackSeconds  lifetime to assume if the SDK gives nothing usable
     */
    private function resolveExpiry(callable $accessor, int $fallbackSeconds): Carbon
    {
        try {
            $value = $accessor();

            if (! empty($value)) {
                $parsed = Carbon::createFromFormat('Y/m/d H:i:s', (string) $value);

                // A past date means the SDK handed back something unexpected;
                // fall back rather than store an already-expired token.
                if ($parsed && $parsed->isFuture()) {
                    return $parsed;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('QuickBooks: could not read token expiry from the SDK, using fallback.', [
                'fallback_seconds' => $fallbackSeconds,
                'error' => $e->getMessage(),
            ]);
        }

        return now()->addSeconds($fallbackSeconds);
    }

    /**
     * Revoke the access token, remove it from the database, and purge all
     * synced data for that realm so no stale records remain after disconnect.
     */
    public function disconnect(int $userId): void
    {
        $token = QuickBooksToken::where('user_id', $userId)->first();

        if (! $token) {
            User::find($userId)?->markQuickBooksDisconnected();

            return;
        }

        $realmId = $token->realm_id;

        try {
            $dataService = $this->getDataService($token);
            /** @var \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper $helper */
            $helper = $dataService->getOAuth2LoginHelper();
            $helper->revokeToken($token->access_token);
        } catch (\Throwable $e) {
            Log::warning('QuickBooks token revocation failed (token may already be invalid).', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        // Delete token first, then check whether any OTHER user's token still
        // references this realm before purging synced data. Multiple portal
        // users can be connected to the same QuickBooks company (realm), so
        // purging unconditionally here would delete data still needed by them.
        $token->delete();

        if (QuickBooksToken::where('realm_id', $realmId)->exists()) {
            Log::info('QuickBooks: skipped data purge — another user is still connected to this realm.', [
                'user_id' => $userId,
                'realm_id' => $realmId,
            ]);
        } else {
            QuickBooksAccount::where('realm_id', $realmId)->delete();
            QuickBooksCustomer::where('realm_id', $realmId)->delete();
            QuickBooksInvoice::where('realm_id', $realmId)->delete();
            QuickBooksTransaction::where('realm_id', $realmId)->delete();
            QuickBooksSyncState::where('realm_id', $realmId)->delete();

            Log::info('QuickBooks: synced data purged after disconnect.', [
                'user_id' => $userId,
                'realm_id' => $realmId,
            ]);
        }

        User::find($userId)?->markQuickBooksDisconnected();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Data Sync Methods
    // These use the QuickBooks REST API directly via Laravel's HTTP client with
    // Accept: application/json — no XML / DOMDocument / SimpleXML required.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Sync company profile details for the connected QBO realm.
     */
    public function syncCompanyInfo(QuickBooksToken $token): int
    {
        $rows = $this->qbQuery($token, 'SELECT * FROM CompanyInfo', 'CompanyInfo');
        $company = $rows[0] ?? null;

        if (! $company) {
            return 0;
        }

        $companyName = $company->CompanyName ?? $company->LegalName ?? null;

        $token->update([
            'company_name' => $companyName,
            'legal_name' => $company->LegalName ?? null,
            'company_email' => $company->Email->Address ?? null,
            'country' => $company->Country ?? null,
        ]);

        if ($companyName && $token->user_id) {
            User::find($token->user_id)?->markQuickBooksConnected($companyName);
        }

        return 1;
    }

    /**
     * Resolve and persist which clients the connected user may see.
     *
     * A non-admin who signs in with an email belonging to a customer of the
     * connected company is scoped to that customer's records. Admins, and users
     * whose email matches nothing, track all clients.
     *
     * This used to run inline in the OAuth callback, where a failure was caught
     * and treated as "track all clients". That fails open: a transient API
     * error during connect silently granted a client-scoped user the whole
     * company's financials. It now runs as a tracked sync entity, and a failure
     * leaves the selection incomplete, which QuickBooksClientScope denies.
     *
     * @return int number of clients the user is scoped to (0 = all clients)
     */
    public function syncClientMatching(QuickBooksToken $token): int
    {
        // Selection already made (or the user picked clients by hand).
        if ($token->hasCompletedClientSelection()) {
            return count($token->selectedClients());
        }

        $user = User::find($token->user_id);

        if (! $user || $user->isAdmin() || empty($user->email)) {
            $this->selectClients($token, []);

            return 0;
        }

        // Deliberately not wrapped in try/catch: a failure must surface as a
        // failed entity, not as an accidental grant of full access.
        $matches = $this->findCustomersByEmail($token, $user->email);

        if ($matches === []) {
            Log::info('QuickBooks: no customer matched the user email; tracking all clients.', [
                'user_id' => $token->user_id,
            ]);
        }

        $this->selectClients($token, $matches);

        return count($matches);
    }

    /**
     * Sync the Chart of Accounts from QBO.
     */
    public function syncAccounts(QuickBooksToken $token): int
    {
        return $this->syncPagedEntity($token, 'accounts');
    }

    /**
     * Sync Customers from QBO.
     */
    public function syncCustomers(QuickBooksToken $token): int
    {
        return $this->syncPagedEntity($token, 'customers');
    }

    /**
     * Sync Invoices from QBO.
     */
    public function syncInvoices(QuickBooksToken $token): int
    {
        return $this->syncPagedEntity($token, 'invoices');
    }

    /**
     * Sync Transactions (Purchase/Expense records) from QBO.
     */
    public function syncTransactions(QuickBooksToken $token): int
    {
        return $this->syncPagedEntity($token, 'transactions');
    }

    /**
     * Fetch and store one page of a paged entity.
     *
     * The query is chosen on the first page and must be passed back unchanged for
     * every later page. Invoices and purchases use a full-history query while the
     * realm has no rows, but page one creates rows, so choosing again on page two
     * would switch to the date-windowed query and walk STARTPOSITION through a
     * different result set, skipping or repeating records.
     *
     * @return array{count: int, next_position: int|null, query: string}
     */
    public function syncEntityPage(
        QuickBooksToken $token,
        string $entity,
        int $startPosition = 1,
        ?string $query = null
    ): array {
        $definition = $this->pagedEntity($entity);
        $query ??= $definition['query']($token);
        $pageSize = $this->pageSize();

        $rows = $this->fetchPage($token, $query, $definition['key'], $startPosition, $pageSize);
        $count = $definition['persist']($token, $rows);

        // A full page may be followed by more; a short one is the last.
        $hasMore = count($rows) === $pageSize;
        $pageNumber = intdiv($startPosition - 1, $pageSize) + 1;

        if ($hasMore && $pageNumber >= self::MAX_PAGES) {
            Log::warning('QuickBooks: hit the page ceiling, results may be incomplete.', [
                'realm_id' => $token->realm_id,
                'entity' => $entity,
                'pages' => $pageNumber,
            ]);

            $hasMore = false;
        }

        return [
            'count' => $count,
            'next_position' => $hasMore ? $startPosition + $pageSize : null,
            'query' => $query,
        ];
    }

    /**
     * Apply everything that changed in QuickBooks since $since, in one request.
     *
     * One change data capture call covers every paged entity, where a full sync
     * needs at least one paged query per entity, and unlike a query it also
     * reports deletions. A response that reaches the object cap may be missing
     * changes, so nothing is written in that case and `truncated` tells the
     * caller to run a full sync instead.
     *
     * @return array{truncated: bool, counts: array<string, array{updated: int, deleted: int}>}
     */
    public function syncChanges(QuickBooksToken $token, Carbon $since): array
    {
        $keys = [];
        foreach (self::PAGED_ENTITIES as $entity) {
            $keys[$entity] = $this->pagedEntity($entity)['key'];
        }

        $changes = $this->fetchChanges($token, array_values($keys), $since);
        $total = array_sum(array_map('count', $changes));

        if ($total >= self::CDC_MAX_OBJECTS) {
            Log::warning('QuickBooks: change set reached the CDC object cap, a full sync is needed.', [
                'realm_id' => $token->realm_id,
                'objects' => $total,
                'since' => $since->toIso8601String(),
            ]);

            return ['truncated' => true, 'counts' => []];
        }

        $counts = [];

        foreach ($keys as $entity => $key) {
            $definition = $this->pagedEntity($entity);

            [$deleted, $active] = collect($changes[$key])
                ->partition(fn (\stdClass $row) => ($row->status ?? null) === 'Deleted');

            $updated = $definition['persist']($token, $active->values()->all());

            // A deleted object carries only its Id and MetaData.
            $deletedIds = $deleted->pluck('Id')->filter()->map(fn ($id) => (string) $id)->values()->all();

            if ($deletedIds !== []) {
                $model = $definition['model'];
                $model::where('realm_id', $token->realm_id)->whereIn('qbo_id', $deletedIds)->delete();
            }

            $counts[$entity] = ['updated' => $updated, 'deleted' => count($deletedIds)];
        }

        Log::info("QuickBooks: applied changes since {$since->toIso8601String()} for realm {$token->realm_id}.", $counts);

        return ['truncated' => false, 'counts' => $counts];
    }

    /**
     * Sync every page of a paged entity within this process.
     *
     * Queued syncs page through SyncQuickBooksEntityJob instead, one job per
     * page; both share the same query and persistence below.
     */
    private function syncPagedEntity(QuickBooksToken $token, string $entity): int
    {
        $definition = $this->pagedEntity($entity);

        $rows = $this->qbQuery($token, $definition['query']($token), $definition['key']);
        $synced = $definition['persist']($token, $rows);

        Log::info("QuickBooks: synced {$synced} {$entity} for realm {$token->realm_id}.");

        return $synced;
    }

    /**
     * How a paged entity is queried and stored.
     *
     * @return array{key: string, model: class-string<\Illuminate\Database\Eloquent\Model>, query: \Closure(QuickBooksToken): string, persist: \Closure(QuickBooksToken, array): int}
     */
    private function pagedEntity(string $entity): array
    {
        return match ($entity) {
            'accounts' => [
                'key' => 'Account',
                'model' => QuickBooksAccount::class,
                'query' => fn (QuickBooksToken $token) => 'SELECT * FROM Account',
                'persist' => fn (QuickBooksToken $token, array $rows) => $this->persistAccounts($token, $rows),
            ],
            'customers' => [
                'key' => 'Customer',
                'model' => QuickBooksCustomer::class,
                'query' => fn (QuickBooksToken $token) => 'SELECT * FROM Customer',
                'persist' => fn (QuickBooksToken $token, array $rows) => $this->persistCustomers($token, $rows),
            ],
            'invoices' => [
                'key' => 'Invoice',
                'model' => QuickBooksInvoice::class,
                'query' => fn (QuickBooksToken $token) => $this->entityQuery(
                    'Invoice', QuickBooksInvoice::where('realm_id', $token->realm_id)->exists()
                ),
                'persist' => fn (QuickBooksToken $token, array $rows) => $this->persistInvoices($token, $rows),
            ],
            'transactions' => [
                'key' => 'Purchase',
                'model' => QuickBooksTransaction::class,
                'query' => fn (QuickBooksToken $token) => $this->entityQuery(
                    'Purchase', QuickBooksTransaction::where('realm_id', $token->realm_id)->exists()
                ),
                'persist' => fn (QuickBooksToken $token, array $rows) => $this->persistTransactions($token, $rows),
            ],
            default => throw new \InvalidArgumentException("Not a paged QuickBooks entity: {$entity}"),
        };
    }

    /**
     * @param  array<\stdClass>  $rows
     */
    private function persistAccounts(QuickBooksToken $token, array $rows): int
    {
        foreach ($rows as $account) {
            QuickBooksAccount::updateOrCreate(
                ['realm_id' => $token->realm_id, 'qbo_id' => $account->Id],
                [
                    'name' => $account->Name ?? null,
                    'account_type' => $account->AccountType ?? null,
                    'account_sub_type' => $account->AccountSubType ?? null,
                    'classification' => $account->Classification ?? null,
                    'current_balance' => $account->CurrentBalance ?? 0,
                    'currency_ref' => $account->CurrencyRef->value ?? null,
                    'active' => (bool) ($account->Active ?? true),
                    'synced_at' => now(),
                ]
            );
        }

        return count($rows);
    }

    /**
     * @param  array<\stdClass>  $rows
     */
    private function persistCustomers(QuickBooksToken $token, array $rows): int
    {
        foreach ($rows as $customer) {
            QuickBooksCustomer::updateOrCreate(
                ['realm_id' => $token->realm_id, 'qbo_id' => $customer->Id],
                [
                    'display_name' => $customer->DisplayName ?? $customer->FullyQualifiedName ?? null,
                    'company_name' => $customer->CompanyName ?? null,
                    'email' => $customer->PrimaryEmailAddr->Address ?? null,
                    'phone' => $customer->PrimaryPhone->FreeFormNumber ?? null,
                    'balance' => $customer->Balance ?? 0,
                    'active' => (bool) ($customer->Active ?? true),
                    'synced_at' => now(),
                ]
            );
        }

        return count($rows);
    }

    /**
     * @param  array<\stdClass>  $rows
     */
    private function persistInvoices(QuickBooksToken $token, array $rows): int
    {
        foreach ($rows as $invoice) {
            $lineItems = [];

            foreach ((array) ($invoice->Line ?? []) as $line) {
                if (isset($line->SalesItemLineDetail)) {
                    $lineItems[] = [
                        'description' => $line->Description ?? null,
                        'quantity' => $line->SalesItemLineDetail->Qty ?? null,
                        'unit_price' => $line->SalesItemLineDetail->UnitPrice ?? null,
                        'amount' => $line->Amount ?? 0,
                        'item_name' => $line->SalesItemLineDetail->ItemRef->name ?? null,
                    ];
                }
            }

            $status = 'Open';
            if (isset($invoice->Balance) && (float) $invoice->Balance === 0.0) {
                $status = 'Paid';
            } elseif (isset($invoice->DueDate) && now()->isAfter($invoice->DueDate)) {
                $status = 'Overdue';
            }

            QuickBooksInvoice::updateOrCreate(
                ['realm_id' => $token->realm_id, 'qbo_id' => $invoice->Id],
                [
                    'doc_number' => $invoice->DocNumber ?? null,
                    'customer_name' => $invoice->CustomerRef->name ?? null,
                    'customer_qbo_id' => $invoice->CustomerRef->value ?? null,
                    'customer_email' => $invoice->BillEmail->Address ?? null,
                    'txn_date' => $invoice->TxnDate ?? null,
                    'due_date' => $invoice->DueDate ?? null,
                    'total_amount' => $invoice->TotalAmt ?? 0,
                    'balance' => $invoice->Balance ?? 0,
                    'status' => $status,
                    'currency_ref' => $invoice->CurrencyRef->value ?? null,
                    'line_items' => $lineItems,
                    'synced_at' => now(),
                ]
            );
        }

        return count($rows);
    }

    /**
     * @param  array<\stdClass>  $rows
     */
    private function persistTransactions(QuickBooksToken $token, array $rows): int
    {
        foreach ($rows as $txn) {
            $accountName = null;
            $amount = 0;

            foreach ((array) ($txn->Line ?? []) as $line) {
                if (isset($line->AccountBasedExpenseLineDetail)) {
                    $accountName = $line->AccountBasedExpenseLineDetail->AccountRef->name ?? null;
                    $amount = $line->Amount ?? 0;
                    break;
                }
            }

            QuickBooksTransaction::updateOrCreate(
                ['realm_id' => $token->realm_id, 'qbo_id' => $txn->Id],
                [
                    'txn_type' => $txn->PaymentType ?? 'Purchase',
                    'txn_date' => $txn->TxnDate ?? null,
                    'account_name' => $accountName,
                    'entity_name' => $txn->EntityRef->name ?? null,
                    'customer_qbo_id' => $txn->EntityRef->value ?? null,
                    'amount' => $txn->TotalAmt ?? $amount,
                    'description' => $txn->PrivateNote ?? null,
                    'currency_ref' => $txn->CurrencyRef->value ?? null,
                    'synced_at' => now(),
                ]
            );
        }

        return count($rows);
    }

    /**
     * Run every sync operation for a token, recording per-entity progress.
     *
     * Runs everything inline in one process. Queued syncs go through
     * SyncQuickBooksDataJob's batch instead; this remains for synchronous
     * callers, and both share the same per-entity code.
     *
     * A failing entity is recorded and skipped rather than aborting the run, so
     * one bad entity cannot deny the user every other figure on the dashboard.
     * If anything failed the method throws at the end, which lets the job's
     * existing retry/backoff have another go; the sync methods are all
     * updateOrCreate, so a repeat run is safe.
     *
     * @return array{company_info: int, client_matching: int, accounts: int, customers: int, invoices: int, transactions: int}
     *
     * @throws RuntimeException if one or more entities failed
     */
    public function syncAll(QuickBooksToken $token): array
    {
        $operations = [
            'company_info' => fn () => $this->syncCompanyInfo($token),
            // Must precede the data entities: until the scope is resolved a
            // non-admin is denied all data, so resolving it first shortens the
            // window in which the dashboard would read as empty.
            'client_matching' => fn () => $this->syncClientMatching($token),
            'accounts' => fn () => $this->syncAccounts($token),
            'customers' => fn () => $this->syncCustomers($token),
            'invoices' => fn () => $this->syncInvoices($token),
            'transactions' => fn () => $this->syncTransactions($token),
        ];

        $counts = [];
        $failures = [];

        foreach ($operations as $entity => $operation) {
            QuickBooksSyncState::markSyncing($token->realm_id, $entity);

            try {
                $count = $operation();
                $counts[$entity] = $count;
                QuickBooksSyncState::markComplete($token->realm_id, $entity, $count);
            } catch (\Throwable $e) {
                $counts[$entity] = 0;
                $failures[$entity] = $e->getMessage();
                QuickBooksSyncState::markFailed($token->realm_id, $entity, $e->getMessage());

                Log::error("QuickBooks: {$entity} sync failed for realm {$token->realm_id}.", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                'QuickBooks sync completed with failures: '.json_encode($failures)
            );
        }

        return $counts;
    }

    /**
     * Fetch active customers from QBO for the post-connect client picker.
     *
     * @return array<int, array{qbo_id: string, display_name: string, company_name: string|null}>
     */
    public function fetchCustomersForSelection(QuickBooksToken $token, ?string $search = null): array
    {
        // QuickBooks IQL does not support the OR operator or parenthesised
        // grouping in a WHERE clause, so multiple LIKE filters cannot be combined
        // server-side. Fetch the active customers and filter across fields in PHP,
        // which also avoids fragile manual quote-escaping of the search term
        // (IQL escapes single quotes by doubling them, not with a backslash).
        $rows = $this->qbQuery(
            $token,
            'SELECT Id, DisplayName, CompanyName, FullyQualifiedName FROM Customer WHERE Active = true',
            'Customer'
        );

        $term = $search !== null ? mb_strtolower(trim($search)) : '';
        $clients = [];

        foreach ($rows as $customer) {
            $displayName = $customer->DisplayName
                ?? $customer->FullyQualifiedName
                ?? $customer->CompanyName
                ?? null;

            if (! $displayName) {
                continue;
            }

            $companyName = $customer->CompanyName ?? null;

            if ($term !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $displayName,
                    $companyName,
                    $customer->FullyQualifiedName ?? null,
                ])));

                if (! str_contains($haystack, $term)) {
                    continue;
                }
            }

            $clients[] = [
                'qbo_id' => (string) $customer->Id,
                'display_name' => $displayName,
                'company_name' => $companyName,
            ];
        }

        usort($clients, fn ($a, $b) => strcasecmp($a['display_name'], $b['display_name']));

        return $clients;
    }

    /**
     * Find active QBO customers whose primary email matches the given address.
     *
     * Used to scope a freshly connected portal user to their own client record
     * when they sign in with an email that exists in the connected company's
     * customer list. IQL has no case-insensitive comparison and quoting an
     * arbitrary address server-side is fragile, so the match is done in PHP.
     *
     * @return array<int, array{qbo_id: string, name: string}>
     */
    public function findCustomersByEmail(QuickBooksToken $token, string $email): array
    {
        $needle = mb_strtolower(trim($email));

        if ($needle === '') {
            return [];
        }

        $rows = $this->qbQuery(
            $token,
            'SELECT * FROM Customer WHERE Active = true',
            'Customer'
        );

        $matches = [];

        foreach ($rows as $customer) {
            $customerEmail = $customer->PrimaryEmailAddr->Address ?? null;

            if ($customerEmail === null || mb_strtolower(trim($customerEmail)) !== $needle) {
                continue;
            }

            $name = $customer->DisplayName
                ?? $customer->FullyQualifiedName
                ?? $customer->CompanyName
                ?? null;

            if (! $name) {
                continue;
            }

            // One address is often shared by several customer records
            // (sub-customers, multiple sites), so every match is tracked.
            $matches[] = [
                'qbo_id' => (string) $customer->Id,
                'name' => $name,
            ];
        }

        return $matches;
    }

    /**
     * Persist the client(s) the user chose after OAuth.
     *
     * Pass an empty array to track ALL clients (no filtering). Otherwise pass a
     * list of ['qbo_id' => ..., 'name' => ...] for the specific clients to track.
     *
     * @param  array<int, array{qbo_id: string, name: string|null}>  $clients
     */
    public function selectClients(QuickBooksToken $token, array $clients): QuickBooksToken
    {
        // Normalise to the stored shape and keep the legacy single columns in
        // sync (first client, or null for "all") for backward compatibility.
        $normalised = array_values(array_map(fn ($c) => [
            'qbo_id' => (string) $c['qbo_id'],
            'name' => $c['name'] ?? null,
        ], $clients));

        $first = $normalised[0] ?? null;

        $token->update([
            'selected_clients' => $normalised,
            'selected_client_qbo_id' => $first['qbo_id'] ?? null,
            'selected_client_name' => $first['name'] ?? null,
            'client_selected_at' => now(),
        ]);

        return $token->fresh();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build the IQL used to sync a transactional entity.
     *
     * MetaData.LastUpdatedTime is the right cursor for an incremental sync, but
     * it is wrong for a cold start: records last touched before the window are
     * skipped entirely, so a realm whose invoices predate it syncs as empty and
     * every derived figure (open balance, totals) reads zero. The first sync of
     * a realm therefore pulls full history, and the window applies only once
     * rows exist to keep later syncs cheap.
     *
     * @param  string  $entity  QBO entity name (e.g. 'Invoice', 'Purchase')
     * @param  bool  $hasExistingRows  Whether this realm has already synced rows
     */
    private function entityQuery(string $entity, bool $hasExistingRows): string
    {
        if (! $hasExistingRows) {
            return "SELECT * FROM {$entity}";
        }

        $since = now()->subDays((int) config('quickbooks.sync_days', 365))->format('Y-m-d');

        return "SELECT * FROM {$entity} WHERE MetaData.LastUpdatedTime >= '{$since}'";
    }

    /**
     * Execute an IQL query against the QuickBooks REST API, following pagination.
     *
     * QuickBooks caps a single response at 1000 records. Previously every query
     * appended its own MAXRESULTS and took whatever came back, so any realm with
     * more than 1000 invoices, customers or accounts was silently truncated: no
     * error, no warning, just missing rows and wrong totals everywhere
     * downstream. This now walks STARTPOSITION until a short page arrives.
     *
     * Callers pass the query WITHOUT a STARTPOSITION or MAXRESULTS clause; this
     * method owns paging so no caller can opt out of it by accident.
     *
     * Bypasses the SDK's DataService::Query() which requires SimpleXML/DOMDocument.
     *
     * @param  string  $query  IQL query (e.g. "SELECT * FROM Account")
     * @param  string  $entityKey  JSON response key (e.g. 'Account', 'Invoice')
     * @return array<\stdClass>
     *
     * @throws RuntimeException on HTTP or API error
     */
    private function qbQuery(QuickBooksToken $token, string $query, string $entityKey): array
    {
        $pageSize = $this->pageSize();

        // IQL uses 1-based positions.
        $startPosition = 1;
        $results = [];
        $pages = 0;

        do {
            $page = $this->fetchPage($token, $query, $entityKey, $startPosition, $pageSize);
            $results = array_merge($results, $page);

            $startPosition += $pageSize;
            $pages++;

            // A short page means the last one. The page cap is a guard against a
            // server that keeps returning full pages forever; without it a bad
            // response would spin until the job timed out.
            if (count($page) < $pageSize) {
                break;
            }

            if ($pages >= self::MAX_PAGES) {
                Log::warning('QuickBooks: hit the page ceiling, results may be incomplete.', [
                    'realm_id' => $token->realm_id,
                    'entity' => $entityKey,
                    'pages' => $pages,
                    'records' => count($results),
                ]);

                break;
            }
        } while (true);

        if ($pages > 1) {
            Log::info("QuickBooks: paged {$entityKey} over {$pages} requests for realm {$token->realm_id}.", [
                'records' => count($results),
            ]);
        }

        return $results;
    }

    /**
     * Fetch a single page of an IQL query.
     *
     * A 401 means QuickBooks rejected the connection outright (revoked, or
     * removed on Intuit's side). No retry can fix that, so it is raised as
     * QuickBooksReauthorizationRequired for sync jobs to fail fast on.
     *
     * @return array<\stdClass>
     *
     * @throws QuickBooksReauthorizationRequired when the connection is rejected
     * @throws RuntimeException on any other HTTP or API error
     */
    private function fetchPage(
        QuickBooksToken $token,
        string $query,
        string $entityKey,
        int $startPosition,
        int $pageSize
    ): array {
        // Checked per page rather than per query: paging a large realm can
        // outlast the one-hour access token.
        $token = $this->refreshTokenIfNeeded($token);

        // GET with URL-encoded query param — avoids POST body parsing issues.
        $response = Http::withToken($token->access_token)
            ->accept('application/json')
            ->get("{$this->apiBaseUrl()}/v3/company/{$token->realm_id}/query", [
                'query' => "{$query} STARTPOSITION {$startPosition} MAXRESULTS {$pageSize}",
                'minorversion' => '65',
            ]);

        if ($response->status() === 401) {
            throw new QuickBooksReauthorizationRequired(self::REJECTED_CONNECTION_MESSAGE);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "QuickBooks API error ({$response->status()}): ".$response->body()
            );
        }

        return (array) ($response->object()->QueryResponse->{$entityKey} ?? []);
    }

    /**
     * Fetch everything that changed since $since for the given entities.
     *
     * @param  array<int, string>  $keys  QuickBooks entity names, e.g. Account, Invoice
     * @return array<string, array<\stdClass>>  changed objects keyed by entity name
     *
     * @throws QuickBooksReauthorizationRequired when the connection is rejected
     * @throws RuntimeException on any other HTTP or API error
     */
    private function fetchChanges(QuickBooksToken $token, array $keys, Carbon $since): array
    {
        $token = $this->refreshTokenIfNeeded($token);

        $response = Http::withToken($token->access_token)
            ->accept('application/json')
            ->get("{$this->apiBaseUrl()}/v3/company/{$token->realm_id}/cdc", [
                'entities' => implode(',', $keys),
                // With an explicit offset. The SDK sends none, which leaves the
                // time zone for QuickBooks to assume.
                'changedSince' => $since->copy()->utc()->format('Y-m-d\TH:i:sP'),
                'minorversion' => '65',
            ]);

        if ($response->status() === 401) {
            throw new QuickBooksReauthorizationRequired(self::REJECTED_CONNECTION_MESSAGE);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "QuickBooks API error ({$response->status()}): ".$response->body()
            );
        }

        return $this->parseChanges((array) $response->json(), $keys);
    }

    /**
     * Collect changed objects from a change data capture response, per entity.
     *
     * The SDK's XML parser shows one CDCResponse holding a QueryResponse per
     * requested entity. How that maps to JSON (a list or an object at each
     * level) is not independently confirmed, so both are accepted rather than
     * guessing and silently finding no changes.
     *
     * @param  array<string, mixed>  $body
     * @param  array<int, string>  $keys
     * @return array<string, array<\stdClass>>
     */
    private function parseChanges(array $body, array $keys): array
    {
        $asList = fn ($value) => is_array($value) && ! array_is_list($value) ? [$value] : (array) $value;

        $changes = array_fill_keys($keys, []);

        foreach ($asList($body['CDCResponse'] ?? []) as $cdcResponse) {
            foreach ($asList($cdcResponse['QueryResponse'] ?? []) as $queryResponse) {
                foreach ($keys as $key) {
                    foreach ($asList($queryResponse[$key] ?? []) as $object) {
                        // Objects as the persist methods expect them.
                        $changes[$key][] = json_decode(json_encode($object));
                    }
                }
            }
        }

        return $changes;
    }

    private function apiBaseUrl(): string
    {
        return config('quickbooks.base_url') === 'Production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';
    }

    /**
     * Records per page. QuickBooks refuses anything above 1000.
     */
    private function pageSize(): int
    {
        return max(1, min((int) config('quickbooks.max_results', 1000), 1000));
    }

    /**
     * Throw an exception if the last DataService call produced an error.
     * Kept for any remaining SDK usages (OAuth operations only).
     */
    private function throwIfError(DataService $dataService): void
    {
        $error = $dataService->getLastError();

        if ($error) {
            throw new RuntimeException(
                'QuickBooks API error: '.$error->getResponseBody()
            );
        }
    }
}
