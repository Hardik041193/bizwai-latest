<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksDeposit;
use App\Models\QuickBooksTransfer;
use App\Services\Ai\DateRangeResolver;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetBankMovements implements AiTool
{
    use ReportsDataFreshness, RequiresWholeCompany;

    public function name(): string
    {
        return 'get_bank_movements';
    }

    public function description(): string
    {
        return 'List bank deposits (money paid into a bank account) or transfers between the company\'s own accounts, optionally filtered by account and period, most recent first, with the count and total. Use type "deposits" for bank deposits and "transfers" for transfers. Company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['deposits', 'transfers'], 'description' => 'deposits: money paid into a bank account. transfers: money moved between the company\'s own accounts.'],
                'account' => ['type' => 'string', 'description' => 'Filter by bank account name (partial match).'],
                'period' => [
                    'type' => 'string',
                    'description' => 'Optional period to filter by date. Omit for all time.',
                    'enum' => ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'ytd', 'mtd', 'custom'],
                ],
                'start_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'end_date' => ['type' => 'string', 'description' => 'Required when period is "custom". Format Y-m-d.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows to return (default 20, max 50).'],
            ],
            'required' => ['type'],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        if ($error = $this->wholeCompanyError($context, 'Bank deposits and transfers')) {
            return $error;
        }

        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);
        $transfers = ($arguments['type'] ?? 'deposits') === 'transfers';
        $account = $arguments['account'] ?? null;

        $query = $transfers
            ? QuickBooksTransfer::where('realm_id', $context->realmId)
            : QuickBooksDeposit::where('realm_id', $context->realmId);

        if (! empty($account)) {
            $transfers
                ? $query->where(fn ($q) => $q->where('from_account_name', 'like', "%{$account}%")->orWhere('to_account_name', 'like', "%{$account}%"))
                : $query->where('account_name', 'like', "%{$account}%");
        }

        if (! empty($arguments['period'])) {
            $range = DateRangeResolver::resolve($arguments['period'], $arguments['start_date'] ?? null, $arguments['end_date'] ?? null);
            $query->whereBetween('txn_date', [$range['start']->toDateString(), $range['end']->toDateString()]);
        }

        $rows = (clone $query)->orderByDesc('txn_date')->orderByDesc('id')->limit($limit)->get();

        if ($transfers) {
            return $this->withDataFreshness([
                'transfers' => $rows->map(fn ($transfer) => [
                    'txn_date' => optional($transfer->txn_date)->toDateString(),
                    'amount' => (float) $transfer->amount,
                    'from_account' => $transfer->from_account_name,
                    'to_account' => $transfer->to_account_name,
                    'description' => $transfer->description,
                ])->all(),
                'total_count' => (clone $query)->count(),
                'total_transferred' => round((float) (clone $query)->sum('amount'), 2),
            ], $context, ['transfers']);
        }

        return $this->withDataFreshness([
            'deposits' => $rows->map(fn ($deposit) => [
                'txn_date' => optional($deposit->txn_date)->toDateString(),
                'total_amount' => (float) $deposit->total_amount,
                'deposited_to_account' => $deposit->account_name,
                'items_in_deposit' => (int) $deposit->line_count,
                'description' => $deposit->description,
            ])->all(),
            'total_count' => (clone $query)->count(),
            'total_deposited' => round((float) (clone $query)->sum('total_amount'), 2),
        ], $context, ['deposits']);
    }
}
