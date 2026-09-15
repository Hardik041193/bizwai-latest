<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksCustomer;
use App\Models\QuickBooksInvoice;
use App\Models\QuickBooksToken;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Concerns\UsesQuickBooksReports;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\QuickBooksReports;
use Illuminate\Support\Carbon;

class GetCompanySummary implements AiTool
{
    use ScopesToSelectedClients, UsesQuickBooksReports;

    public function name(): string
    {
        return 'get_company_summary';
    }

    public function description(): string
    {
        return "Get a high-level summary of the connected QuickBooks company: total customers, total invoices, open balance, overdue invoice count, and revenue, expenses and net income to date from QuickBooks' own Profit and Loss report on the company's accounting basis.";
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

        $customerQuery = QuickBooksCustomer::where('realm_id', $context->realmId);
        $this->applyClientScope($customerQuery, $context, 'qbo_id', ['display_name', 'company_name']);

        $token = QuickBooksToken::where('user_id', $context->userId)->first();

        $summary = [
            'company_name' => $token?->company_name,
            'total_customers' => (clone $customerQuery)->count(),
            'total_invoices' => (clone $invoiceQuery)->count(),
            'open_balance' => (float) (clone $invoiceQuery)->whereIn('status', ['Open', 'Overdue'])->sum('balance'),
            'overdue_invoices' => (clone $invoiceQuery)->where('status', 'Overdue')->count(),
        ];

        $pnl = $this->withReports($context, fn (QuickBooksReports $reports, QuickBooksToken $token, array $customers) => $reports->profitAndLoss(
            $token, Carbon::parse(QuickBooksReports::ALL_TIME_START), Carbon::now(), $customers
        ));

        // A report failure still leaves the rest of the summary worth giving.
        if (isset($pnl['error'])) {
            return $summary + [
                'total_revenue' => null,
                'total_expenses' => null,
                'net_income' => null,
                'figures_error' => $pnl['message'],
            ];
        }

        return $summary + [
            'accounting_basis' => $pnl['basis'],
            'total_revenue' => $pnl['revenue'],
            'total_expenses' => $pnl['total_expenses'],
            'net_income' => $pnl['net_income'],
        ];
    }
}
