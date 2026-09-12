<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksToken;
use App\Models\QuickBooksTransaction;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;

class GetCompanySummary implements AiTool
{
    use ScopesToSelectedClients;

    public function name(): string
    {
        return 'get_company_summary';
    }

    public function description(): string
    {
        return 'Get a high-level financial summary of the connected QuickBooks company: total customers, total invoices, open balance, total revenue (paid invoices), total expenses, and overdue invoice count.';
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

        $invoiceQuery = QuickBooksInvoice::where('realm_id', $context->realmId);
        $this->applyClientScope($invoiceQuery, $context, 'customer_qbo_id', ['customer_name']);

        $txnQuery = QuickBooksTransaction::where('realm_id', $context->realmId);
        $this->applyClientScope($txnQuery, $context, 'customer_qbo_id', ['entity_name']);

        $customerQuery = QuickBooksCustomer::where('realm_id', $context->realmId);
        $this->applyClientScope($customerQuery, $context, 'qbo_id', ['display_name', 'company_name']);

        $token = QuickBooksToken::where('user_id', $context->userId)->first();

        return [
            'company_name' => $token?->company_name,
            'total_customers' => (clone $customerQuery)->count(),
            'total_invoices' => (clone $invoiceQuery)->count(),
            'open_balance' => (float) (clone $invoiceQuery)->whereIn('status', ['Open', 'Overdue'])->sum('balance'),
            'total_revenue' => (float) (clone $invoiceQuery)->where('status', 'Paid')->sum('total_amount'),
            'total_expenses' => (float) (clone $txnQuery)->sum('amount'),
            'overdue_invoices' => (clone $invoiceQuery)->where('status', 'Overdue')->count(),
        ];
    }
}
