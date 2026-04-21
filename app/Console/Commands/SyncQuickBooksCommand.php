<?php

namespace App\Console\Commands;

use App\Jobs\SyncQuickBooksDataJob;
use App\Models\QuickBooksToken;
use Illuminate\Console\Command;

class SyncQuickBooksCommand extends Command
{
    protected $signature = 'sync:quickbooks
                            {--user= : Sync only the specified user ID}
                            {--realm= : Sync only the specified realm ID}';

    protected $description = 'Sync QuickBooks data (accounts, invoices, transactions) for all connected users.';

    public function handle(): int
    {
        $query = QuickBooksToken::query();

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        if ($realm = $this->option('realm')) {
            $query->where('realm_id', $realm);
        }

        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->info('No connected QuickBooks accounts found.');
            return self::SUCCESS;
        }

        $this->info("Dispatching sync jobs for {$tokens->count()} QuickBooks account(s)...");

        foreach ($tokens as $token) {
            dispatch(new SyncQuickBooksDataJob($token->id));
            $this->line("  → Queued sync for user #{$token->user_id} (realm: {$token->realm_id})");
        }

        $this->info('All sync jobs dispatched.');

        return self::SUCCESS;
    }
}
