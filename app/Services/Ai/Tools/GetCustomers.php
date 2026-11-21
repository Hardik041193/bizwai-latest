<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksCustomer;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetCustomers implements AiTool
{
    use ReportsDataFreshness, ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_customers';
    }

    public function description(): string
    {
        return 'List customers with the balance each one currently owes, optionally filtered by name/company search text, active status, or only those who owe money. Sort by balance to find who owes the most.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Search text matched against customer display name, company name, or email.'],
                'active' => ['type' => 'boolean', 'description' => 'Filter by active status.'],
                'with_balance_only' => ['type' => 'boolean', 'description' => 'Only customers who currently owe money.'],
                'sort' => ['type' => 'string', 'enum' => ['name', 'balance'], 'description' => 'name (default): alphabetical. balance: largest balance owed first.'],
                'limit' => ['type' => 'integer', 'description' => 'Max customers to return (default 20, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $limit = min(max((int) ($arguments['limit'] ?? 20), 1), 50);

        $query = QuickBooksCustomer::where('realm_id', $context->realmId);
        $this->applyClientScope($query, $context, 'qbo_id', ['display_name', 'company_name']);

        if (! empty($arguments['search'])) {
            $term = '%'.$arguments['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('display_name', 'like', $term)
                    ->orWhere('company_name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (array_key_exists('active', $arguments)) {
            $query->where('active', (bool) $arguments['active']);
        }

        if (! empty($arguments['with_balance_only'])) {
            $query->where('balance', '>', 0);
        }

        $totalCount = (clone $query)->count();

        $customers = (clone $query)
            ->when(($arguments['sort'] ?? 'name') === 'balance', fn ($q) => $q->orderByDesc('balance'))
            ->orderBy('display_name')
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'display_name' => $c->display_name,
                'company_name' => $c->company_name,
                'email' => $c->email,
                'balance' => (float) $c->balance,
                'active' => $c->active,
            ])
            ->all();

        return $this->withDataFreshness([
            'customers' => $customers,
            'total_count' => $totalCount,
            'total_balance' => round((float) (clone $query)->sum('balance'), 2),
        ], $context, ['customers']);
    }
}
