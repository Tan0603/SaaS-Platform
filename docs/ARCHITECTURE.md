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
