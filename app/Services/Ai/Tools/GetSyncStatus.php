<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksSyncState;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetSyncStatus implements AiTool
{
    public function name(): string
    {
        return 'get_sync_status';
    }

    public function description(): string
    {
        return 'The state of the QuickBooks data import: for each kind of data (customers, invoices, expenses, bills, payments received, sales receipts, credit memos, chart of accounts), whether its latest import finished, is still running or failed, how many records the latest run brought in, and when it last completed. Use it for whether everything is synced, when the last sync was, and whether anything failed.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass, 'required' => []];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $progress = QuickBooksSyncState::progressFor($context->realmId);

        $steps = collect($progress['entities'])->map(fn (array $step) => [
            'data' => $step['label'],
            // "idle" means this kind of data has never been imported.
            'status' => $step['status'] === 'idle' ? 'never_imported' : $step['status'],
            'records_in_latest_run' => (int) $step['records_synced'],
            'last_completed_at' => $step['last_synced_at'],
        ]);

        return [
            'overall_status' => $progress['status'],
            'all_imports_finished_without_failure' => $progress['status'] === 'complete',
            'last_completed_at' => $steps->pluck('last_completed_at')->filter()->max(),
            'oldest_completed_at' => $steps->pluck('last_completed_at')->filter()->min(),
            'failed' => $steps->where('status', 'failed')->pluck('data')->values()->all(),
            'still_importing' => $steps->whereIn('status', ['pending', 'syncing'])->pluck('data')->values()->all(),
            'never_imported' => $steps->where('status', 'never_imported')->pluck('data')->values()->all(),
            'steps' => $steps->all(),
            'note' => 'records_in_latest_run counts what the latest run added or updated, not how many records exist. A later run only brings in what changed, so a small number or zero is normal. Whether individual records are missing inside QuickBooks itself cannot be checked from here.',
        ];
    }
}
