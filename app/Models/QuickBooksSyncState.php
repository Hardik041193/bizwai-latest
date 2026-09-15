<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class QuickBooksSyncState extends Model
{
    protected $table = 'quickbooks_sync_states';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    /**
     * Entities tracked for a realm, in the order they are synced.
     *
     * Ordering matters for the progress UI: company_info resolves first so the
     * company name appears immediately, then the entities the dashboard needs.
     * R3 appends bills, payments, sales_receipts and the rest here.
     */
    public const ENTITIES = [
        'company_info',
        'client_matching',
        'accounts',
        'customers',
        'invoices',
        'transactions',
    ];

    /**
     * Human labels for the progress UI, keyed by entity.
     */
    public const LABELS = [
        'company_info' => 'Company profile',
        'client_matching' => 'Client access',
        'accounts' => 'Chart of accounts',
        'customers' => 'Customers',
        'invoices' => 'Invoices',
        'transactions' => 'Expenses',
    ];

    protected $fillable = [
        'realm_id',
        'entity',
        'status',
        'records_synced',
        'start_position',
        'started_at',
        'last_synced_at',
        'changes_through',
        'error',
    ];

    protected $casts = [
        'records_synced' => 'integer',
        'start_position' => 'integer',
        'started_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'changes_through' => 'datetime',
    ];

    public function label(): string
    {
        return self::LABELS[$this->entity] ?? ucfirst(str_replace('_', ' ', $this->entity));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETE, self::STATUS_FAILED], true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // State transitions
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Mark every tracked entity for a realm as queued.
     *
     * Called synchronously when a sync is requested, before the job is
     * dispatched, so the frontend's first poll already sees work in progress.
     * Without this the poll can land in the gap before a worker picks the job
     * up, read the previous run's "complete" rows, and stop polling while the
     * new sync has not started, which is the exact bug the progress endpoint
     * exists to prevent.
     */
    public static function markQueued(string $realmId): void
    {
        foreach (self::ENTITIES as $entity) {
            self::updateOrCreate(
                ['realm_id' => $realmId, 'entity' => $entity],
                [
                    'status' => self::STATUS_PENDING,
                    'started_at' => null,
                    'error' => null,
                ]
            );
        }
    }

    public static function markSyncing(string $realmId, string $entity): void
    {
        self::updateOrCreate(
            ['realm_id' => $realmId, 'entity' => $entity],
            [
                'status' => self::STATUS_SYNCING,
                'started_at' => now(),
                // Clear the previous run's figures so a count shown mid-sync
                // belongs to this run.
                'records_synced' => 0,
                'start_position' => null,
                'error' => null,
            ]
        );
    }

    /**
     * Record that a page finished, and where the next one starts.
     *
     * The count is a running total derived from the page position rather than
     * an increment, so a retried page, or two overlapping runs, overwrite the
     * figure instead of double-counting it.
     */
    public static function recordPageProgress(
        string $realmId,
        string $entity,
        int $recordsSoFar,
        int $nextPosition
    ): void {
        self::updateOrCreate(
            ['realm_id' => $realmId, 'entity' => $entity],
            [
                'status' => self::STATUS_SYNCING,
                'records_synced' => $recordsSoFar,
                'start_position' => $nextPosition,
                'error' => null,
            ]
        );
    }

    public static function markComplete(string $realmId, string $entity, int $records): void
    {
        $row = self::firstOrNew(['realm_id' => $realmId, 'entity' => $entity]);

        $row->fill([
            'status' => self::STATUS_COMPLETE,
            'records_synced' => $records,
            'last_synced_at' => now(),
            'start_position' => null,
            'error' => null,
        ]);

        // Advance the change data capture watermark to when this run started,
        // not when it finished: anything changed in QuickBooks while the run was
        // fetching is then picked up next time instead of falling in the gap.
        if ($row->started_at !== null) {
            $row->changes_through = $row->started_at;
        }

        $row->save();
    }

    public static function markFailed(string $realmId, string $entity, string $error): void
    {
        self::updateOrCreate(
            ['realm_id' => $realmId, 'entity' => $entity],
            [
                'status' => self::STATUS_FAILED,
                // Column is TEXT but QBO error bodies can be long, so cap it.
                'error' => mb_substr($error, 0, 2000),
            ]
        );
    }

    /**
     * Any entity left mid-flight is failed, used when a job dies without
     * reaching the per-entity error handler (timeout, worker restart, OOM).
     */
    public static function failUnfinished(string $realmId, string $error): void
    {
        self::where('realm_id', $realmId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SYNCING])
            ->update([
                'status' => self::STATUS_FAILED,
                'error' => mb_substr($error, 0, 2000),
                'updated_at' => now(),
            ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Progress reporting
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Where to ask QuickBooks for changes from, or null when a full sync is needed.
     *
     * Change data capture can only be used when every one of the given entities
     * has completed a run, and the oldest of those runs is inside QuickBooks'
     * lookback window. Using the oldest watermark means no entity can miss a
     * change; the rest just re-read a little, which is harmless because every
     * write is an upsert.
     *
     * @param  array<int, string>  $entities
     */
    public static function changesSince(string $realmId, array $entities, int $maxAgeDays): ?Carbon
    {
        $watermarks = self::where('realm_id', $realmId)
            ->whereIn('entity', $entities)
            ->pluck('changes_through', 'entity')
            ->filter();

        if ($watermarks->count() < count($entities)) {
            return null;
        }

        $oldest = $watermarks->min();

        return $oldest->greaterThan(now()->subDays($maxAgeDays)) ? $oldest : null;
    }

    /**
     * Whether a sync for this realm is genuinely still running.
     *
     * Rows can be left unfinished by a worker that died without reaching a
     * failure handler, and treating those as live would block every later sync
     * for good. A run that is really moving writes a row with each page, so the
     * realm only counts as busy while something is unfinished AND some row was
     * written recently.
     */
    public static function isInProgress(string $realmId, int $staleAfterMinutes = 15): bool
    {
        $rows = self::where('realm_id', $realmId)->get(['status', 'updated_at']);

        if (! $rows->contains(fn (self $row) => ! $row->isFinished())) {
            return false;
        }

        $lastWrite = $rows->max('updated_at');

        return $lastWrite !== null && $lastWrite->greaterThan(now()->subMinutes($staleAfterMinutes));
    }

    /**
     * Progress snapshot for a realm, shaped for both the frontend poller and
     * the AI context.
     *
     * `status` is the realm-level rollup:
     *   idle     — never synced
     *   syncing  — at least one entity pending or in flight
     *   partial  — everything finished but something failed
     *   complete — everything finished cleanly
     */
    public static function progressFor(string $realmId): array
    {
        $rows = self::where('realm_id', $realmId)->get()->keyBy('entity');

        $entities = [];
        $tracked = 0;
        $finished = 0;
        $failed = 0;
        $pending = [];

        foreach (self::ENTITIES as $entity) {
            /** @var self|null $row */
            $row = $rows->get($entity);

            $entities[] = [
                'entity' => $entity,
                'label' => $row?->label() ?? (self::LABELS[$entity] ?? $entity),
                'status' => $row?->status ?? 'idle',
                'records_synced' => $row?->records_synced ?? 0,
                'last_synced_at' => $row?->last_synced_at?->toIso8601String(),
                'error' => $row?->error,
            ];

            // No row at all means this entity has never been part of a sync for
            // this realm, which happens when the entity list grows: realms
            // synced before it existed keep their old rows until the next run.
            // Such an entity must not count towards completion, or a realm that
            // finished cleanly would report "syncing" forever and any poller
            // would hang. markQueued() creates a row for every entity, so a
            // genuinely in-flight entity always has one.
            if (! $row) {
                continue;
            }

            $tracked++;

            if ($row->isFinished()) {
                $finished++;
            } else {
                $pending[] = $entity;
            }

            if ($row->status === self::STATUS_FAILED) {
                $failed++;
            }
        }

        $total = $tracked;
        $neverSynced = $rows->isEmpty();

        if ($neverSynced) {
            $overall = 'idle';
        } elseif ($finished < $total) {
            $overall = 'syncing';
        } elseif ($failed > 0) {
            $overall = 'partial';
        } else {
            $overall = 'complete';
        }

        return [
            'entities' => $entities,
            'status' => $overall,
            // The frontend clears its spinner on this flag alone, so it must be
            // true for "partial" too: a failed entity will not finish on its own
            // and leaving the spinner up would hang the UI forever.
            'complete' => in_array($overall, ['complete', 'partial'], true),
            'progress' => $total > 0 ? (int) round($finished / $total * 100) : 0,
            'entities_total' => $total,
            'entities_finished' => $finished,
            'entities_failed' => $failed,
            'pending_entities' => $pending,
            'last_synced_at' => $rows->max('last_synced_at')?->toIso8601String(),
        ];
    }
}
