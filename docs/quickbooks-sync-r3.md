# QuickBooks sync, R3: complete data for the AI chat

R3 fills the gaps in what the portal stores and makes the AI chat answer from
complete data. It ships in steps:

| Step | Status |
|---|---|
| 1. Sync bills, payments, sales receipts and credit memos | Done |
| 2. Revenue, expenses and profit from QuickBooks' own Profit and Loss report, cached, with client scoping | Done |
| 3. The AI chat qualifies answers while data is still importing | Done |
| 4. Chat tools that list bills, payments, sales receipts and credit memos | Done |

The original plan had separate steps for computing figures from the complete
data and for QuickBooks' reports. They merged: the evidence in step 2 showed the
figures should come from the report itself, not from summing documents.

## Step 1: four more entities

Before R3 the sync stored accounts, customers, invoices and cash purchases. That
left whole categories of money out of every figure:

| Entity | Table | What was missing without it |
|---|---|---|
| Bill | `quickbooks_bills` | Spending on account: expenses ran low until a bill was paid |
| Payment | `quickbooks_payments` | When, how and by which payment an invoice was paid |
| SalesReceipt | `quickbooks_sales_receipts` | Sales paid on the spot, with no invoice: absent from revenue |
| CreditMemo | `quickbooks_credit_memos` | Credits and refunds: never taken off revenue |

The field mapping was built from records fetched from a live sandbox realm, and
the tests use fixtures of that same structure.

### How they are synced

Each is a paged entity (`QuickBooksService::PAGED_ENTITIES`), so everything R2
built applies without extra code: full paged sync, change data capture,
per-step jobs with their own retries, progress rows, and fail-fast on a
rejected connection. The change request now asks for eight entities in one
call.

### Mapping notes

- **Bills** keep the vendor, amounts, the accounts payable account, and a status
  derived the same way as invoices (Paid, Overdue, Open). Their lines come in
  two kinds, and both are kept: item-based lines say what was bought,
  account-based lines say which expense account the cost went to.
- **Payments** keep the ids of the invoices they settled, taken from each line's
  links of type `Invoice`. A payment's top-level link points at the bank
  deposit, so it is ignored, as are links to credit memos.
- **Sales receipts and credit memos** keep item lines as invoices do. Subtotal,
  discount and tax lines are left out; tax is kept as a total instead.
- Invoice status and item lines moved into helpers shared with the new entities.
  Invoice behaviour is unchanged, which the existing invoice tests confirm.

### What does not change yet

Nothing reads the new tables in this step. Dashboard figures and AI answers are
exactly what they were, so this step can ship without moving any number a user
sees. Step 2 changes the figures, together with client scoping for the new data:
vendor bills belong to the company, not to any client, so a client-scoped user
must not see them.

### Deploying

1. **Run the migrations.** Four new tables, `2026_09_16_000001` to `000004`.
2. **Run `php artisan queue:restart`** so workers load the new sync steps.
3. **Expect one full sync per company.** The new entities have no change data
   capture watermark yet, and a change sync needs every entity to have one, so
   each company's next sync pulls all eight entities in full. Later syncs are
   change syncs again.

### Also fixed

`disconnect()` deleted each synced table by name, so a new table would have kept
a disconnected company's data. It now purges every table in the synced entity
list.

### Verified

- **88 tests pass** (354 assertions). `QuickBooksNewEntitiesTest` covers the four
  mappings with fixtures shaped like live sandbox records.
- **Five existing tests needed updating**, each a stale expectation that had the
  old six-step list built in (batch sizes, progress percentages, the oldest
  watermark, the `syncAll` result). None was a product bug. Where it fits, they
  now derive their expectations from the entity constants.
- **Mutation checks.** Each deliberate break makes its test fail, 8 of 8:

  | Deliberate break | Result |
  |---|---|
  | Links to credit memos counted as settled invoices | caught |
  | Linked invoice ids not deduplicated | caught |
  | Account-based bill lines dropped | caught |
  | Bill status not derived | caught |
  | Subtotal lines stored as items | caught |
  | Remaining credit dropped | caught |
  | Disconnect purges only the original four tables | caught |
  | A new entity left out of the change request | caught |

- **Live, against the sandbox realm:**
  - Record counts matched a live count query exactly: 15 bills, 16 payments, 4
    sales receipts, 1 credit memo. All ten sync steps completed.
  - Payments linked 14 distinct invoices, and all 14 exist among the synced
    invoices, so the linkage is sound.
  - 14 of the 16 real bill lines are account-based. Keeping item lines only, as
    invoices do, would have dropped most bill detail.
  - Bill status derivation on real data: 10 paid, 5 overdue, every bill with its
    vendor id.

## Step 2: figures from QuickBooks' own Profit and Loss report

### Why not sum the synced documents

All dates, sandbox realm:

| | Revenue | Expenses |
|---|---|---|
| The portal before this step (paid invoices, cash purchases) | 4,282.62 | 3,424.17 |
| QuickBooks Profit and Loss, accrual basis | 10,201.77 | 8,558.31 |
| QuickBooks Profit and Loss, cash basis | 5,080.27 | 6,984.39 |
| Best estimate from all eight synced entities (accrual) | 10,816.47 | 9,566.34 |

Expenses include cost of goods sold and other expenses. The portal showed under
half of QuickBooks' accrual revenue. Even with every entity synced, summing
documents came out 6% high on revenue and 12% high on expenses, because
QuickBooks books each line to an account (tax, cost of goods, asset purchases,
other expenses) that document totals cannot see. Only the report is exact.

### Decisions

Both were put to the product owner with the figures above:

- **Basis: each company's own QuickBooks setting.** Implemented by sending no
  `accounting_method`, so QuickBooks applies the company preference. On the
  sandbox, `Preferences.ReportPrefs.ReportBasis` is `Accrual`, and a report
  requested without a method came back on an accrual basis. The basis used is
  returned with every figure.
- **Source: QuickBooks' Profit and Loss report, cached.**

### How it works

`App\Services\QuickBooksReports`:

- `profitAndLoss()` returns every section total plus three figures that always
  satisfy revenue - total expenses = net income: `revenue` (income plus other
  income), `total_expenses` (cost of goods sold, expenses and other expenses)
  and `net_income`, and the top-level expense accounts. Parent accounts arrive
  as nested sections with their own total and are kept as one line.
- `incomeByCustomer()` reads the same report summarised by customer, so a
  breakdown agrees with the headline. Columns are keyed by customer id; the
  label, `not_specified` and `total` columns are skipped.
- **Caching.** A report is cached under its realm, dates and client filter, plus
  the realm's latest completed sync step, so a sync that lands data invalidates
  it. A failed request is never cached, and nothing is kept longer than 6 hours.
  Live: 1,631 ms for a report, 12 ms for the same report again.

**Client scoping.** Reports can be filtered by customer id only. The filter
comes from the same scoping code as the synced-data queries
(`QuickBooksClientScope::reportCustomersFor*`):

- A non-admin whose client match has not resolved gets no figures and no
  QuickBooks request is made.
- A specific selection becomes a comma list of customer ids. Confirmed live:
  customers 1 and 2 together returned 890.00, their 630.00 and 260.00 combined.
- A selection with no ids to filter by is denied rather than widened.

**AI tools.** `get_profit_and_loss`, `compare_financial_periods`, `get_revenue`,
`get_expenses` and `get_company_summary` now take their figures from the
report. A report failure becomes an error the assistant can explain:
`client_access_pending`, `quickbooks_reconnect_required` or
`quickbooks_report_unavailable`. The system prompt asks the assistant to state
the basis. The revenue breakdown no longer includes customer ids.

**Dashboard summary.** `GET /api/quickbooks/summary` takes `total_revenue` and
`total_expenses` from the report and adds `net_income`, `accounting_basis` and
`figures_error`. A failed report no longer fails the endpoint: the rest of the
summary is returned and the two figures are null. The admin dashboard shows
"Unavailable" with the reason instead of $0.00, which would have been a
confident wrong figure, and notes the basis when the figures loaded.

### What users will see change

- **Admin dashboard revenue and expenses** move to QuickBooks' figures. On the
  sandbox, all-time revenue goes from 4,282.62 to 10,201.77 and expenses from
  3,424.17 to 8,558.31.
- **Client portal cards are unchanged.** Open balance and total invoiced still
  come from invoices.
- **AI answers** about revenue, expenses and profit now match QuickBooks and say
  which basis they are on. A client-scoped user's figures cover only their
  clients: live, Amy's Bird Sanctuary saw revenue of 630.00 and only her own
  name in the customer breakdown.

### API usage

Each distinct report (period and client filter) costs one metered read until
the company's next sync or 6 hours. The dashboard summary asks for a report
running to today, so it costs at most one call per user per day, plus one after
each sync. Repeat questions in the chat reuse the cache.

### Also fixed in this step

**The AI tools failed open for users whose client match had not resolved**
(commit `e51ff44`). Scoping lived in two copies marked "do not let the two
drift". The controller's copy failed closed, the AI tools' copy did not, so
such a user could read the whole company through the chat. Confirmed with a
failing test before the fix. There is now one implementation, used by both.

### Deploying

No migrations and no queue restart: reports run inside web and chat requests,
not queued jobs. A web server that keeps code in memory (for example Octane, or
opcache without timestamp validation) needs reloading.

### Known limitations

- **Figures can lag QuickBooks.** A cached report is reused until the next sync
  or for 6 hours, so an edit made directly in QuickBooks can take that long to
  show.
- **The per-customer breakdown is income only.** QuickBooks does not attribute
  other income to customers, so the breakdown can sum to less than revenue.
- **A client-scoped user's expenses** are only those QuickBooks associates with
  their customers.
- **The dashboard change is type-checked and builds but has not been viewed in a
  browser.** It needs an admin login.
- **Chat lists did not cover the new entities at first.** Step 4 adds them.

### Verified

- **113 tests pass** (429 assertions), including `QuickBooksReportsTest` for
  parsing, filtering and caching, and `QuickBooksAiReportToolsTest` for the tools
  and the summary endpoint. Report fixtures follow live report structure,
  including the `account`, `not_specified` and `total` column keys.
- **Mutation checks.** Each deliberate break makes its test fail, 11 of 11:

  | Deliberate break | Result |
  |---|---|
  | An accounting method is sent, overriding the company basis | caught |
  | Client filter ids not sorted | caught |
  | Cache ignores new syncs | caught |
  | Parent-account totals dropped from expense accounts | caught |
  | Not-specified and total columns kept as customers | caught |
  | Other expenses left out of total expenses | caught |
  | No-data flag ignored | caught |
  | Unresolved non-admin gets whole-company figures | caught |
  | Scoped user loses their client filter | caught |
  | Rejected connection reported as a generic outage | caught |
  | Dashboard reports 0 instead of unavailable | caught |

  The last one was missed at first. `assertJson` compares values loosely, so it
  treated `null` and `0` as equal: the test would have passed a dashboard showing
  $0.00. Both summary tests now assert strictly with `assertJsonPath`, and the
  mutation is caught.
- **Live, against the sandbox realm:**
  - Whole company: all-time revenue 10,201.77, expenses 8,558.31 and net income
    1,643.46 on an accrual basis, identical to QuickBooks' own report.
  - Amy's Bird Sanctuary, a client-scoped user: revenue 630.00 in the tools with
    only her own name in the breakdown, and the same 630 from the summary endpoint
    through the HTTP kernel, with her open balance (239) and total invoiced (772)
    unchanged.
  - The same report twice: 1,631 ms, then 12 ms from the cache.
- **Not verified:** the dashboard in a browser, which needs an admin login.

## Step 3: answers from data still importing are qualified

After step 2, revenue, expense and profit figures are read live from QuickBooks,
so a sync in progress cannot skew them. Answers built from synced tables still
can: invoice lists and counts, customers, transactions, open balances. Before
this step, "you have 3 overdue invoices" read as final even while invoices were
still importing.

Tools that read synced tables (`get_invoices`, `get_customers`,
`get_transactions`, and the counts in `get_company_summary`) now attach
`data_freshness`:

```json
"data_freshness": {
  "complete": false,
  "still_importing": ["Invoices"],
  "failed_to_import": [],
  "last_synced_at": "2026-09-15T18:49:37+00:00"
}
```

- It covers only the entities that tool reads, so a customers sync in progress
  does not qualify an invoice list.
- A step that has never run for the company counts as still importing.
- `failed_to_import` means the latest attempt failed; what is shown may come
  from an earlier sync.
- `last_synced_at` is the oldest of the steps read, so no part of the answer is
  older than stated.

When `complete` is false, the system prompt tells the assistant to say the result
covers only data imported so far and to name what is still importing or failed.

### Also fixed: a refused token refresh crashed the dashboard

Found while testing this step. When Intuit refuses to refresh an access token,
the QuickBooks SDK throws `ServiceException`, which extends `\Exception`, not
`RuntimeException`. Every caller only caught `RuntimeException`, so the refusal
escaped them all:

| Caller | Before |
|---|---|
| Dashboard summary endpoint | HTTP 500 |
| AI report tools | a generic "tool failed" |
| Sync jobs | three backed-off retries of a refusal that could never succeed |

A deliberately bogus refresh token sent to Intuit came back as HTTP 400 with
`invalid_grant`. `refreshTokenIfNeeded` now translates SDK exceptions at the
boundary:

- **A refusal** (HTTP 400 or 401, or `invalid_grant`) becomes
  `QuickBooksReauthorizationRequired`. The dashboard shows "Reconnect
  QuickBooks", the chat says the same, and a sync closes out at once. The refresh
  token is also marked expired, so later requests stop immediately instead of
  asking Intuit to refuse them again. That write happens after the locked
  transaction rolls back, so it is not undone with it.
- **Any other SDK failure**, such as an outage, becomes a `RuntimeException`
  that callers already handle, and the connection is left alone.

**Not fixed here:** the OAuth callback has the same gap. The SDK's code exchange
throws `SdkException`, which the callback's `RuntimeException` handler does not
catch, so a bad or missing authorization code would fail with a server error
instead of redirecting to the portal's QuickBooks error page.

### Deploying

No migrations. Run `php artisan queue:restart` so workers pick up the refresh
handling in sync jobs.

### Verified

- **125 tests pass** (463 assertions), including `QuickBooksAiDataFreshnessTest`
  and `QuickBooksTokenRefreshFailureTest`. The refresh tests stand in for Intuit
  with the refusal a live call returned, so they need no network.
- **Mutation checks.** Each deliberate break makes its test fail, 9 of 9:

  | Deliberate break | Result |
  |---|---|
  | A refused refresh is not translated into reconnect-required | caught |
  | A refused refresh token is not recorded, so Intuit is asked again | caught |
  | An outage is treated as a refusal | caught |
  | SDK exceptions not caught at the boundary | caught |
  | Failed imports not reported | caught |
  | Every entity considered, not just the ones the tool reads | caught |
  | Never-synced data treated as complete | caught |
  | Newest sync time reported instead of the oldest | caught |
  | Summary checks the wrong entities | caught |

- **Live:** for a client-scoped user on the sandbox realm, `get_invoices` and
  `get_company_summary` reported `complete: true` with the real last sync time.
  A deliberately bogus refresh token sent to Intuit came back as HTTP 400,
  `invalid_grant`, as a `ServiceException` that is not a `RuntimeException`.
- **One test had been calling Intuit.** It time-travelled after creating its
  token, so the token looked expired and was refreshed over the network. That
  is how the refresh bug surfaced. The test now connects after the time travel.
- **Not verified live:** a refusal for a real, previously working connection.

## Step 4: the chat can list bills, payments, sales receipts and credit memos

Step 1 synced these entities, but until now only their effect on the Profit and
Loss figures reached the chat. Questions such as "which bills are overdue?" or
"which invoices did that payment cover?" had no tool to answer them.

| Tool | Lists | Scoping |
|---|---|---|
| `get_bills` | Supplier bills, with the balance still owed | Company-level: the whole company only |
| `get_payments` | Customer payments, with the invoices each settled | The caller's clients |
| `get_sales_receipts` | Sales paid on the spot | The caller's clients |
| `get_credit_memos` | Credits issued, with the credit still available | The caller's clients |

Each filters by period and by vendor or customer name, returns the most recent
first, and carries `data_freshness` for the entities it reads.

### Scoping notes

- **Bills belong to no client.** A bill is owed by the company to a supplier, so
  client scoping has nothing to narrow it by. Only a caller who sees the whole
  company (an admin, or a user tracking all clients) may list bills. A user
  limited to specific clients gets `company_level_data`, and one whose scope has
  not resolved gets `client_access_pending`. Neither gets any bill data.
- **A payment's invoices go through the same client scope.** Invoice numbers are
  looked up through the scoped invoice query, so a payment cannot reveal another
  client's invoice, even if QuickBooks linked one to it.
- **Payments read invoices too,** so their freshness covers both: an invoices
  sync in progress qualifies a payments answer.

### Known limitations

- **Bill and invoice status is worked out when the record syncs.** A bill that
  falls due between syncs still shows as Open until the next sync marks it
  Overdue.
- **The name filters match loosely** (`vendor`, `customer`): "lumber" matches
  every vendor with that word in its name.

### Deploying

No migrations and no queue restart: the tools run inside chat requests.

### Verified

- **134 tests pass** (494 assertions), including `QuickBooksAiEntityListToolsTest`.
- **Mutation checks.** Each deliberate break makes its test fail, 8 of 8:

  | Deliberate break | Result |
  |---|---|
  | Bills shown to client-scoped users | caught |
  | Company-level helper lets client-scoped users through | caught |
  | Payments not scoped to the caller | caught |
  | Linked invoice numbers not scoped | caught |
  | Payments ignore an invoices sync in progress | caught |
  | Sales receipts not scoped to the caller | caught |
  | Remaining credit summed from the wrong column | caught |
  | A list tool left unregistered | caught |

  One test assertion had compared a list with itself and so could never fail. It
  now checks what it was meant to: that a payments answer reports an invoices
  sync still in progress.
- **Live, against the sandbox realm:**

  | | Client-scoped user | Whole company | Synced totals |
  |---|---|---|---|
  | Bills | refused, `company_level_data` | 15, owed 1,602.67, 5 overdue | 15, 1,602.67, 5 |
  | Payments | 4, received 433.00, only her own | 16, received 4,752.62 | 16, 4,752.62 |
  | Sales receipts | 0 | 4, total 781.25 | 4, 781.25 |
  | Credit memos | 1, credited 100.00 | 1, credited 100.00, 0.00 left | 1, 100.00, 0.00 |

  Every company payment listed the invoices it settled, resolving to 14 distinct
  invoice numbers, the same 14 links found when the entities were first synced.
