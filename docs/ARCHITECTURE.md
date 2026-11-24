# Architecture notes

Short design notes for the SaaS accounting system. Section numbers refer to [PLAN.md](../PLAN.md).

## Why "overdue" is computed, not stored

An invoice has two independent fields:

| Field | Values | Written by |
|---|---|---|
| `state` | `draft`, `issued`, `void` | issue / void actions |
| `status` | `unpaid`, `partial`, `paid` | `Invoice::recalculatePaymentState()` only |

**Overdue is neither.** It is a fact about *today*:

```
overdue  =  state = 'issued'  AND  due_date < today  AND  status != 'paid'
```

### The problem with storing it

If `status = 'overdue'` were saved in the database, it would be true only until something changes, and nothing
changes it at midnight except a job:

1. **It goes stale.** An invoice due yesterday stays `unpaid` until the scheduler runs. If the scheduler is late,
   down, or fails for one tenant, the dashboard and reports show wrong numbers. "Invoice state logic is unreliable"
   is an automatic fail.
2. **It needs a transition back.** A payment on an overdue invoice must move it to `partial` or `paid`, and a voided
   payment must move it back. That is a second writer of `status`, which breaks the rule that
   `recalculatePaymentState()` is the only writer.
3. **It mixes two questions.** "How much has been paid?" (`status`) and "is it late?" (a date comparison) have
   different inputs. One column cannot hold both without losing the payment state (a late *partial* invoice would
   have to forget that it is partial).

### What we do instead

The database stores only facts that do not depend on the current date (`state`, `status`, `due_date`, amounts).
"Overdue" is derived every time it is read, in the two places it is needed, and both use the same rule:

| Where | Code | Used for |
|---|---|---|
| SQL | `Invoice::scopeOverdue()` | invoice list filter, dashboard totals, reports, the daily reminder job |
| PHP | `Invoice::is_overdue` accessor and `display_status` | status badge on a single row |

`OverdueLogicTest` checks that the scope and the accessor always agree, and `travelTo()` is used to prove the
boundary: on the due date itself the invoice is **not** overdue, the next day it **is**.

The list filters mirror the badge. `display_status` shows `overdue` instead of `unpaid`/`partial`, so the
`unpaid` and `partial` filters (`Invoice::scopeWithDisplayStatus()`) exclude overdue invoices and the `overdue`
filter returns exactly the rows that show the Overdue badge.

### What the daily job is for

The scheduled command (Phase 6/7) never decides what is overdue. It runs once per tenant, asks
`Invoice::overdue()` the same question as everyone else, and for invoices not yet notified it records
`overdue_notified_at` and queues a reminder email. That timestamp only prevents sending the reminder twice. It is
not the truth: an invoice is overdue because of its dates and payments, whether or not the job has run.

### Consequences

- A payment never has to "un-overdue" anything: `recalculatePaymentState()` updates `amount_paid_cents` and
  `status`, and the next read of the scope or accessor is already correct.
- Paying in full, partially, or voiding a payment all move the invoice in and out of overdue without extra code.
- Drafts and void invoices are never overdue, because the rule starts with `state = 'issued'`.
- The index `(tenant_id, state, status, due_date)` on `invoices` serves the overdue query.

## How queued jobs keep the tenant context

A queue worker has no logged-in user and no request, so nothing tells it which company a job belongs to. We use
stancl's `QueueTenancyBootstrapper` (enabled in `config/tenancy.php`):

1. **Dispatch.** When a job is dispatched while a tenant is initialised, the bootstrapper adds `tenant_id` to the
   job payload (the `jobs` row / Redis entry).
2. **Work.** Before the worker runs `handle()`, the `JobProcessing` listener initialises that tenant; after the job
   (success or failure) it ends tenancy again. Each job therefore starts in its own tenant and leaves none behind.
3. **Inside the job.** `Invoice::find()` is filtered by `BelongsToTenant`, so a job holding an id from another
   tenant finds nothing and exits. Jobs carry plain ids (`int $invoiceId`), never serialized models.

`SendInvoiceEmail` is dispatched with `->afterCommit()`, and always from inside tenancy (a `PendingDispatch` pushes
when it is destroyed, so it must be consumed in the tenant's scope). Tests cover this with a real database queue:
dispatch in tenant A, end tenancy, run `queue:work --once`, and assert the mail was built for A.

`ShouldBeUnique` is kept: its lock key is `"{tenant}:{invoice}:{event}"`, so tenants cannot block each other, and it
is released by the worker (which has the tenant initialised, so the key matches). `uniqueFor` is one hour so a lock
left by a dispatch whose transaction rolled back cannot block the invoice forever. It is only an optimisation; the
database is what guarantees "once".

## Email idempotency

Exactly one email per `(tenant, invoice, event)` is enforced by the database, not by the queue:

- `invoice_email_logs` has `UNIQUE (tenant_id, invoice_id, event)`.
- The job claims the slot with `INSERT ... ON CONFLICT DO NOTHING`, then, in a transaction, locks that row
  (`FOR UPDATE`). If `sent_at` is set it exits; otherwise it sends the mail and sets `sent_at`.
- Duplicate dispatch or two workers at once: the second waits for the row lock, sees `sent_at`, and exits.
- Crash or SMTP error before `sent_at` is written: the transaction rolls back (or leaves `sent_at` null), so the
  retry (`$tries = 5`, backoff 10 s / 60 s / 300 s) sends it. The one unavoidable gap is a crash after SMTP accepted
  the mail but before the commit, which gives at-least-once delivery in that rare case.
- The job re-checks the invoice at run time: drafts and void invoices never email, and an overdue reminder is
  skipped if the invoice was paid in the meantime.

## The scheduler runs per tenant

The daily command `invoices:mark-overdue` is scheduled as:

```php
Schedule::command('tenants:run invoices:mark-overdue')->dailyAt('00:05')->withoutOverlapping()->onOneServer();
```

`tenants:run` loops over every tenant, initialises it, runs our command, and ends tenancy before the next one, so
`Invoice::overdue()` only ever sees one tenant's invoices. The command refuses to run (exit code 1) when tenancy is
not initialised, so a direct `php artisan invoices:mark-overdue` cannot touch every tenant by accident.

For each overdue invoice without `overdue_notified_at` it stamps that column and queues one overdue email job.
It never changes `status`: overdue stays computed. The `overdue_notified_at` marker (plus the unique log row) makes
the daily run safe to repeat.

## Dashboard caching

The dashboard shows four numbers per tenant, all from one SQL statement (`DashboardStatsService`, `FILTER` aggregates
plus a tenant-scoped payments sub-select; no PHP loops). Only issued invoices and non-voided payments count; drafts,
void invoices and voided payments never do.

| Number | Definition |
|---|---|
| Revenue this month | sum of non-voided payments with `paid_at` in the current calendar month (cash basis) |
| Outstanding | sum of `total - paid` of issued invoices that are not paid |
| Paid | sum of `total` of issued paid invoices |
| Overdue | outstanding balance of issued, not-paid invoices with `due_date < today` (same rule as `Invoice::overdue()`) |

Dates are computed in PHP and bound as parameters (not `CURRENT_DATE`), so the dashboard and the invoice list always
agree, including under `travelTo()` in tests.

**Cache.** `DashboardCache` stores the result (cards and charts together) for 10 minutes under `dashboard:data:<today>`. `CacheTenancyBootstrapper`
tags every call with the current tenant, so Acme and Globex never share an entry. The date in the key makes the overdue
number change at midnight without any job. With no tenant initialised nothing is cached or flushed.

**Invalidation.** `InvoiceObserver` and `PaymentObserver` (created / updated / deleted) call
`DB::afterCommit(fn () => DashboardCache::flush())`. Waiting for the commit matters: flushing inside the transaction
would let a concurrent request re-cache the old numbers before the new ones are visible, and a rolled-back change
would flush for nothing. `flush()` forgets today's key only (older dated keys expire by TTL), which is why a version
counter was not needed. `invoices:mark-overdue` uses `saveQuietly()` (no observers), so it flushes explicitly.
Remaining gap: a request that read the database just before a commit and writes the cache just after the flush can
keep slightly old numbers until the 10-minute TTL ends.

**Charts.** `DashboardChartsService` adds three charts to the same cached payload (`{stats, charts}`), so one flush
invalidates cards and charts together. Each is one grouped SQL statement on the tenant-scoped models; the only PHP loop
is over the 12 month buckets (zero-filling). Drafts, void invoices and voided payments are excluded everywhere.

| Chart | Definition |
|---|---|
| Invoiced vs collected | last 12 calendar months (this month and the 11 before): `SUM(total)` of issued invoices by `issue_date`, and `SUM(amount)` of non-voided payments by `paid_at`, `GROUP BY date_trunc('month', ...)` |
| Invoices by status | issued invoices in four non-overlapping groups, exactly like the badges / list filters: overdue (past due, not paid), paid, and unpaid / partial that are not overdue (one `FILTER` query; count and sum of totals) |
| Top 5 customers | `SUM(total - paid)` of issued, not-paid invoices per customer (archived customers included), largest first; the customers join repeats `tenant_id` |

**Live refresh.** `Dashboard.vue` uses Inertia's `usePoll(30000)` to reload only the `stats`, `charts` and `month` props
every 30 seconds (paused while the tab is hidden). The server answers from the cache, so polling is cheap; a new
payment or invoice flushes the cache and shows up on the next poll. The page shows both the data time and the last check.
