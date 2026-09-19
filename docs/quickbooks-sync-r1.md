# QuickBooks sync, R1: background sync and real progress

## What changed and why

The QuickBooks sync used to block the user's browser. `QUEUE_CONNECTION` was
`sync`, which makes Laravel's `dispatch()` run a job inline inside the HTTP
request rather than queueing it, so `POST /api/quickbooks/sync` did not return
until every entity had been pulled from QuickBooks.

The frontend's spinner was only ever accurate by accident: it cleared when the
POST returned, and the POST returned when the sync finished.

R1 moves the job onto a real queue and replaces that accidental accuracy with a
progress endpoint the frontend polls.

## Operational requirement (read this before deploying)

**A queue worker must be running.** With the `database` driver, a dispatched job
sits in the `jobs` table until a worker picks it up. No worker means no sync,
and no error on screen either.

    sudo cp deploy/bizwai-queue-worker.conf /etc/supervisor/conf.d/
    sudo supervisorctl reread && sudo supervisorctl update
    sudo supervisorctl start bizwai-queue-worker:*

Locally:

    php artisan queue:work --queue=quickbooks

Health checks worth wiring into monitoring:

    SELECT COUNT(*) FROM jobs;         -- climbing steadily = worker is down
    SELECT COUNT(*) FROM failed_jobs;  -- non-zero = investigate

## Changes

| File | Change |
|---|---|
| `.env`, `.env.example` | `QUEUE_CONNECTION=sync` to `database` |
| `config/queue.php` | `retry_after` 90 to 900 (see below) |
| `2026_09_12_000001_*` | `jobs`, `job_batches` tables |
| `2026_09_12_000002_*` | `quickbooks_sync_states` table |
| `app/Models/QuickBooksSyncState.php` | new, state machine plus progress rollup |
| `app/Services/QuickBooksService.php` | `syncAll()` records per-entity state |
| `app/Jobs/SyncQuickBooksDataJob.php` | timeout 120s to 600s, marks unfinished entities failed |
| `app/Http/Controllers/QuickBooksController.php` | `syncProgress()`, seeds state before dispatch |
| `routes/api.php` | `GET /api/quickbooks/sync/progress` |
| `resources/js/src/stores/quickbooks.ts` | polls progress, spinner tracks real state |
| `views/quickbooks/connected.vue` | progress bar and per-entity copy during onboarding |
| `views/quickbooks/dashboard.vue`, `portal.vue` | await real completion, handle partial failure |

### Why retry_after had to move

The job's timeout is 600s. If the connection's `retry_after` stays at 90s, the
worker considers the job abandoned after 90 seconds and releases it for another
worker while the first is still running, syncing the realm twice concurrently.
`retry_after` must always exceed the longest job timeout.

## Design notes

**State is seeded in the request, not the job.** `sync()` calls
`QuickBooksSyncState::markQueued()` before dispatching. A worker may take a
second or two to pick the job up, and in that window the frontend's first poll
would otherwise read the *previous* run's `complete` rows and clear its spinner
on a sync that had not started.

**A failing entity does not abort the run.** `syncAll()` catches per entity,
records the failure, and continues, so one bad entity cannot deny the user every
other figure on the dashboard. It rethrows at the end so the job's existing
retry/backoff applies. All sync methods are `updateOrCreate`, so repeat runs are
safe.

**`complete` is true for a partial sync.** A failed entity will never finish on
its own. If the frontend waited for a clean result it would spin forever, so the
rollup reports `status: partial, complete: true` and the store surfaces which
entities failed.

**The poll has a ceiling** (10 minutes). A worker that dies without reaching the
job's `failed()` handler (OOM, restart, SIGKILL) leaves rows mid-flight forever.
Hitting the ceiling shows a message naming the likely cause instead of spinning.

## API

`GET /api/quickbooks/sync/progress`

```json
{
  "realm_id": "9341...",
  "status": "syncing",
  "complete": false,
  "progress": 60,
  "entities_total": 5,
  "entities_finished": 3,
  "entities_failed": 0,
  "pending_entities": ["invoices", "transactions"],
  "last_synced_at": "2026-09-12T17:52:34+00:00",
  "entities": [
    { "entity": "company_info", "label": "Company profile", "status": "complete",
      "records_synced": 1, "last_synced_at": "...", "error": null }
  ]
}
```

`status` is `idle` (never synced), `syncing`, `partial` (finished, something
failed) or `complete`.

## Frontend

`triggerSync()` now queues and then follows the sync to completion:

```ts
await qb.triggerSync();          // resolves when the sync actually finishes
await qb.triggerSync({ poll: false });  // fire and forget

qb.syncPercent        // 0-100
qb.syncPendingLabels  // ["Invoices", "Expenses"]
qb.syncHadFailures    // true if any entity failed
qb.syncProgress       // full payload
```

`triggerSync()` refreshes summary and status once the sync lands, so screens
bound to those update without extra wiring.

## Verified

- `dispatch()` returns immediately and the job lands on the `quickbooks` queue
- State transitions: `idle` to `syncing` (0%) to 60% to `partial`/`complete`
- Worker picks the job up, per-entity failures recorded with readable reasons
- Retry and backoff intact (attempt 1, next attempt +60s)
- `tsc --noEmit`, `vue-tsc --noEmit` and `vite build` all pass
- 24 test cases pass, covering the state machine, the HTTP endpoints, and a
  full sync run against a faked QuickBooks API
- Mutation-checked: reverting `complete` to exclude `partial` fails 4 tests;
  removing the seed-before-dispatch fails the race regression test

### Running the tests

No PHP 8 build on this machine has `dom`/`xml`/`xmlwriter` (PHPUnit refuses to
start without them) or `pdo_sqlite` (which `phpunit.xml` now uses). Either
install them:

    sudo apt install -y php8.3-xml php8.3-sqlite3
    php artisan test

or run the suite in a container that already has them, which needs nothing
installed on the host:

    docker run --rm -v "$PWD":/app -w /app --user "$(id -u):$(id -g)" \
      php:8.3-cli php artisan test

The `--user` flag matters: without it the container writes root-owned files
into `storage/` and `bootstrap/cache`.

### Partial failure is not an exception

`triggerSync()` resolves on a partial sync (the run is over, some entities
failed), so a clean resolve is not proof of success. Every caller checks
`syncHadFailures`/`error` before showing a success toast. Missing that check
shows "your data has been synced" over missing data.

## Known, deferred

An expired refresh token cannot succeed on retry, but the job still retries
three times over ~20 minutes before giving up. R2 restructures the job into
per-entity jobs and is the right place to add a non-retryable auth failure.

## Not in R1

Pagination, CDC, new entities, AI partial-data awareness. See R2 and R3.
