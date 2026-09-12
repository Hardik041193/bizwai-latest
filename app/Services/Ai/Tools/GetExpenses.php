<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksTransaction;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;
use Illuminate\Support\Facades\DB;

class GetExpenses implements AiTool
{
    use ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_expenses';
    }

    public function description(): string
    {
        return 'Get total expenses (purchase transactions) for a period, broken down by top 10 accounts.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'The period to report on.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
            ],
            'required' => ['period'],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $range = DateRangeResolver::resolve(
            $arguments['period'] ?? '',
            $arguments['start_date'] ?? null,
            $arguments['end_date'] ?? null
        );

        $query = QuickBooksTransaction::where('realm_id', $context->realmId)
            ->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        $this->applyClientScope($query, $context, 'customer_qbo_id', ['entity_name']);

        $byAccount = (clone $query)
            ->select('account_name', DB::raw('SUM(amount) as total'))
            ->groupBy('account_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'account_name' => $row->account_name,
                'total' => (float) $row->total,
            ])
            ->all();

        return [
            'period_label' => $range['label'],
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'expenses' => (float) (clone $query)->sum('amount'),
            'transaction_count' => (clone $query)->count(),
            'by_account' => $byAccount,
        ];
    }
}
