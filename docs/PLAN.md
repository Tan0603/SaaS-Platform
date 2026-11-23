# SaaS Accounting System — Implementation Plan

Assessment window: 2026-11-23 → review 2026-11-30 (5 working days).

## 1. Stack decisions

| Area | Choice | Why |
|---|---|---|
| Framework | Laravel 12 + official Vue starter kit (Vue 3 + TS + Inertia + Tailwind + shadcn-vue) | Required; gives auth screens for free |
| Database | **PostgreSQL 16** | Strong `SELECT … FOR UPDATE`, partial indexes, `CHECK` constraints |
| Tenancy | **stancl/tenancy `^3.10`** (latest stable, supports Laravel 12), **single-database mode** | Spec says "all models tenant-scoped" → `tenant_id` column + `BelongsToTenant` global scope |
| Tenant identification | **Subdomain** (`acme.localhost`) via `InitializeTenancyBySubdomain` + `PreventAccessFromCentralDomains` | The canonical stancl approach. Users are tenant-scoped, so a login on `acme.localhost` can only ever match Acme users |
| Cache / Queue / Session | Redis (phpredis) | Required. Session cookie is per-subdomain |
| Money | `bigint` **cents** (`unit_price_cents`, `total_cents`, …) | No float rounding. Format only in the UI |
| Local env | Docker Compose (app, nginx, pgsql, redis, queue worker, scheduler, mailpit) | Bonus |

> Risk note: if subdomains are painful on the reviewer's machine, the fallback is path identification (`/t/{tenant}/…`, `InitializeTenancyByPath`). Everything else in this plan stays the same.

## 2. Requirement flow (how everything connects)

```
Browser (acme.localhost)
  │
  ▼
[InitializeTenancyBySubdomain] ──► tenancy()->initialize(acme)
  │        ├─ BelongsToTenant global scope: WHERE tenant_id = 'acme' on every query
  │        ├─ CacheTenancyBootstrapper: cache keys tagged/prefixed per tenant
  │        └─ QueueTenancyBootstrapper: tenant_id written into every job payload
  ▼
[auth] (Fortify login; the users table is tenant-scoped too)
  ▼
Controller ──► FormRequest (validation) ──► Action/Service (DB::transaction)
                                              │
                                              ├─ Invoice saved ─► InvoiceObserver ─► flush dashboard cache
                                              │                                  └─► SendInvoiceEmail job (Redis)
                                              └─ Payment saved ─► PaymentObserver ─► flush dashboard cache
  ▼
Inertia::render('Invoices/Show', props) ──► Vue page
```

### 2.1 Invoice lifecycle (state machine)

The stored `status` column holds **payment status only**. It is always derived from `SUM(payments)`:

| amount_paid | stored status |
|---|---|
| 0 | `unpaid` |
| 0 < paid < total | `partial` |
| paid == total | `paid` |
| paid > total | **impossible** (blocked by the service, a row lock and a DB `CHECK`) |

**Overdue is computed, never the source of truth:**
`is_overdue = due_date < today AND status != 'paid'`

- Model accessor `$invoice->is_overdue` and `$invoice->display_status` (returns `overdue` / `unpaid` / `partial` / `paid`)
- Query scope `Invoice::overdue()` → `whereDate('due_date','<',today())->where('status','!=','paid')`
- The daily job (section 10 of the spec) **does not change `status`**. Per tenant, it:
  1. finds invoices that `overdue()` returns and whose `overdue_notified_at` is null,
  2. stamps `overdue_notified_at` (an audit/notification marker, never read for status),
  3. dispatches an idempotent "invoice overdue" email,
  4. flushes that tenant's dashboard cache, because the overdue totals change with the date.

  This satisfies "daily job marks overdue" and "overdue is computed, not stored truth" at the same time. **Write this reasoning in the README**, because reviewers will look for it.

### 2.2 Create / update an invoice

1. `StoreInvoiceRequest` validates the customer (must exist **in this tenant**, using `Rule::exists` with a tenant scope), the dates (`due_date >= issue_date`), and `items.*` (`description`, `quantity > 0` as an integer or decimal(10,2), `unit_price >= 0`).
2. `CreateInvoiceAction` (in `DB::transaction`):
   - creates the invoice with a number from the per-tenant sequence (`INV-000123`, unique on `(tenant_id, number)`),
   - inserts the items with `line_total_cents = round(qty * unit_price_cents)`,
   - `InvoiceCalculator` sets `subtotal_cents = Σ line_total`, `total_cents = subtotal` (+ tax if it is added later),
   - **client-sent totals are ignored.** The server always recalculates.
3. Update: same flow, in a transaction with a row lock on the invoice. **Reject** the change if the new total would be below `amount_paid_cents`. Recalculate the status afterwards.
4. Delete: only allowed when there are no payments (otherwise void/cancel; out of scope).

### 2.3 Record a payment (the most important piece; automatic-fail risk)

`RecordPaymentAction::execute(Invoice $invoice, PaymentData $data)`:

```php
DB::transaction(function () use ($invoiceId, $data) {
    $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail(); // tenant-scoped + row lock
    $paid    = $invoice->payments()->sum('amount_cents');                      // fresh SQL sum, not the cached column
    $balance = $invoice->total_cents - $paid;
    if ($data->amount_cents <= 0 || $data->amount_cents > $balance) {
        throw OverpaymentException::forInvoice($invoice, $balance);             // → 422 validation error
    }
    $invoice->payments()->create([...]);
    $invoice->recalculatePaymentState();   // amount_paid_cents = SUM(), status = unpaid|partial|paid
});
```

Defence in depth: FormRequest (UX message) → service check under the row lock (concurrency) → DB `CHECK (amount_paid_cents <= total_cents)` (last line of defence).

### 2.4 Dashboard (tenant-scoped, cached)

The cache key is `dashboard:stats:{Y-m-d}`. The tenant prefix is added automatically by the CacheTenancyBootstrapper. TTL is 10 minutes.
`DashboardStatsService` uses a **single SQL aggregation query** with `SUM(CASE WHEN …)`:
- current month revenue = `SUM(payments.amount_cents)` where `paid_at` is in the current month (cash basis; document this choice)
- outstanding total = `SUM(total_cents - amount_paid_cents)` where `status != paid`
- paid invoices total = `SUM(total_cents)` where `status = paid`
- overdue total = `SUM(total_cents - amount_paid_cents)` where `due_date < today AND status != paid`

Invalidation: `InvoiceObserver` / `PaymentObserver` (`saved`, `deleted`) → `Cache::forget(...)` via `DashboardCache::flush()`. Use `DB::afterCommit` so a rolled-back write does not clear the cache too early.

### 2.5 Reports (SQL aggregation only, paginated)

- **Sales report** (`from`, `to`): invoices issued in the range, grouped by day/month: count, `SUM(total)`, `SUM(amount_paid)`.
- **Outstanding invoices**: `status != paid`, with a balance column and an overdue flag, sorted by `due_date`, paginated.
- **Customer invoice summary**: `customers LEFT JOIN invoices GROUP BY customer`: invoice count, total billed, total paid, balance, overdue amount.

### 2.6 Queued email (idempotent + retry-safe)

- `SendInvoiceEmail implements ShouldQueue, ShouldBeUnique` with `$tries = 5`, `backoff = [10, 60, 300]`, `uniqueId = "{tenant}:{invoice}:{event}"`.
- Idempotency table `invoice_email_logs` (`tenant_id`, `invoice_id`, `event` [`created` | `overdue`], `sent_at`) with a **UNIQUE (tenant_id, invoice_id, event)** constraint.
- Job flow: `insertOrIgnore` a "sending" row → if no row was inserted and `sent_at` is set, return (already sent) → send the mail → set `sent_at`. A retry after a crash re-sends only if `sent_at` is still null.
- Dispatch with `->afterCommit()` so the job never sees an uncommitted invoice.
- Tenant context comes from `QueueTenancyBootstrapper`. A test asserts that the job runs inside the correct tenant.

### 2.7 Scheduler (tenant-aware)

```php
// routes/console.php
Schedule::command('tenants:run invoices:mark-overdue')->dailyAt('00:05')->withoutOverlapping()->onOneServer();
```
`invoices:mark-overdue` assumes tenancy is already initialized, so it relies on the global scope. A second, defensive variant is `Tenant::all()->runForEach(fn () => MarkOverdueInvoices::dispatch())`.

## 3. Database schema (ERD)

```
tenants (id string PK, name, data json, timestamps)          ← stancl
domains (id, domain UNIQUE, tenant_id FK)                    ← stancl

users     (id, tenant_id FK, name, email, password, …)       UNIQUE(tenant_id, email)
customers (id, tenant_id FK, name, email, phone, address, timestamps, soft deletes)
            INDEX(tenant_id, name)  UNIQUE(tenant_id, email) nullable
invoices  (id, tenant_id FK, customer_id FK, number, issue_date, due_date,
           status enum[unpaid,partial,paid], subtotal_cents, total_cents,
           amount_paid_cents default 0, overdue_notified_at null, timestamps)
            UNIQUE(tenant_id, number)
            INDEX(tenant_id, status, due_date)     ← overdue/outstanding lookups
            INDEX(tenant_id, issue_date)           ← sales report
            INDEX(tenant_id, customer_id)          ← FK + customer summary
            CHECK(amount_paid_cents >= 0 AND amount_paid_cents <= total_cents)
invoice_items (id, tenant_id FK, invoice_id FK cascade, description, quantity decimal(10,2),
           unit_price_cents, line_total_cents, position)   INDEX(invoice_id)
payments  (id, tenant_id FK, invoice_id FK restrict, amount_cents CHECK>0, paid_at date,
           method, reference, timestamps)
            INDEX(tenant_id, invoice_id)            ← SUM per invoice
            INDEX(tenant_id, paid_at)               ← monthly revenue
invoice_email_logs (id, tenant_id, invoice_id, event, sent_at null, timestamps)
            UNIQUE(tenant_id, invoice_id, event)
```

Every business model uses the `BelongsToTenant` trait. `tenant_id` is kept on child tables (items, payments) **as well**, so they are scoped directly and not only through a relationship. This is the strongest isolation for "relationships". Composite FKs are optional.

Write this as a Mermaid ERD in `docs/ERD.md`.

## 4. Folder structure

```
SaaS-Platform/
├── app/
│   ├── Actions/                       # one class = one business operation
│   │   ├── Invoices/
│   │   │   ├── CreateInvoiceAction.php
│   │   │   ├── UpdateInvoiceAction.php
│   │   │   └── DeleteInvoiceAction.php
│   │   └── Payments/
│   │       └── RecordPaymentAction.php
│   ├── Console/Commands/
│   │   └── MarkOverdueInvoices.php    # invoices:mark-overdue (run via tenants:run)
│   ├── Data/                          # small DTOs (InvoiceData, InvoiceItemData, PaymentData)
│   ├── Enums/
│   │   ├── InvoiceStatus.php          # unpaid | partial | paid  (+ displayStatus() helper incl. overdue)
│   │   └── InvoiceEmailEvent.php      # created | overdue
│   ├── Exceptions/
│   │   └── OverpaymentException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── DashboardController.php
│   │   │   ├── CustomerController.php
│   │   │   ├── InvoiceController.php
│   │   │   ├── PaymentController.php
│   │   │   └── ReportController.php
│   │   ├── Middleware/
│   │   │   └── HandleInertiaRequests.php   # shares auth user + tenant name + flash
│   │   └── Requests/
│   │       ├── Customers/{Store,Update}CustomerRequest.php
│   │       ├── Invoices/{Store,Update}InvoiceRequest.php
│   │       ├── Payments/StorePaymentRequest.php
│   │       └── Reports/DateRangeRequest.php
│   ├── Jobs/
│   │   └── SendInvoiceEmail.php
│   ├── Mail/
│   │   ├── InvoiceCreatedMail.php
│   │   └── InvoiceOverdueMail.php
│   ├── Models/
│   │   ├── Tenant.php                 # extends Stancl Tenant, HasDomains
│   │   ├── User.php                   # BelongsToTenant
│   │   ├── Customer.php
│   │   ├── Invoice.php                # scopes: overdue(), outstanding(); accessors: is_overdue, display_status, balance
│   │   ├── InvoiceItem.php
│   │   ├── Payment.php
│   │   └── InvoiceEmailLog.php
│   ├── Observers/
│   │   ├── InvoiceObserver.php        # flush dashboard cache, dispatch created email (afterCommit)
│   │   └── PaymentObserver.php        # flush dashboard cache
│   ├── Policies/                      # extra safety: user->tenant_id === model->tenant_id
│   │   ├── CustomerPolicy.php
│   │   └── InvoicePolicy.php
│   ├── Providers/
│   │   ├── AppServiceProvider.php     # Model::preventLazyLoading() in non-prod (N+1 guard)
│   │   └── TenancyServiceProvider.php # stancl events → bootstrappers
│   └── Services/
│       ├── InvoiceCalculator.php      # pure: items → line totals, subtotal, total
│       ├── DashboardStatsService.php  # single SQL aggregate + cache
│       ├── DashboardCache.php         # key + flush helper
│       └── Reports/
│           ├── SalesReport.php
│           ├── OutstandingInvoicesReport.php
│           └── CustomerSummaryReport.php
├── config/tenancy.php                 # single-DB: DatabaseTenancyBootstrapper removed
├── database/
│   ├── factories/                     # Tenant, User, Customer, Invoice (+ states: paid(), partial(), overdue()), InvoiceItem, Payment
│   ├── migrations/
│   └── seeders/
│       ├── DatabaseSeeder.php         # 2–3 demo tenants + login users
│       ├── DemoTenantSeeder.php       # small, readable data
│       └── PerformanceSeeder.php      # 500 customers / 5k invoices / 15k items / 5k payments per tenant (chunked bulk inserts)
├── resources/js/
│   ├── components/
│   │   ├── ui/                        # shadcn-vue (from the starter kit)
│   │   ├── DataTable.vue              # table + empty state + pagination
│   │   ├── EmptyState.vue
│   │   ├── StatusBadge.vue            # unpaid/partial/paid/overdue
│   │   ├── MoneyInput.vue
│   │   └── InvoiceItemsEditor.vue     # dynamic rows, live preview of totals (server is authoritative)
│   ├── composables/
│   │   └── useMoney.ts                # cents ↔ display formatting
│   ├── layouts/AppLayout.vue
│   ├── pages/
│   │   ├── auth/Login.vue             # from the starter kit
│   │   ├── Dashboard.vue
│   │   ├── customers/{Index,Create,Edit,Show}.vue
│   │   ├── invoices/{Index,Create,Edit,Show}.vue   # Show contains the payment form + payment history
│   │   ├── payments/Index.vue
│   │   └── reports/{Sales,Outstanding,CustomerSummary}.vue
│   └── types/index.d.ts
├── routes/
│   ├── web.php                        # central domain only (landing / tenant picker)
│   ├── tenant.php                     # all app routes: subdomain + auth middleware
│   └── console.php                    # schedule
├── tests/
│   ├── TestCase.php                   # helper: actingAsTenantUser(), tenantUrl()
│   ├── Feature/
│   │   ├── Auth/AuthenticationTest.php            # login, logout, wrong tenant can't log in
│   │   ├── Tenancy/TenantIsolationTest.php        # 404 on another tenant's customer/invoice/payment; dashboard numbers isolated
│   │   ├── Invoices/InvoiceCreationTest.php       # totals computed server-side, client totals ignored, validation
│   │   ├── Payments/PaymentProcessingTest.php     # partial → partial, full → paid, overpay → 422, exact remaining ok, sequential overpay
│   │   ├── Invoices/OverdueLogicTest.php          # travelTo(): overdue on due_date+1, not on due_date, never when paid, scheduler per tenant
│   │   ├── Jobs/SendInvoiceEmailTest.php          # idempotent (run twice → 1 mail), runs in correct tenant
│   │   └── Dashboard/DashboardCacheTest.php       # cached, invalidated on invoice/payment change
│   └── Unit/InvoiceCalculatorTest.php
├── docker/ (nginx.conf, php.Dockerfile)
├── docker-compose.yml
├── docs/
│   ├── PLAN.md                        # this file
│   ├── ERD.md                         # mermaid ERD + index rationale
│   └── ARCHITECTURE.md                # tenancy, overdue decision, idempotency, caching
└── README.md                          # setup, demo logins, hosts (acme.localhost), commands
```

All tests use `RefreshDatabase` + factories, as the spec requires.

## 5. Five-day schedule

| Day | Goal | Done when |
|---|---|---|
| **1 (Mon 11-23)** | Scaffold: Laravel 12 Vue kit, Postgres + Redis (Docker), stancl install in single-DB mode, subdomain routes, tenant-scoped users, login works per tenant | `AuthenticationTest` + a basic isolation test are green |
| **2 (Tue)** | Schema + models + factories; Customers CRUD (backend + Vue); Invoice create/edit with items + `InvoiceCalculator` | `InvoiceCreationTest` green |
| **3 (Wed)** | `RecordPaymentAction` (transaction + lock + check); status recalculation; overdue scope/accessor; daily command + schedule; email job + idempotency log | Payment + overdue + job tests green |
| **4 (Thu)** | Dashboard (SQL aggregate + cache + invalidation); 3 reports; loading/error/empty states; `PerformanceSeeder` | Dashboard cache test green; pages fast with ~5k invoices |
| **5 (Fri)** | Hardening: full isolation test sweep, N+1 check (`preventLazyLoading`), indexes via `EXPLAIN`; README, ERD, ARCHITECTURE; final review | All tests green, docs complete |

## 6. Automatic-fail checklist (verify before submission)

- [ ] Data leak: every model has `BelongsToTenant`; cross-tenant ID returns 404 (test for customers, invoices, payments, reports, dashboard)
- [ ] `Rule::exists('customers','id')` is scoped to the tenant (a plain `exists` rule bypasses the Eloquent scope!)
- [ ] Overpayment: blocked in the FormRequest, in the service under `lockForUpdate`, and by a DB `CHECK`
- [ ] Totals: always recalculated on the server, stored in integer cents
- [ ] Status: only `recalculatePaymentState()` writes it; overdue only computed
- [ ] Queue: `QueueTenancyBootstrapper` enabled; test that asserts `tenant()->id` inside the job
- [ ] Scheduler: runs through `tenants:run` / `runForEach`; test with 2 tenants

## 7. Agent workflow

- **Opus**: this plan and a review of each day's diff against section 6.
- **Sonnet**: implements one day block at a time, following this file.
- **Haiku**: version and documentation lookups, runs `php artisan test` / `npm run build`, reports failures.
