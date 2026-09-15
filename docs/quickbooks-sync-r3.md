# QuickBooks sync, R3: complete data for the AI chat

R3 fills the gaps in what the portal stores and makes the AI chat answer from
complete data. It ships in steps:

| Step | Status |
|---|---|
| 1. Sync bills, payments, sales receipts and credit memos | Done |
| 2. Revenue, expenses and profit from the complete data, with client scoping | Not started |
| 3. QuickBooks' own reports, cached, for profit and loss questions | Not started |
| 4. The AI chat qualifies answers while data is still importing | Not started |

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
