<?php

namespace App\Services\Ai\Tools;

use App\Models\QuickBooksBill;
use App\Models\QuickBooksInvoice;
use App\Services\Ai\QuickBooksAiContext;
use App\Services\Ai\Tools\Concerns\ReportsDataFreshness;
use App\Services\Ai\Tools\Concerns\RequiresWholeCompany;
use App\Services\Ai\Tools\Concerns\ScopesToSelectedClients;
use App\Services\Ai\Tools\Contracts\AiTool;
use Carbon\Carbon;

/**
 * Aging of what is owed, worked out from synced invoices and bills as of today
 * rather than from QuickBooks' aging reports: the synced rows already carry the
 * balance and due date, they respect a user's client scope, and the result
 * agrees with what the invoice and bill tools list.
 */
class GetAging implements AiTool
{
    use ReportsDataFreshness, RequiresWholeCompany, ScopesToSelectedClients;

    private const BUCKETS = ['not_yet_due', 'overdue_1_30_days', 'overdue_31_60_days', 'overdue_61_90_days', 'overdue_over_90_days'];

    public function name(): string
    {
        return 'get_aging';
    }

    public function description(): string
    {
        return 'Aging summary of unpaid amounts as of today, split into not yet due and 1-30, 31-60, 61-90 and over 90 days overdue, in total and per customer or vendor. Use type "receivable" for money customers owe (accounts receivable aging) and "payable" for money owed to vendors (accounts payable aging). Use it for how much is overdue by more than 30, 60 or 90 days and who owes it. Payable aging is company-level data, not available to users limited to specific clients.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['receivable', 'payable'], 'description' => 'receivable: owed by customers. payable: owed to vendors.'],
                'name' => ['type' => 'string', 'description' => 'Only this customer or vendor (partial match).'],
            ],
            'required' => ['type'],
        ];
    }

    public function handle(array $arguments, QuickBooksAiContext $context): array
    {
        if (! $context->hasQuickBooks()) {
            return ['error' => 'quickbooks_not_connected', 'message' => 'This account is not connected to QuickBooks yet.'];
        }

        $payable = ($arguments['type'] ?? 'receivable') === 'payable';

        if ($payable) {
            if ($error = $this->wholeCompanyError($context, 'Amounts owed to vendors')) {
                return $error;
            }

            $query = QuickBooksBill::where('realm_id', $context->realmId);
            $nameColumn = 'vendor_name';
        } else {
            $query = QuickBooksInvoice::where('realm_id', $context->realmId);
            $this->applyClientScope($query, $context, 'customer_qbo_id', ['customer_name']);
            $nameColumn = 'customer_name';
        }

        $query->where('balance', '>', 0);

        if (! empty($arguments['name'])) {
            $query->where($nameColumn, 'like', '%'.$arguments['name'].'%');
        }

        $today = Carbon::today();
        $totals = array_fill_keys(self::BUCKETS, 0.0);
        $byName = [];

        foreach ($query->get([$nameColumn, 'balance', 'due_date']) as $document) {
            $days = $document->due_date && $today->gt($document->due_date)
                ? (int) Carbon::parse($document->due_date)->startOfDay()->diffInDays($today)
                : 0;

            $bucket = match (true) {
                $days <= 0 => 'not_yet_due',
                $days <= 30 => 'overdue_1_30_days',
                $days <= 60 => 'overdue_31_60_days',
                $days <= 90 => 'overdue_61_90_days',
                default => 'overdue_over_90_days',
            };

            $name = $document->{$nameColumn} ?? 'Unknown';
            $byName[$name] ??= array_fill_keys(self::BUCKETS, 0.0);
            $byName[$name][$bucket] += (float) $document->balance;
            $totals[$bucket] += (float) $document->balance;
        }

        $withTotals = function (array $buckets): array {
            $buckets = array_map(fn (float $amount) => round($amount, 2), $buckets);
            $total = round(array_sum($buckets), 2);

            return $buckets + [
                'total_overdue' => round($total - $buckets['not_yet_due'], 2),
                'overdue_more_than_30_days' => round($buckets['overdue_31_60_days'] + $buckets['overdue_61_90_days'] + $buckets['overdue_over_90_days'], 2),
                'overdue_more_than_60_days' => round($buckets['overdue_61_90_days'] + $buckets['overdue_over_90_days'], 2),
                'total_owed' => $total,
            ];
        };

        $rows = [];

        foreach ($byName as $name => $buckets) {
            $rows[] = [$payable ? 'vendor_name' : 'customer_name' => $name] + $withTotals($buckets);
        }

        usort($rows, fn (array $a, array $b) => $b['total_owed'] <=> $a['total_owed']);

        return $this->withDataFreshness([
            'type' => $payable ? 'payable' : 'receivable',
            'as_of_date' => $today->toDateString(),
            'totals' => $withTotals($totals),
            $payable ? 'by_vendor' : 'by_customer' => array_slice($rows, 0, 50),
            'count' => count($rows),
        ], $context, [$payable ? 'bills' : 'invoices']);
    }
}
