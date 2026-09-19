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
        return 'List customers, optionally filtered by name/company search text and active status.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Search text matched against customer display name, company name, or email.'],
                'active' => ['type' => 'boolean', 'description' => 'Filter by active status.'],
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

        $totalCount = (clone $query)->count();

        $customers = (clone $query)
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
        ], $context, ['customers']);
    }
}
