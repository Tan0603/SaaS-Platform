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
