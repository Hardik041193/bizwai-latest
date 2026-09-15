# QuickBooks sync, R2: complete data at flat cost

R2 makes the sync correct for large companies and keeps QuickBooks API usage
flat as R3 adds more entities. Four pieces:

| Piece | Status |
|---|---|
| Pagination | Done |
| One job per step, one page per job | Done |
| CDC incremental sync | Not started |
| Backfill command for connected realms | Not started |

## 1. Pagination

QuickBooks returns at most 1000 records per request. Every query used to
append its own `MAXRESULTS` and keep whatever came back, so any company with
more than 1000 invoices, customers or accounts was silently truncated: no
error, just missing rows and wrong totals.

`qbQuery()` now walks `STARTPOSITION` until a short page arrives, and owns the
paging clause: callers pass a query with no `STARTPOSITION` or `MAXRESULTS`, so
none can opt out by accident. Page size is clamped to 1000 whatever
`quickbooks.max_results` says, and a 200 page ceiling bounds a response that
keeps returning full pages.

## 2. One job per step, one page per job

The sync used to be one job running every entity back to back. That job's
timeout had to cover the whole company, a failure anywhere retried everything,
and each entity R3 adds would have lengthened it further.

```
SyncQuickBooksDataJob                 orchestrator, makes no QuickBooks calls
 └─ Bus::batch  (allowFailures, queue "quickbooks")
     ├─ SyncQuickBooksEntityJob  company_info
     ├─ SyncQuickBooksEntityJob  client_matching
     ├─ SyncQuickBooksEntityJob  accounts       page 1 → page 1001 → ...
     ├─ SyncQuickBooksEntityJob  customers      page 1 → ...
     ├─ SyncQuickBooksEntityJob  invoices       page 1 → ...
     └─ SyncQuickBooksEntityJob  transactions   page 1 → ...
```

Each page job stores its rows, records progress, and adds the next page to the
batch. Every entry point (Sync button, OAuth callback, scheduler) still
dispatches `SyncQuickBooksDataJob`, so none of them changed.

### Design notes

**The first page pins the query.** Invoices and purchases use a full-history
query while the realm has no rows, and a date-windowed one after. Page one
creates rows, so choosing the query again on page two would switch to the
windowed variant and page through a different result set. The chosen query
travels with each next-page job.

**Counts are running totals, not increments.** A page at `STARTPOSITION s` that
stored `c` records puts the total at `s - 1 + c`. A retried page, or two runs
overlapping, overwrites the figure instead of double-counting it.

**A rejected connection fails the run at once.** An expired refresh token or a
401 raises `QuickBooksReauthorizationRequired`. No retry can fix that, so the
job closes out every unfinished step, cancels the batch, and fails without
retrying, instead of three backed-off attempts at each of six steps before the
progress screen could stop.

**The batch cleans up after itself.** Its `finally` callback fails any step
still unfinished once the batch settles, for steps a cancelled batch skipped or
a lost job never reported. Without it the progress screen would hold open.

## 3. Fewer duplicate syncs

Reads are the metered kind under Intuit's App Partner Program, and a duplicate
sync repeats every one of them.

- **Sync button**: returns the running sync's progress instead of starting a
  second one. A realm counts as busy only while a step is unfinished and some
  step was written in the last 15 minutes, so rows orphaned by a dead worker
  cannot block syncing forever.
- **Scheduler**: queues one sync per realm, not one per connected user. It skips
  realms whose connections have all expired, skips realms already syncing, and
  queues client matching for any other user on the realm whose scope never
  resolved (the scope fails closed, so they would otherwise stay locked out).

## Deploying

1. **Run `php artisan queue:restart` after deploying.** Workers are long running
   and keep job classes in memory, so without a restart they carry on running
   the pre-R2 sync.
2. No migrations. The `job_batches` table from R1 is now in use.
3. `SyncQuickBooksDataJob::$tokenId` stays private, as before R2, so jobs queued
   before the deploy still unserialize.

## Known limitations

- **Sync state is per realm, not per user.** Two users on one company share one
  "Client access" row, so one user's matching failure shows on the other's
  progress screen.
- **Overlapping runs** (reconnecting mid-sync, which bypasses the Sync button
  guard) are data-safe but can mark a step complete early.
- **Pages carry no `ORDERBY`.** If records are created or deleted while a large
  entity is being paged, a record near a page boundary can be missed until the
  next sync. CDC is the intended remedy.

## Verified

- **64 tests pass**, run with
  `docker run --rm -v "$PWD":/app -w /app --user "$(id -u):$(id -g)" php:8.3-cli php artisan test`.
  `QuickBooksSyncBatchIntegrationTest` drives the orchestrator batch through
  `queue:work` on a database queue, the only test that reaches `Batch::add()`.
- **Mutation checks.** Each deliberate break makes its test fail:

  | Deliberate break | Result |
  |---|---|
  | Later pages re-derive the query instead of reusing it | caught |
  | Batch stops tolerating a failed step | caught |
  | A 401 treated as an ordinary retryable error | caught |
  | Page counts incremented instead of totalled | caught |
  | Scheduler queues one sync per user again | caught |
  | Sync button ignores a sync already running | caught |
  | Next pages dispatched outside the batch | caught (6 jobs instead of 11) |

- **Live, against the connected realm:** a run on an expired connection closed out
  all six steps in 4 seconds, cancelled the batch, and failed exactly one job,
  with no retries.
- **Not yet verified live:** paging through a real batch. The only connection on
  the development machine has a refresh token issued before the token expiry fix,
  so it has expired and must be reconnected first. The integration test covers
  the same code path with QuickBooks faked.
