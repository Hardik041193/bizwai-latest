<?php

namespace App\Services\Ai\Tools\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment status for documents that carry a balance and a due date: invoices,
 * and bills from the other side of the ledger.
 *
 * The synced `status` column is worked out when the row is imported, so an
 * invoice that was not yet due at the last sync still reads "Open" after its
 * due date passes. Status is therefore decided here from the balance and the
 * due date as of today, with the synced value kept as a fallback for rows that
 * have no due date.
 */
trait FiltersOpenItems
{
    /** @var array<int, string> */
    private const OPEN_ITEM_STATUSES = ['Unpaid', 'Open', 'Overdue', 'PartiallyPaid', 'Paid'];

    private function applyOpenItemStatus(Builder $query, ?string $status): void
    {
        $today = Carbon::today()->toDateString();

        $overdue = fn (Builder $q) => $q->where('status', 'Overdue')->orWhere('due_date', '<', $today);

        match ($status) {
            'Unpaid' => $query->where('balance', '>', 0),
            'Overdue' => $query->where('balance', '>', 0)->where($overdue),
            'Open' => $query->where('balance', '>', 0)->whereNot($overdue),
            'PartiallyPaid' => $query->where('balance', '>', 0)->whereColumn('balance', '<', 'total_amount'),
            'Paid' => $query->where('balance', '<=', 0),
            default => null,
        };
    }

    /**
     * Unpaid and at least this many days past the due date.
     */
    private function applyMinDaysOverdue(Builder $query, int $days): void
    {
        $query->where('balance', '>', 0)
            ->where('due_date', '<=', Carbon::today()->subDays(max($days, 1))->toDateString());
    }

    /**
     * @return array{status: string, days_overdue: int}
     */
    private function openItemState(Model $document): array
    {
        $balance = (float) $document->balance;

        if ($balance <= 0) {
            return ['status' => 'Paid', 'days_overdue' => 0];
        }

        $daysOverdue = $document->due_date && Carbon::today()->gt($document->due_date)
            ? (int) Carbon::parse($document->due_date)->startOfDay()->diffInDays(Carbon::today())
            : 0;

        return [
            'status' => $daysOverdue > 0 || $document->status === 'Overdue' ? 'Overdue' : 'Open',
            'days_overdue' => $daysOverdue,
        ];
    }
}
