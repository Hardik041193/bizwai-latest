# QuickBooks sync, R2: complete data at flat cost

R2 makes the sync correct for large companies and keeps QuickBooks API usage
flat as R3 adds more entities. Four pieces:

| Piece | Status |
|---|---|
| Pagination | Done |
| One job per step, one page per job | Done |
| CDC incremental sync | Done |
| Backfill for connected realms | Not needed, see Deploying |

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

## 3. Incremental sync through change data capture

Once every data entity has completed a run, later syncs ask QuickBooks only for
what changed, through its change data capture (CDC) endpoint:

```
GET /v3/company/{realm}/cdc?entities=Account,Customer,Invoice,Purchase&changedSince=2026-09-13T08:00:00+00:00
```

One request covers all four entities (eight since R3, see
`quickbooks-sync-r3.md`), where a full sync needs at least one paged query each, and unlike a query it also reports deletions. A daily sync of a
realm that is up to date now costs about two calls: company info and one change
request.

```
SyncQuickBooksDataJob
 └─ Bus::batch
     ├─ SyncQuickBooksEntityJob  company_info
     ├─ SyncQuickBooksEntityJob  client_matching
     └─ SyncQuickBooksChangesJob since the oldest watermark
          └─ too many changes? adds the four paged entity jobs to the batch
```

### Design notes

**The watermark is when a run started, not when it finished.** Each entity's
`changes_through` is set to the start of its last successful run, so anything
changed in QuickBooks while that run was fetching is picked up next time
instead of falling in the gap. It is only written on success: a failed run
leaves the previous watermark, so the next run asks for changes since then.

**The oldest watermark wins.** One request serves all four entities, so it asks
from the oldest of their watermarks. No entity can miss a change; the others
re-read a little, which is harmless because every write is an upsert.

**The window is 29 days.** QuickBooks looks back at most 30. If any entity has
never completed, or the oldest watermark is older than that, the run is a full
paged sync instead.

**A full response means a full sync.** QuickBooks returns at most 1000 objects
from one change request. A response that reaches the cap may be missing
changes, so the job applies nothing and adds the four paged entity jobs to the
same batch.

**Deletes remove local rows.** A deleted object comes back with
`status: "Deleted"` and only its `Id` and `MetaData`. Nothing has a foreign key
into the synced tables, so the rows are deleted outright.

## 4. Fewer duplicate syncs

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
2. **Run the migration.** `2026_09_15_000001` adds the nullable
   `changes_through` column to `quickbooks_sync_states`. Until a realm's entities
   complete a run on the new code they have no watermark, so the first sync
   after deploying is a full one. The `job_batches` table from R1 is now in use.
3. `SyncQuickBooksDataJob::$tokenId` stays private, as before R2, so jobs queued
   before the deploy still unserialize.
4. **Backfill with `php artisan sync:quickbooks`**, or let the 02:00 schedule do
   it. No separate backfill command is needed: a realm without watermarks gets a
   full sync, and that run writes them, so every later sync is a change sync.

## Known limitations

- **Sync state is per realm, not per user.** Two users on one company share one
  "Client access" row, so one user's matching failure shows on the other's
  progress screen.
- **Overlapping runs** (reconnecting mid-sync, which bypasses the Sync button
  guard) are data-safe but can mark a step complete early.
- **Pages carry no `ORDERBY`.** If records are created or deleted while a large
  entity is being paged, a record near a page boundary can be missed until the
  next sync that pages that entity. Change syncs do not page, so they are not
  affected.
- **Deletions are only seen inside the change window.** A realm that goes more
  than 29 days without a successful sync falls back to a full sync, and a full
  sync cannot tell that a record was deleted, so those rows stay until removed
  by hand.
- **Entities in a change response do not come back in request order.** Seen
  live: a request for `Account,Customer,Invoice,Purchase` returned Invoice
  second, after an empty entry. The SDK's XML parser matches entities by
  position and would mislabel them. This parser matches by name, pinned by
  `test_the_live_response_shape_is_read_by_entity_name_not_position`.
- **A deleted object has not yet been seen in a live change response.** The
  sandbox had no deletions in the window. Its shape (`status: "Deleted"` with
  only `Id` and `MetaData`) comes from the raw XML quoted in SDK issue #431.
- **Merged records** are assumed to arrive as `status: "Deleted"` for the record
  merged away, which the sources point to but do not state outright.

## Verified

- **81 tests pass** (277 assertions), run with
  `docker run --rm -v "$PWD":/app -w /app --user "$(id -u):$(id -g)" php:8.3-cli php artisan test`.
  `QuickBooksSyncBatchIntegrationTest` drives the orchestrator batch through
  `queue:work` on a database queue, covering both paged and change syncs on a
  live batch, including the fallback that adds a full sync to it.
- **Mutation checks.** Each deliberate break makes its test fail, 16 of 16:

  | Deliberate break | Result |
  |---|---|
  | Later pages re-derive the query instead of reusing it | caught |
  | Batch stops tolerating a failed step | caught |
  | A 401 treated as an ordinary retryable error | caught |
  | Page counts incremented instead of totalled | caught |
  | Scheduler queues one sync per user again | caught |
  | Sync button ignores a sync already running | caught |
  | Next pages dispatched outside the batch | caught |
  | Watermark set when a run finishes, not when it started | caught |
  | Starting a run wipes the watermark | caught |
  | Newest watermark used instead of the oldest | caught |
  | 30 day lookback not enforced | caught |
  | Deleted objects stored as active rows | caught |
  | Object-nested change responses not parsed | caught |
  | 1000 object cap ignored | caught |
  | Truncated fallback dispatched outside the live batch | caught |
  | Orchestrator never chooses change sync | caught |

- **Live, against the sandbox realm:**
  - An expired connection closed out all six steps in 4 seconds, cancelled the
    batch, and failed exactly one job, with no retries.
  - The first sync on the new code ran in full: 6 jobs, every step complete, and
    a watermark written for each entity.
  - The next sync chose change data capture: a batch of 3 jobs and one change
    request (`changedSince` with a `+00:00` offset accepted). It applied 4 real
    changes (2 accounts, 1 customer, 1 invoice), advanced all four data
    watermarks together, and left local row counts unchanged. 9 seconds end to
    end.
  - A raw change request parsed to exactly the counts found by walking the JSON
    independently.
- **Not yet verified live:** paging across more than one page inside a real
  batch (every entity in this realm fits in one page of 1000), and a deleted
  object. Integration tests cover both with QuickBooks faked.
