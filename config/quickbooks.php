<?php

return [

    /*
    |--------------------------------------------------------------------------
    | QuickBooks Online OAuth 2.0 Credentials
    |--------------------------------------------------------------------------
    |
    | These values are pulled from your Intuit Developer app's Keys & OAuth
    | page. The redirect URI must be registered there exactly as set here.
    |
    */

    'client_id'     => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri'  => env('QUICKBOOKS_REDIRECT_URI', env('APP_URL') . '/quickbooks/callback'),
    'scope'         => env('QUICKBOOKS_SCOPE', 'com.intuit.quickbooks.accounting'),

    /*
    |--------------------------------------------------------------------------
    | API Environment
    |--------------------------------------------------------------------------
    |
    | "Development" targets the sandbox (sandbox-quickbooks.api.intuit.com).
    | "Production" targets the live API (quickbooks.api.intuit.com).
    | Defaults to "Development" when APP_ENV is not production.
    |
    */

    'base_url' => env('QUICKBOOKS_BASE_URL', 'Development'),

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    |
    | max_results: maximum records fetched per query from QBO (max 1000).
    | sync_days:   how many days back to pull transactions on each sync.
    |
    */

    'max_results' => (int) env('QUICKBOOKS_MAX_RESULTS', 1000),
    'sync_days'   => (int) env('QUICKBOOKS_SYNC_DAYS', 365),

];
