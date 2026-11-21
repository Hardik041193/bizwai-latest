<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksVendorCredit;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetVendorCredits implements AiTool
{
    use ReportsDataFreshness, RequiresWholeCompany;

    public function name(): string
    {
        return 'get_vendor_credits';
    }

    public function description(): string
    {
        return 'List credits vendors have issued to the company (for returns or adjustments), optionally filtered by vendor and period, most recent first, with the total credited and the credit not yet used against a bill. Company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vendor' => ['type' => 'string', 'description' => 'Filter by vendor name (partial match).'],
                'unused_only' => ['type' => 'boolean', 'description' => 'Only credits with an amount still unused.'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max credits to return (default 20, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        if ($error = $this->wholeCompanyError($context, 'Vendor credits')) {
            return $error;
        }

        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);

        $query = QuickBooksVendorCredit::where('realm_id', $context->realmId);

        if (! empty($arguments['vendor'])) {
            $query->where('vendor_name', 'like', '%'.$arguments['vendor'].'%');
        }

        if (! empty($arguments['unused_only'])) {
            $query->where('balance', '>', 0);
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        return $this->withDataFreshness([
            'vendor_credits' => (clone $query)->orderByDesc('txn_date')->limit($limit)->get()->map(fn ($credit) => [
                'doc_number' => $credit->doc_number,
                'vendor_name' => $credit->vendor_name,
                'txn_date' => optional($credit->txn_date)->toDateString(),
                'total_amount' => (float) $credit->total_amount,
                'unused_credit' => (float) $credit->balance,
            ])->all(),
            'total_count' => (clone $query)->count(),
            'total_credited' => round((float) (clone $query)->sum('total_amount'), 2),
            'total_unused_credit' => round((float) (clone $query)->sum('balance'), 2),
        ], $context, ['vendor_credits']);
    }
}
