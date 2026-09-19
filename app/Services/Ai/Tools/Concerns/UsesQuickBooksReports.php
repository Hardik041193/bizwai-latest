<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Exceptions\QuickBooksReauthorizationRequired;
use App\Models\QuickBooksToken;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\QuickBooksReports;
use App\Support\QuickBooksClientScope;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * For tools whose figures come from QuickBooks' own reports.
 *
 * Works out which customers the report must be filtered to, from the same scope
 * the synced-data queries use, and turns each way a report can fail into an
 * error the assistant can explain.
 */
trait UsesQuickBooksReports
{
    /**
     * @param  callable(QuickBooksReports, QuickBooksToken, array<int, string>): array  $lookup
     * @return array<string, mixed>
     */
    private function withReports(QuickBooksAiContext $context, callable $lookup): array
    {
        $customers = QuickBooksClientScope::reportCustomersForContext($context);

        if ($customers === null) {
            return [
                'error' => 'client_access_pending',
                'message' => 'Access to these figures is still being set up for this user.',
            ];
        }

        $token = QuickBooksToken::where('user_id', $context->userId)
            ->where('realm_id', $context->realmId)
            ->first();

        if (! $token) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        try {
            return $lookup(app(QuickBooksReports::class), $token, $customers);
        } catch (QuickBooksReauthorizationRequired $e) {
            return [
                'error' => 'quickbooks_reconnect_required',
                'message' => 'The QuickBooks connection has expired and must be reconnected before these figures can be shown.',
            ];
        } catch (RuntimeException $e) {
            Log::warning('QuickBooks report unavailable to an AI tool.', ['error' => $e->getMessage()]);

            return [
                'error' => 'quickbooks_report_unavailable',
                'message' => 'QuickBooks did not return the report just now. Try again shortly.',
            ];
        }
    }
}
