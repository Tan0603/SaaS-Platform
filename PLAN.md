# PLAN.md — SaaS Accounting System (phase by phase)

Assessment: start 2026-11-23 → review 2026-11-30.
Work **one phase at a time**. A phase is done only when every item in its **Exit criteria** is checked. Then commit, push, and get an Opus review before starting the next phase.

| Role | Model | Job in each phase |
|---|---|---|
| Planner / reviewer | Opus | Keeps this file up to date; reviews each phase's diff against the Exit criteria and section B |
| Coder | Sonnet | Implements the phase's tasks |
| Researcher / tester | Haiku | Looks up docs, runs `php artisan test` / `npm run build`, reports failures |

---

## A. Fixed decisions (apply to every phase)

| Area | Decision |
|---|---|
| Framework | Laravel 12 + official Vue starter kit (Vue 3, TypeScript, Inertia, Tailwind, shadcn-vue, Fortify auth) |
| Database | PostgreSQL 16 |
| Tenancy | `stancl/tenancy:^3.10`, **single-database** mode (`tenant_id` column + `BelongsToTenant` trait on every business model) |
| Tenant identification | Subdomain (`acme.localhost`) via `InitializeTenancyBySubdomain` + `PreventAccessFromCentralDomains`. Fallback if needed: path identification `/t/{tenant}` |
| Cache / queue / session | Redis |
| Money | Integer **cents** (`bigint`). Never floats |
| Invoice `status` column | Only `unpaid` / `partial` / `paid`, always derived from `SUM(payments)` |
| Overdue | **Computed**: `due_date < today AND status != paid`. Never stored as the status |
| Business logic location | `app/Actions` (write operations) and `app/Services` (calculations, reads). Controllers stay thin |

## B. Automatic-fail guardrails (check in every review)

- [ ] No cross-tenant access: every model uses `BelongsToTenant`; another tenant's ID returns 404
- [ ] Validation `exists` rules are tenant-scoped (a plain `exists:customers,id` bypasses the Eloquent scope)
- [ ] Overpayment is impossible: FormRequest check → service check under `lockForUpdate` → DB `CHECK` constraint
- [ ] Invoice totals are always recalculated on the server; client totals are ignored
- [ ] Only `Invoice::recalculatePaymentState()` writes `status` / `amount_paid_cents`
- [ ] Queued jobs run in the correct tenant (`QueueTenancyBootstrapper`)
- [ ] The scheduler runs per tenant (`tenants:run` / `runForEach`)

---

## Phase 0 — Project setup & environment

**Goal:** an empty Laravel 12 + Vue app that runs in Docker with Postgres, Redis and Mailpit.

Tasks
- [ ] `laravel new` with the Vue starter kit, in the repository root
- [ ] `docker-compose.yml`: `app` (PHP 8.3-fpm with `pdo_pgsql` + `redis`), `nginx`, `pgsql`, `redis`, `queue` (`php artisan queue:work`), `scheduler` (`php artisan schedule:work`), `mailpit`
- [ ] `docker/php.Dockerfile`, `docker/nginx.conf` (with `server_name *.localhost localhost`)
- [ ] `.env.example`: `DB_CONNECTION=pgsql`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `MAIL_HOST=mailpit`
- [ ] `phpunit.xml`: tests run on a Postgres test database (not SQLite, because `CHECK` constraints and locking must behave like production)
- [ ] Turn on `Model::preventLazyLoading(! app()->isProduction())` and `Model::shouldBeStrict()` in `AppServiceProvider`

Exit criteria
- [ ] `docker compose up -d` → the starter kit's welcome page loads
- [ ] `php artisan test` passes (starter kit default tests)
- [ ] `npm run build` succeeds

---

## Phase 1 — Multi-tenancy foundation & authentication

**Goal:** every request runs inside one tenant; users log in only on their own company's subdomain.

Tasks
- [ ] `composer require stancl/tenancy:^3.10` → `php artisan tenancy:install`
- [ ] `config/tenancy.php`: remove `DatabaseTenancyBootstrapper`; keep `CacheTenancyBootstrapper`, `QueueTenancyBootstrapper`; set `central_domains` = `['localhost']`
- [ ] `app/Models/Tenant.php` (extends the stancl Tenant, `HasDomains`)
- [ ] Migration: add `tenant_id` to `users`; replace the unique `email` index with `UNIQUE(tenant_id, email)`
- [ ] `User` uses `BelongsToTenant`
- [ ] `routes/tenant.php`: middleware `web`, `InitializeTenancyBySubdomain`, `PreventAccessFromCentralDomains`. Move the starter kit's auth + dashboard routes here
- [ ] `routes/web.php`: central domain only (a simple landing page listing the demo tenant URLs)
- [ ] `HandleInertiaRequests`: share `tenant` (id, name) and the flash messages
- [ ] Turn off public registration (users are created by the seeder only)
- [ ] `tests/TestCase.php` helpers: `createTenant('acme')`, `tenantUrl('acme', '/path')`, `actingAsTenantUser()`

Tests
- [ ] `Feature/Auth/AuthenticationTest`: login on own subdomain succeeds; logout works; wrong password fails; **an Acme user cannot log in on `globex.localhost`**

Exit criteria
- [ ] Logging in at `acme.localhost` works; the same credentials fail at `globex.localhost`
- [ ] Auth tests are green

---

## Phase 2 — Database schema, models & factories

**Goal:** the full schema with indexes and constraints, the models, and factories that every later test uses.

Tasks — migrations (all with `tenant_id` FK → `tenants.id`, `cascadeOnDelete`)
- [ ] `customers`: name, email (nullable), phone, address, timestamps, soft deletes · `INDEX(tenant_id, name)`, `UNIQUE(tenant_id, email)`
- [ ] `invoices`: customer_id FK (`restrictOnDelete`), number, issue_date, due_date, status (`unpaid` default), subtotal_cents, total_cents, amount_paid_cents (default 0), overdue_notified_at (nullable), timestamps
  - `UNIQUE(tenant_id, number)`, `INDEX(tenant_id, status, due_date)`, `INDEX(tenant_id, issue_date)`, `INDEX(tenant_id, customer_id)`
  - `CHECK (amount_paid_cents >= 0 AND amount_paid_cents <= total_cents)`, `CHECK (due_date >= issue_date)`
- [ ] `invoice_items`: invoice_id FK (cascade), description, quantity `decimal(10,2)`, unit_price_cents, line_total_cents, position · `INDEX(invoice_id)`
- [ ] `payments`: invoice_id FK (restrict), amount_cents `CHECK > 0`, paid_at (date), method, reference (nullable), timestamps · `INDEX(tenant_id, invoice_id)`, `INDEX(tenant_id, paid_at)`
- [ ] `invoice_email_logs`: invoice_id, event, sent_at (nullable), timestamps · `UNIQUE(tenant_id, invoice_id, event)`

Tasks — code
- [ ] Models `Customer`, `Invoice`, `InvoiceItem`, `Payment`, `InvoiceEmailLog`, all with `BelongsToTenant`, relationships, and `casts`
- [ ] `app/Enums/InvoiceStatus.php` (`Unpaid`, `Partial`, `Paid`) and `InvoiceEmailEvent.php` (`Created`, `Overdue`)
- [ ] `Invoice`: `scopeOverdue()`, `scopeOutstanding()`, accessors `is_overdue`, `display_status`, `balance_cents`
- [ ] Factories for every model, with `Invoice` states `paid()`, `partial()`, `overdue()`, `withItems(n)`
- [ ] `docs/ERD.md`: Mermaid ERD + one line of reasoning per index

Tests
- [ ] `Feature/Tenancy/TenantIsolationTest` (model level): with two tenants seeded, `Customer::count()` / `Invoice::count()` only see the current tenant; a relationship query cannot load another tenant's rows

Exit criteria
- [ ] `php artisan migrate:fresh` runs cleanly on Postgres
- [ ] Isolation tests are green

---

## Phase 3 — Customer management (backend + UI)

**Goal:** full CRUD for customers, inside the tenant.

Tasks
- [ ] `CustomerController` (resource: index with search + pagination, create, store, show, edit, update, destroy)
- [ ] `StoreCustomerRequest` / `UpdateCustomerRequest` (email unique **per tenant**)
- [ ] `CustomerPolicy` (defence in depth: `$user->tenant_id === $customer->tenant_id`)
- [ ] Block deleting a customer who has invoices (friendly error message)
- [ ] Shared Vue components: `DataTable.vue`, `EmptyState.vue`, `Pagination.vue`, flash/toast
- [ ] Pages `customers/{Index,Create,Edit,Show}.vue`, with loading (`form.processing`), error (field errors from the server) and empty states

Tests
- [ ] CRUD happy path; validation errors; another tenant's customer ID → 404 on show/update/delete

Exit criteria
- [ ] Customers can be managed in the browser; tests are green

---

## Phase 4 — Invoice management with line items

**Goal:** create, edit and view invoices with multiple items; totals always correct.

Tasks
- [ ] `app/Services/InvoiceCalculator.php` (pure): items → `line_total_cents = round(qty × unit_price_cents)`, `subtotal_cents`, `total_cents`
- [ ] `app/Data/InvoiceData.php`, `InvoiceItemData.php` (DTOs built from the validated request)
- [ ] `StoreInvoiceRequest` / `UpdateInvoiceRequest`: `customer_id` exists **in this tenant**; `due_date >= issue_date`; `items` min 1; `quantity > 0`; `unit_price >= 0`
- [ ] `CreateInvoiceAction`: `DB::transaction`; per-tenant invoice number (`INV-000001`, lock the last row to avoid duplicates); insert items; save the calculated totals
- [ ] `UpdateInvoiceAction`: lock the invoice; replace the items; recalculate; **reject the edit if the new total < `amount_paid_cents`**; then `recalculatePaymentState()`
- [ ] `DeleteInvoiceAction`: only allowed when the invoice has no payments
- [ ] `InvoiceController` index (filter by status incl. overdue; eager-load `customer`), show (eager-load `items`, `payments`)
- [ ] Vue: `InvoiceItemsEditor.vue` (add/remove rows, live preview of totals), `StatusBadge.vue`, `composables/useMoney.ts`, pages `invoices/{Index,Create,Edit,Show}.vue`

Tests
- [ ] `Feature/Invoices/InvoiceCreationTest`: totals calculated by the server; tampered client totals ignored; validation (no items, bad dates, another tenant's customer); invoice numbers are unique and sequential per tenant
- [ ] `Unit/InvoiceCalculatorTest`: rounding and multiple items

Exit criteria
- [ ] Invoices with several items can be created and edited in the browser; tests are green

---

## Phase 5 — Payments & invoice status (highest risk)

**Goal:** record payments safely; status always correct; overpayment impossible.

Tasks
- [ ] `Invoice::recalculatePaymentState()`: `amount_paid_cents = SUM(payments.amount_cents)` → status `unpaid` / `partial` / `paid`
- [ ] `RecordPaymentAction`: `DB::transaction` → `Invoice::lockForUpdate()` → fresh `SUM` → reject if `amount <= 0` or `amount > balance` (`OverpaymentException` → 422 field error) → create the payment → `recalculatePaymentState()`
- [ ] `StorePaymentRequest` (amount, paid_at not in the future, method)
- [ ] `PaymentController@store` (nested under invoice) and `PaymentController@index` (payments list, eager-load `invoice.customer`, paginated, date filter)
- [ ] Payment form + payment history on `invoices/Show.vue` (shows the remaining balance; button disabled when paid); `payments/Index.vue`

Tests — `Feature/Payments/PaymentProcessingTest`
- [ ] Partial payment → status `partial`, balance correct
- [ ] Payments adding up exactly to the total → `paid`
- [ ] Payment > balance → 422, no row inserted, status unchanged
- [ ] Second payment that would exceed the remaining balance → rejected
- [ ] Zero / negative amount → rejected
- [ ] Payment on another tenant's invoice → 404
- [ ] DB constraint test: a direct update setting `amount_paid_cents > total_cents` throws

Exit criteria
- [ ] All payment tests are green; guardrail B is re-checked by the Opus review

---

## Phase 6 — Overdue logic & daily scheduler

**Goal:** overdue is computed correctly; the daily job runs per tenant.

Tasks
- [ ] `app/Console/Commands/MarkOverdueInvoices.php` (`invoices:mark-overdue`): runs inside the current tenant. For `Invoice::overdue()->whereNull('overdue_notified_at')`, chunked: set `overdue_notified_at`, dispatch the overdue email (Phase 7), then flush the dashboard cache. **It never changes `status`.**
- [ ] `routes/console.php`: `Schedule::command('tenants:run invoices:mark-overdue')->dailyAt('00:05')->withoutOverlapping()->onOneServer()`
- [ ] Overdue filter on the invoices index; `StatusBadge` shows "Overdue"
- [ ] `docs/ARCHITECTURE.md`: explain why overdue is computed and what the daily job does

Tests — `Feature/Invoices/OverdueLogicTest` (use `$this->travelTo()`)
- [ ] Not overdue on the due date itself; overdue the day after
- [ ] A paid invoice is never overdue; a partially paid past-due invoice is overdue
- [ ] Paying an overdue invoice in full → no longer overdue
- [ ] The command with 2 tenants: each tenant's invoices are processed only in their own context; `status` stays unchanged

Exit criteria
- [ ] Overdue tests are green; `php artisan schedule:list` shows the job

---

## Phase 7 — Queued invoice emails (idempotent, retry-safe)

**Goal:** emails are sent asynchronously, exactly once per event, in the correct tenant.

Tasks
- [ ] `app/Mail/InvoiceCreatedMail.php`, `InvoiceOverdueMail.php` (Markdown mail)
- [ ] `app/Jobs/SendInvoiceEmail.php`: `ShouldQueue`, `ShouldBeUnique` (`uniqueId = tenant:invoice:event`), `$tries = 5`, `backoff = [10, 60, 300]`
  - `insertOrIgnore` an `invoice_email_logs` row → if `sent_at` is already set, return → send the mail → set `sent_at`
- [ ] `InvoiceObserver@created` dispatches `SendInvoiceEmail(Created)` with `->afterCommit()`
- [ ] Confirm `QueueTenancyBootstrapper` is enabled

Tests — `Feature/Jobs/SendInvoiceEmailTest`
- [ ] Running the job twice sends exactly 1 email (`Mail::fake()`)
- [ ] A job that failed after the log row was inserted but before `sent_at` was set sends on retry
- [ ] Inside the job `tenant('id')` equals the invoice's tenant (dispatch in tenant A, run, assert)
- [ ] A rolled-back invoice transaction dispatches no job

Exit criteria
- [ ] Mail appears in Mailpit via the `queue` container; tests are green

---

## Phase 8 — Dashboard & caching

**Goal:** the four tenant-scoped metrics from one cached SQL query.

Tasks
- [ ] `app/Services/DashboardStatsService.php`: one query with `SUM(CASE WHEN …)`:
  - current month revenue = `SUM(payments.amount_cents)` with `paid_at` in this month (cash basis; document it)
  - outstanding total = `SUM(total - amount_paid)` where `status != paid`
  - paid invoices total = `SUM(total)` where `status = paid`
  - overdue total = `SUM(total - amount_paid)` where `due_date < today AND status != paid`
- [ ] `app/Services/DashboardCache.php`: key `dashboard:stats:{date}` (the tenant prefix comes from the bootstrapper), TTL 10 min, `flush()`
- [ ] `InvoiceObserver` / `PaymentObserver` (`saved`, `deleted`) → `DB::afterCommit(fn () => DashboardCache::flush())`
- [ ] `Dashboard.vue`: 4 stat cards, skeleton loading, empty state for new tenants

Tests — `Feature/Dashboard/DashboardCacheTest`
- [ ] Correct numbers for a known factory data set
- [ ] The second request is served from the cache (query count does not rise)
- [ ] Creating an invoice / recording a payment invalidates the cache
- [ ] Tenant A's dashboard never includes tenant B's numbers

Exit criteria
- [ ] Tests are green; the dashboard renders in under ~200 ms with the performance seed (Phase 10)

---

## Phase 9 — Reports

**Goal:** three reports, computed in SQL only, paginated.

Tasks
- [ ] `app/Services/Reports/SalesReport.php`: `from`/`to` → invoices issued in the range grouped by month (or day): count, total billed, total paid
- [ ] `OutstandingInvoicesReport.php`: `status != paid`, balance, overdue flag, sorted by `due_date`, paginated
- [ ] `CustomerSummaryReport.php`: `customers LEFT JOIN invoices GROUP BY customer`: invoice count, billed, paid, balance, overdue amount; paginated; sortable
- [ ] `DateRangeRequest`, `ReportController`
- [ ] Pages `reports/{Sales,Outstanding,CustomerSummary}.vue` with a date filter + loading/empty states

Tests
- [ ] Each report: correct totals on a known data set; only the current tenant's data; date-range boundaries (inclusive)

Exit criteria
- [ ] Reports work in the browser with the performance seed; tests are green

---

## Phase 10 — Seeding & performance validation (bonus)

**Goal:** realistic data volumes to prove indexing, SQL aggregation and caching.

Tasks
- [ ] `DatabaseSeeder`: tenants `acme`, `globex` (+ domains) and a login user each (`admin@acme.test` / `password`)
- [ ] `DemoTenantSeeder`: small, readable data set
- [ ] `PerformanceSeeder` (per tenant): 500 customers, 5,000 invoices, ~15,000 items, ~5,000 payments, using chunked bulk `insert()` (not factories one by one); keep invoice totals and `amount_paid` consistent with the items and payments
- [ ] `EXPLAIN ANALYZE` the dashboard and report queries; add or adjust indexes; write the results down in `docs/ARCHITECTURE.md`
- [ ] Turn on `preventLazyLoading` and fix any N+1 issues

Exit criteria
- [ ] `php artisan db:seed --class=PerformanceSeeder` finishes in under ~1 min
- [ ] Index/report pages stay fast; no lazy-loading violations

---

## Phase 11 — Hardening, docs & submission

**Goal:** pass every guardrail and deliver the required documents.

Tasks
- [ ] Full isolation sweep: one test per route that requests another tenant's resource → 404
- [ ] Re-read section B line by line; Opus does a final review of the whole repository
- [ ] `README.md`: requirements, Docker setup, `/etc/hosts` note (or `*.localhost`), migrate + seed, demo logins, how to run the queue/scheduler/tests, design decisions summary
- [ ] `docs/ERD.md` final; `docs/ARCHITECTURE.md` (tenancy, payment locking, overdue decision, email idempotency, caching, performance results)
- [ ] Clean `git log`; final push

Exit criteria
- [ ] `php artisan test` all green; `npm run build` succeeds
- [ ] A fresh clone → README steps → working app

---

## Folder structure (target)

```
SaaS-Platform/
├── app/
│   ├── Actions/
│   │   ├── Invoices/{CreateInvoiceAction,UpdateInvoiceAction,DeleteInvoiceAction}.php
│   │   └── Payments/RecordPaymentAction.php
│   ├── Console/Commands/MarkOverdueInvoices.php
│   ├── Data/{InvoiceData,InvoiceItemData,PaymentData}.php
│   ├── Enums/{InvoiceStatus,InvoiceEmailEvent}.php
│   ├── Exceptions/OverpaymentException.php
│   ├── Http/
│   │   ├── Controllers/{Dashboard,Customer,Invoice,Payment,Report}Controller.php
│   │   ├── Middleware/HandleInertiaRequests.php
│   │   └── Requests/{Customers,Invoices,Payments,Reports}/…
│   ├── Jobs/SendInvoiceEmail.php
│   ├── Mail/{InvoiceCreatedMail,InvoiceOverdueMail}.php
│   ├── Models/{Tenant,User,Customer,Invoice,InvoiceItem,Payment,InvoiceEmailLog}.php
│   ├── Observers/{InvoiceObserver,PaymentObserver}.php
│   ├── Policies/{CustomerPolicy,InvoicePolicy}.php
│   ├── Providers/{AppServiceProvider,TenancyServiceProvider}.php
│   └── Services/
│       ├── InvoiceCalculator.php
│       ├── DashboardStatsService.php
│       ├── DashboardCache.php
│       └── Reports/{SalesReport,OutstandingInvoicesReport,CustomerSummaryReport}.php
├── config/tenancy.php
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/{DatabaseSeeder,DemoTenantSeeder,PerformanceSeeder}.php
├── resources/js/
│   ├── components/{ui/,DataTable,EmptyState,Pagination,StatusBadge,MoneyInput,InvoiceItemsEditor}.vue
│   ├── composables/useMoney.ts
│   ├── layouts/AppLayout.vue
│   ├── pages/
│   │   ├── auth/Login.vue
│   │   ├── Dashboard.vue
│   │   ├── customers/{Index,Create,Edit,Show}.vue
│   │   ├── invoices/{Index,Create,Edit,Show}.vue
│   │   ├── payments/Index.vue
│   │   └── reports/{Sales,Outstanding,CustomerSummary}.vue
│   └── types/index.d.ts
├── routes/{web,tenant,console}.php
├── tests/
│   ├── TestCase.php
│   ├── Feature/{Auth,Tenancy,Customers,Invoices,Payments,Jobs,Dashboard,Reports}/…
│   └── Unit/InvoiceCalculatorTest.php
├── docker/{php.Dockerfile,nginx.conf}
├── docker-compose.yml
├── docs/{ERD,ARCHITECTURE}.md
├── PLAN.md
└── README.md
```

## Suggested timing

| Day | Phases |
|---|---|
| 1 | 0, 1 |
| 2 | 2, 3, 4 |
| 3 | 5, 6, 7 |
| 4 | 8, 9, 10 |
| 5 | 11 |
