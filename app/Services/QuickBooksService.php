<?php

namespace App\Services;

use App\Models\QuickBooksAccount;
use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper;
use QuickBooksOnline\API\DataService\DataService;
use RuntimeException;

class QuickBooksService
{
    /**
     * Build a DataService instance for authorization URL generation only
     * (no realm_id needed at this stage).
     */
    private function makeOAuthDataService(): DataService
    {
        return DataService::Configure([
            'auth_mode'     => 'oauth2',
            'ClientID'      => config('quickbooks.client_id'),
            'ClientSecret'  => config('quickbooks.client_secret'),
            'RedirectURI'   => config('quickbooks.redirect_uri'),
            'scope'         => config('quickbooks.scope'),
            'baseUrl'       => config('quickbooks.base_url'),
        ]);
    }

    /**
     * Build a fully authorized DataService for API calls.
     */
    public function getDataService(QuickBooksToken $token): DataService
    {
        $token = $this->refreshTokenIfNeeded($token);

        return DataService::Configure([
            'auth_mode'       => 'oauth2',
            'ClientID'        => config('quickbooks.client_id'),
            'ClientSecret'    => config('quickbooks.client_secret'),
            'RedirectURI'     => config('quickbooks.redirect_uri'),
            'scope'           => config('quickbooks.scope'),
            'baseUrl'         => config('quickbooks.base_url'),
            'accessTokenKey'  => $token->access_token,
            'refreshTokenKey' => $token->refresh_token,
            'QBORealmID'      => $token->realm_id,
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

        $url   = $helper->getAuthorizationCodeURL();
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
        $helper      = $dataService->getOAuth2LoginHelper();
        $accessToken = $helper->exchangeAuthorizationCodeForToken($code, $realmId);

        // SDK returns raw seconds from response (e.g. 3600 / 8726400).
        // Use safe defaults when the sandbox returns null or zero.
        $accessExpiresIn  = (int) ($accessToken->getAccessTokenExpiresAt()  ?: 3600);
        $refreshExpiresIn = (int) ($accessToken->getRefreshTokenExpiresAt() ?: 8726400);

        return DB::transaction(function () use (
            $userId,
            $realmId,
            $accessToken,
            $accessExpiresIn,
            $refreshExpiresIn
        ) {
            $token = QuickBooksToken::updateOrCreate(
                ['user_id' => $userId],
                [
                    'realm_id'                  => $realmId,
                    'access_token'              => $accessToken->getAccessToken(),
                    'refresh_token'             => $accessToken->getRefreshToken(),
                    'token_expires_at'          => now()->addSeconds($accessExpiresIn),
                    'refresh_token_expires_at'  => now()->addSeconds($refreshExpiresIn),
                    'selected_client_qbo_id'    => null,
                    'selected_client_name'      => null,
                    'client_selected_at'          => null,
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
                throw new RuntimeException(
                    'QuickBooks refresh token has expired. The user must reconnect their account.'
                );
            }

            $dataService = DataService::Configure([
                'auth_mode'       => 'oauth2',
                'ClientID'        => config('quickbooks.client_id'),
                'ClientSecret'    => config('quickbooks.client_secret'),
                'RedirectURI'     => config('quickbooks.redirect_uri'),
                'scope'           => config('quickbooks.scope'),
                'baseUrl'         => config('quickbooks.base_url'),
                'accessTokenKey'  => $fresh->access_token,
                'refreshTokenKey' => $fresh->refresh_token,
                'QBORealmID'      => $fresh->realm_id,
            ]);

            /** @var \QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper $helper */
            $helper   = $dataService->getOAuth2LoginHelper();
            $newToken = $helper->refreshToken();

            $accessExpiresIn  = (int) ($newToken->getAccessTokenExpiresAt()  ?: 3600);
            $refreshExpiresIn = (int) ($newToken->getRefreshTokenExpiresAt() ?: 8726400);

            $fresh->update([
                'access_token'             => $newToken->getAccessToken(),
                'refresh_token'            => $newToken->getRefreshToken(),
                'token_expires_at'         => now()->addSeconds($accessExpiresIn),
                'refresh_token_expires_at' => now()->addSeconds($refreshExpiresIn),
            ]);

            return $fresh->fresh();
        });
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
                'error'   => $e->getMessage(),
            ]);
        }

        // Delete token first, then purge all synced data for this realm.
        // This prevents a race where another request could still use the realm_id.
        $token->delete();

        QuickBooksAccount::where('realm_id', $realmId)->delete();
        QuickBooksCustomer::where('realm_id', $realmId)->delete();
        QuickBooksInvoice::where('realm_id', $realmId)->delete();
        QuickBooksTransaction::where('realm_id', $realmId)->delete();

        User::find($userId)?->markQuickBooksDisconnected();

        Log::info('QuickBooks: synced data purged after disconnect.', [
            'user_id'  => $userId,
            'realm_id' => $realmId,
        ]);
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
            'company_name'  => $companyName,
            'legal_name'    => $company->LegalName ?? null,
            'company_email' => $company->Email->Address ?? null,
            'country'       => $company->Country ?? null,
        ]);

        if ($companyName && $token->user_id) {
            User::find($token->user_id)?->markQuickBooksConnected($companyName);
        }

        return 1;
    }

    /**
     * Sync the Chart of Accounts from QBO.
     */
    public function syncAccounts(QuickBooksToken $token): int
    {
        $maxResults = config('quickbooks.max_results', 1000);
        $rows       = $this->qbQuery($token, "SELECT * FROM Account MAXRESULTS {$maxResults}", 'Account');
        $synced     = 0;

        foreach ($rows as $account) {
            QuickBooksAccount::updateOrCreate(
                ['realm_id' => $token->realm_id, 'qbo_id' => $account->Id],
                [
                    'name'             => $account->Name ?? null,
                    'account_type'     => $account->AccountType ?? null,
                    'account_sub_type' => $account->AccountSubType ?? null,
                    'classification'   => $account->Classification ?? null,
                    'current_balance'  => $account->CurrentBalance ?? 0,
                    'currency_ref'     => $account->CurrencyRef->value ?? null,
                    'active'           => (bool) ($account->Active ?? true),
                    'synced_at'        => now(),
                ]
            );
            $synced++;
        }

        Log::info("QuickBooks: synced {$synced} accounts for realm {$token->realm_id}.");

        return $synced;
    }

    /**
     * Sync Customers from QBO.
     */
    public function syncCustomers(QuickBooksToken $token): int
    {
        $maxResults = config('quickbooks.max_results', 1000);
        $rows       = $this->qbQuery($token, "SELECT * FROM Customer MAXRESULTS {$maxResults}", 'Customer');
        $synced     = 0;

        foreach ($rows as $customer) {
            QuickBooksCustomer::updateOrCreate(
                ['realm_id' => $token->realm_id, 'qbo_id' => $customer->Id],
                [
                    'display_name' => $customer->DisplayName ?? $customer->FullyQualifiedName ?? null,
                    'company_name' => $customer->CompanyName ?? null,
                    'email'        => $customer->PrimaryEmailAddr->Address ?? null,
                    'phone'        => $customer->PrimaryPhone->FreeFormNumber ?? null,
                    'balance'      => $customer->Balance ?? 0,
                    'active'       => (bool) ($customer->Active ?? true),
                    'synced_at'    => now(),
                ]
            );
            $synced++;
        }

        Log::info("QuickBooks: synced {$synced} customers for realm {$token->realm_id}.");

        return $synced;
    }

    /**
     * Sync Invoices from QBO.
     */
    public function syncInvoices(QuickBooksToken $token): int
    {
        $maxResults = config('quickbooks.max_results', 1000);
        $syncDays   = config('quickbooks.sync_days', 365);
        $since      = now()->subDays($syncDays)->format('Y-m-d');

        $rows   = $this->qbQuery($token, "SELECT * FROM Invoice WHERE MetaData.LastUpdatedTime >= '{$since}' MAXRESULTS {$maxResults}", 'Invoice');
        $synced = 0;

        foreach ($rows as $invoice) {
            $lineItems = [];

            foreach ((array) ($invoice->Line ?? []) as $line) {
                if (isset($line->SalesItemLineDetail)) {
                    $lineItems[] = [
                        'description' => $line->Description ?? null,
                        'quantity'    => $line->SalesItemLineDetail->Qty ?? null,
                        'unit_price'  => $line->SalesItemLineDetail->UnitPrice ?? null,
                        'amount'      => $line->Amount ?? 0,
                        'item_name'   => $line->SalesItemLineDetail->ItemRef->name ?? null,
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
                    'doc_number'     => $invoice->DocNumber ?? null,
                    'customer_name'  => $invoice->CustomerRef->name ?? null,
                    'customer_email' => $invoice->BillEmail->Address ?? null,
                    'txn_date'       => $invoice->TxnDate ?? null,
                    'due_date'       => $invoice->DueDate ?? null,
                    'total_amount'   => $invoice->TotalAmt ?? 0,
                    'balance'        => $invoice->Balance ?? 0,
                    'status'         => $status,
                    'currency_ref'   => $invoice->CurrencyRef->value ?? null,
                    'line_items'     => $lineItems,
                    'synced_at'      => now(),
                ]
            );
            $synced++;
        }

        Log::info("QuickBooks: synced {$synced} invoices for realm {$token->realm_id}.");

        return $synced;
    }

    /**
     * Sync Transactions (Purchase/Expense records) from QBO.
     */
    public function syncTransactions(QuickBooksToken $token): int
    {
        $maxResults = config('quickbooks.max_results', 1000);
        $syncDays   = config('quickbooks.sync_days', 365);
        $since      = now()->subDays($syncDays)->format('Y-m-d');

        $rows   = $this->qbQuery($token, "SELECT * FROM Purchase WHERE MetaData.LastUpdatedTime >= '{$since}' MAXRESULTS {$maxResults}", 'Purchase');
        $synced = 0;

        foreach ($rows as $txn) {
            $accountName = null;
            $amount      = 0;

            foreach ((array) ($txn->Line ?? []) as $line) {
                if (isset($line->AccountBasedExpenseLineDetail)) {
                    $accountName = $line->AccountBasedExpenseLineDetail->AccountRef->name ?? null;
                    $amount      = $line->Amount ?? 0;
                    break;
                }
            }

            QuickBooksTransaction::updateOrCreate(
                ['realm_id' => $token->realm_id, 'qbo_id' => $txn->Id],
                [
                    'txn_type'     => $txn->PaymentType ?? 'Purchase',
                    'txn_date'     => $txn->TxnDate ?? null,
                    'account_name' => $accountName,
                    'entity_name'  => $txn->EntityRef->name ?? null,
                    'amount'       => $txn->TotalAmt ?? $amount,
                    'description'  => $txn->PrivateNote ?? null,
                    'currency_ref' => $txn->CurrencyRef->value ?? null,
                    'synced_at'    => now(),
                ]
            );
            $synced++;
        }

        Log::info("QuickBooks: synced {$synced} transactions for realm {$token->realm_id}.");

        return $synced;
    }

    /**
     * Run all three sync operations for a token and return a summary.
     *
     * @return array{company_info: int, accounts: int, customers: int, invoices: int, transactions: int}
     */
    public function syncAll(QuickBooksToken $token): array
    {
        return [
            'company_info' => $this->syncCompanyInfo($token),
            'accounts'     => $this->syncAccounts($token),
            'customers'    => $this->syncCustomers($token),
            'invoices'     => $this->syncInvoices($token),
            'transactions' => $this->syncTransactions($token),
        ];
    }

    /**
     * Fetch active customers from QBO for the post-connect client picker.
     *
     * @return array<int, array{qbo_id: string, display_name: string, company_name: string|null}>
     */
    public function fetchCustomersForSelection(QuickBooksToken $token, ?string $search = null): array
    {
        $maxResults = min(config('quickbooks.max_results', 1000), 200);
        $query      = "SELECT Id, DisplayName, CompanyName, FullyQualifiedName FROM Customer WHERE Active = true";

        if ($search !== null && $search !== '') {
            $term  = str_replace("'", "\\'", $search);
            $query .= " AND (DisplayName LIKE '%{$term}%' OR CompanyName LIKE '%{$term}%' OR FullyQualifiedName LIKE '%{$term}%')";
        }

        $query .= " MAXRESULTS {$maxResults}";

        $rows = $this->qbQuery($token, $query, 'Customer');

        $clients = [];

        foreach ($rows as $customer) {
            $displayName = $customer->DisplayName
                ?? $customer->FullyQualifiedName
                ?? $customer->CompanyName
                ?? null;

            if (! $displayName) {
                continue;
            }

            $clients[] = [
                'qbo_id'       => (string) $customer->Id,
                'display_name' => $displayName,
                'company_name' => $customer->CompanyName ?? null,
            ];
        }

        usort($clients, fn ($a, $b) => strcasecmp($a['display_name'], $b['display_name']));

        return $clients;
    }

    /**
     * Persist the client the user chose after OAuth and optionally sync company profile.
     */
    public function selectClient(QuickBooksToken $token, string $qboCustomerId, string $displayName): QuickBooksToken
    {
        $token->update([
            'selected_client_qbo_id' => $qboCustomerId,
            'selected_client_name'   => $displayName,
            'client_selected_at'     => now(),
        ]);

        return $token->fresh();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Execute an IQL query against the QuickBooks REST API using JSON format.
     *
     * Bypasses the SDK's DataService::Query() which requires SimpleXML/DOMDocument.
     * Returns an array of stdClass objects matching the requested entity type.
     *
     * @param  QuickBooksToken  $token
     * @param  string  $query       IQL query string (e.g. "SELECT * FROM Account")
     * @param  string  $entityKey   JSON response key (e.g. 'Account', 'Invoice', 'Purchase')
     * @return array<\stdClass>
     *
     * @throws RuntimeException on HTTP or API error
     */
    private function qbQuery(QuickBooksToken $token, string $query, string $entityKey): array
    {
        $token   = $this->refreshTokenIfNeeded($token);
        $baseUrl = config('quickbooks.base_url') === 'Production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';

        // GET with URL-encoded query param — avoids POST body parsing issues.
        $response = Http::withToken($token->access_token)
            ->accept('application/json')
            ->get("{$baseUrl}/v3/company/{$token->realm_id}/query", [
                'query'        => $query,
                'minorversion' => '65',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "QuickBooks API error ({$response->status()}): " . $response->body()
            );
        }

        $data = $response->object();

        return (array) ($data->QueryResponse->{$entityKey} ?? []);
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
                'QuickBooks API error: ' . $error->getResponseBody()
            );
        }
    }
}
