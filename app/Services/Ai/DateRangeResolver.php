<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Single source of truth for turning an AI-supplied period keyword into a
 * concrete date range. The AI must never be trusted to compute dates itself
 * — every tool that accepts a "period" argument routes it through here.
 */
class DateRangeResolver
{
    private const MAX_RANGE_YEARS = 5;

    /**
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public static function resolve(string $period, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = strtolower(trim($period));

        if ($period === 'custom') {
            return self::resolveCustom($startDate, $endDate);
        }

        $now = Carbon::now();

        [$start, $end, $label] = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'Today'],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'Yesterday'],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'This Week'],
            'last_week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek(), 'Last Week'],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'This Month'],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(), 'Last Month'],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter(), 'This Quarter'],
            'last_quarter' => [$now->copy()->subQuarterNoOverflow()->startOfQuarter(), $now->copy()->subQuarterNoOverflow()->endOfQuarter(), 'Last Quarter'],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), 'This Year'],
            'last_year' => [$now->copy()->subYearNoOverflow()->startOfYear(), $now->copy()->subYearNoOverflow()->endOfYear(), 'Last Year'],
            'ytd' => [$now->copy()->startOfYear(), $now->copy()->endOfDay(), 'Year to Date'],
            'mtd' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), 'Month to Date'],
            default => throw new InvalidArgumentException("Unknown period: {$period}"),
        };

        return ['start' => $start, 'end' => $end, 'label' => $label];
    }

    /**
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    private static function resolveCustom(?string $startDate, ?string $endDate): array
    {
        if (empty($startDate) || empty($endDate)) {
            throw new InvalidArgumentException('A custom period requires both start_date and end_date.');
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('start_date and end_date must be valid dates in Y-m-d format.');
        }

        // createFromFormat() silently rolls over invalid calendar dates
        // (e.g. 2024-02-31 -> 2024-03-02) instead of failing, so verify the
        // round-trip matches what was actually passed in.
        if ($start->format('Y-m-d') !== $startDate || $end->toDateString() !== $endDate) {
            throw new InvalidArgumentException('start_date and end_date must be valid dates in Y-m-d format.');
        }

        // Swap rather than reject a reversed range — a user asking "from X to Y"
        // where Y < X almost always meant the range the other way round.
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        if ($start->diffInYears($end) > self::MAX_RANGE_YEARS) {
            throw new InvalidArgumentException('The custom date range cannot span more than '.self::MAX_RANGE_YEARS.' years.');
        }

        return ['start' => $start, 'end' => $end, 'label' => $start->toDateString().' to '.$end->toDateString()];
    }
}
