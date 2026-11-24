# PLAN.md — SaaS Accounting System

Assessment: start 2026-11-23 → review 2026-11-30.

How to use this file:
1. Read **Part 1** first. It explains how the whole system works before any code exists. **Section 1.8** explains the business flow (modelled on Bukku).
2. Then build **one phase at a time** (Part 2). Each phase explains *why*, *how it works* and *what to do*, and ends with **Exit criteria**.
3. A phase is done only when every exit criterion is checked. Then commit, push, review against **section 1.6**, and only then start the next phase.

---

# Part 1 — Understand the system before building

## 1.1 What we are building

A web app where **many companies (tenants)** each manage their own:
- **Customers** — who they bill
- **Invoices** — what they bill, made of several line items
- **Payments** — money received against an invoice

Plus a **dashboard** (4 numbers) and **3 reports**.

The graders care most about three things:

| Focus | Meaning in practice |
|---|---|
| Backend correctness | Totals, statuses and balances are always right |
| Data integrity | The database itself refuses impossible data (e.g. paid > total) |
| Multi-tenancy safety | Company A can **never** see or touch company B's data — in pages, queries, jobs or scheduled tasks |

## 1.2 Key ideas (glossary)

| Term | What it means here |
|---|---|
| **Tenant** | One company. Stored in the `tenants` table (id like `acme`). Each tenant has a domain row (`acme`) used for the subdomain `acme.localhost`. |
| **Single-database tenancy** | All companies share one database. Every business table has a `tenant_id` column. Isolation comes from always filtering by `tenant_id`. |
| **Tenancy initialization** | At the start of each request, stancl/tenancy reads the subdomain, finds the tenant and "switches on" that tenant. From then on `tenant()` returns the current company. |
| **`BelongsToTenant` trait** | Added to a model. It does two things automatically: (1) adds `WHERE tenant_id = <current tenant>` to **every** query (a *global scope*), and (2) fills `tenant_id` when a row is created. So `Invoice::find(5)` returns `null` if invoice 5 belongs to another company → we show 404. |
| **Bootstrappers** | stancl plugins that make other Laravel parts tenant-aware when a tenant is initialized: **Cache** (each tenant gets its own cache tag/prefix) and **Queue** (the tenant id is written into every queued job and restored when the job runs). |
| **Inertia.js** | The "glue" between Laravel and Vue. A controller returns `Inertia::render('invoices/Index', $props)` instead of HTML or JSON; Inertia loads the Vue page `resources/js/pages/invoices/Index.vue` and passes `$props` to it. No separate API. |
| **Action class** | One class = one business operation (e.g. `RecordPaymentAction`). Controllers stay small: validate → call the action → redirect. Logic is reusable and easy to test. |
| **Service class** | Calculations or read-only queries (e.g. `InvoiceCalculator`, `DashboardStatsService`). |
| **FormRequest** | A Laravel class holding validation rules for one form. If validation fails, Laravel automatically sends the errors back, and Inertia shows them next to the fields. |
| **Observer** | A class that reacts to model events (`created`, `updated`, `deleted`). We use it to clear the dashboard cache and to queue emails. |
| **DB transaction** | A group of database writes that succeed together or are all undone. |
| **Row lock (`lockForUpdate`)** | `SELECT … FOR UPDATE`: while one request is recording a payment on invoice 5, a second request for invoice 5 must wait. This prevents two simultaneous payments from both "seeing" the same balance and together overpaying. |
| **Idempotent job** | Running the same job twice has the same effect as running it once (one email, not two). Needed because queues retry failed jobs. |
| **Cents** | All money is stored as whole numbers of cents (`RM 12.50` → `1250`). Integers never have rounding errors; floats do. |

## 1.3 Life of a request (big picture)

```
User opens http://acme.localhost/invoices/12
        │
        ▼
 ① Tenancy middleware ── reads subdomain "acme" → finds tenant → tenancy()->initialize(acme)
        │                 • every Eloquent query now gets  WHERE tenant_id = 'acme'
        │                 • cache keys are separated per tenant
        │                 • queued jobs will carry tenant_id = 'acme'
        ▼
 ② auth middleware ───── is someone logged in? (the users table is tenant-scoped too,
        │                 so only Acme's users can ever be logged in here)
        ▼
 ③ Controller ────────── Invoice::with('items','payments')->findOrFail(12)
        │                 → invoice 12 belongs to Globex? the scope hides it → 404
        ▼
 ④ (for writes) FormRequest validates → Action runs inside DB::transaction
        │                 → Observer clears dashboard cache / queues email after commit
        ▼
 ⑤ Inertia::render('invoices/Show', [...]) → Vue page renders the props
```

## 1.4 The data model

```
tenants ─┬─< domains
         ├─< users
         └─< customers ─< invoices ─┬─< invoice_items
                                    ├─< payments
                                    └─< invoice_email_logs
```
(`─<` means "has many". Every table below `tenants` has its own `tenant_id`.)

| Table | Important columns | Notes |
|---|---|---|
| `tenants` | id, name, invoice_prefix, currency, default_payment_terms_days | company settings (B3, B5, B10) |
| `customers` | name, email, phone, address, registration_no, payment_terms_days | email unique **per tenant**; archived = soft deleted |
| `invoices` | customer_id, number, issue_date, due_date, state, status, subtotal_cents, total_cents, amount_paid_cents, issued_at, voided_at, overdue_notified_at | `amount_paid_cents` is a stored copy of `SUM(payments)`, refreshed by one method only |
| `invoice_items` | invoice_id, description, quantity, unit_price_cents, line_total_cents | `line_total_cents` = quantity × unit price, computed by the server |
| `payments` | invoice_id, amount_cents, paid_at, method, reference, voided_at, void_reason | `amount_cents > 0` enforced by the database; voided payments don't count |
| `invoice_email_logs` | invoice_id, event, sent_at | unique per (tenant, invoice, event) → stops duplicate emails |

Why `tenant_id` is also on `invoice_items` and `payments` (not only on `invoices`): it lets every table be filtered directly by the global scope, so even a query that does not go through the invoice cannot leak data. It also makes per-tenant aggregation queries cheap.

## 1.5 The business rules (the heart of the grade)

### Invoice status

`status` stores **payment state only**, and is always recalculated from the payments:

| Total paid | status |
|---|---|
| 0 | `unpaid` |
| more than 0 but less than total | `partial` |
| exactly total | `paid` |
| more than total | impossible — blocked in 3 places (see below) |

### Overdue is computed, not stored

```
is_overdue = state = 'issued'  AND  due_date < today  AND  status != 'paid'
```
It is worked out every time it is needed (model accessor + query scope). Why? A stored "overdue" flag would be wrong the moment a payment comes in or the date changes. Computing it means it can never be out of date.

The spec also asks for a **daily job that "marks" overdue invoices**. Our job does not change `status`. Per tenant it:
1. finds invoices that are overdue and not yet notified,
2. sets `overdue_notified_at` (a record that we noticed and emailed — never used to decide the status),
3. queues one "invoice overdue" email,
4. clears the tenant's dashboard cache (overdue totals change when the date changes).

This meets both requirements at once. We write this reasoning in the README because reviewers look for it.

### Overpayment protection (three layers)

| Layer | Where | Catches |
|---|---|---|
| 1. Validation | `StorePaymentRequest` | Normal user mistakes; shows a friendly field error |
| 2. Locked check | `RecordPaymentAction` inside a transaction with `lockForUpdate` | Two payments submitted at the same moment |
| 3. Database constraint | `CHECK (amount_paid_cents <= total_cents)` | Any bug in our code |

### Totals are always computed by the server

The browser may show a live total preview, but the server **ignores any totals sent by the browser** and recalculates from the items every time.

## 1.6 Automatic-fail guardrails (check at every review)

- [ ] No cross-tenant access: every model uses `BelongsToTenant`; another tenant's ID returns 404
- [ ] Validation `exists` rules are tenant-scoped (a plain `exists:customers,id` rule queries the table directly and **bypasses** the Eloquent scope)
- [ ] Overpayment is impossible (3 layers above)
- [ ] Invoice totals always recalculated on the server
- [ ] Only `Invoice::recalculatePaymentState()` writes `status` / `amount_paid_cents`
- [ ] Drafts, void invoices and voided payments are excluded from every total (1.8)
- [ ] Queued jobs run in the correct tenant (`QueueTenancyBootstrapper`)
- [ ] The scheduler runs per tenant (`tenants:run`)

## 1.7 Fixed technical decisions

| Area | Decision | Reason |
|---|---|---|
| Framework | Laravel 12 + official Vue starter kit (Vue 3, TypeScript, Inertia, Tailwind, shadcn-vue) | Required; gives login screens and a layout for free |
| Database | PostgreSQL 18 (Docker) | Reliable row locks and `CHECK` constraints |
| Tenancy | `stancl/tenancy:^3.10`, single-database mode | Latest stable version; supports Laravel 12. Spec says "all models tenant-scoped" |
| Identification | Subdomain (`acme.localhost`) | Standard stancl approach. Fallback: path `/t/acme/...` |
| Cache / queue / session | Redis | Required |
| Money | integer cents | No rounding errors |
| Code layout | Controllers → FormRequests → Actions / Services → Models | Clean, testable, reusable |

## 1.8 Business flow — how a real accounting system works (modelled on Bukku)

The assessment asks us to design the business logic ourselves, using Bukku (a Malaysian cloud accounting system) as the reference. Below is what Bukku does, what we copy, and what we deliberately leave out. Bukku facts come from its help centre (intercom.help/bukku); items marked *(our design)* are our own decisions where Bukku's behaviour could not be confirmed.

### The sales flow end to end

```
 Customer (contact)            Invoice                                   Payment
 ──────────────────     ─────────────────────────────────────     ─────────────────────
 name, email, TIN,      DRAFT ──issue──► ISSUED ──────────► (paid)   recorded against the
 payment terms   ──►    (no number,      (gets IV-00001,             invoice; can be VOIDED
 (e.g. 30 days)         editable,        emailed to customer,         (never deleted)
                        deletable)       counts in receivables)            │
                                           │                               ▼
                                           ├── void ──► VOID       invoice status recalculated:
                                           │  (only if no active     unpaid → partial → paid
                                           │   payments; keeps the
                                           │   number, never deleted) overdue = issued + past due + not paid
```

### Decisions

| # | Topic | How Bukku works | Our decision |
|---|---|---|---|
| B1 | **Invoice lifecycle** | Invoices can be saved and later sent; transactions are **voided** (and can be un-voided) rather than silently deleted | Two separate fields: `state` = `draft` / `issued` / `void` (document lifecycle) and `status` = `unpaid` / `partial` / `paid` (payment state, Section 1.5). A draft is a work in progress: no number, no email, not in dashboard/reports, cannot receive payments. *Issue* assigns the number and sends the email. |
| B2 | **Delete vs void** | Must void before deleting; void keeps an audit trail | **Drafts** can be deleted. **Issued** invoices are never deleted, only **voided** (keeps the number sequence gap-free and the history auditable). Voiding requires every payment on it to be voided first. Void invoices are excluded from all totals. |
| B3 | **Invoice numbers** | Default format `IV-00001`, customisable per company | Format `{prefix}{5 digits}` e.g. `IV-00001`. Prefix stored per tenant (`tenants.invoice_prefix`, default `IV-`). Number assigned **at issue time**, so deleted drafts never leave gaps. |
| B4 | **Editing** | — *(our design)* | Draft: freely editable. Issued with **no active payments**: editable (totals recalculated). Issued **with payments**: locked; void the payments first. This is simpler and safer than allowing the total to change under a payment. |
| B5 | **Payment terms → due date** | Payment terms are a per-contact default that overrides the company default | `customers.payment_terms_days` (0 = due on receipt, 7, 14, 30, 60; default 30 from `tenants.default_payment_terms_days`). The invoice form pre-fills `due_date = issue_date + terms`; the user can change it. |
| B6 | **Recording payments** | "Add Payment" on the invoice, or a separate Sales Payment that can cover several invoices | We record payments **from the invoice** (one payment → one invoice). Multi-invoice allocation is out of scope and listed as a future improvement. Fields: date, amount, method (`cash`, `bank_transfer`, `cheque`, `card`, `e_wallet`), reference no. |
| B7 | **Overpayment** | Bukku accepts the extra money as customer credit to refund or offset later | The spec says overpayment must be **impossible**, so we **block** it (3 layers, Section 1.5). We note customer credit as a future improvement in the README. |
| B8 | **Undoing a payment** | Transactions are voided, not deleted | Payments are **voided** (`voided_at`, `void_reason`), never deleted. Voided payments are excluded from `SUM`, and `recalculatePaymentState()` runs again (e.g. `paid` → `partial`). The history stays visible with a "Void" badge. |
| B9 | **Customers** | Contact has default terms, currency, TIN (needed for e-invoice) | Fields: name, email, phone, billing address, `registration_no` (SSM/TIN, optional), `payment_terms_days`. A customer with invoices cannot be deleted, only **archived** (soft delete): hidden from the invoice customer picker, history kept, can be restored. |
| B10 | **Currency & tax** | Multi-currency and SST tax codes | Single currency per tenant (`tenants.currency`, default `MYR`). No tax (SST) in scope; `InvoiceCalculator` is the single place to add it later. |
| B11 | **Emails** | Invoice sent on "save and send"; automatic reminders before due and when overdue; email tracker on the invoice | Emails: **invoice issued** (on issue) and **overdue reminder** (daily job). Optional stretch: **payment receipt**. The invoice page shows an email history from `invoice_email_logs`. |
| B12 | **Multi-company** | One login can own several companies, switched from a dropdown | Spec model: each user belongs to **one** tenant and reaches it via its subdomain. Switching companies is out of scope. |
| B13 | **Reports** | Standard accounting reports | Sales report, **Receivables aging** (outstanding invoices bucketed Current / 1–30 / 31–60 / 61–90 / 90+ days overdue), Customer summary. Only `issued` invoices and non-voided payments count. |

### What this changes in the rules

- **Overdue:** `state = issued AND due_date < today AND status != paid`.
- **Everything that sums money** (dashboard, reports) uses `state = issued` invoices and `voided_at IS NULL` payments only.
- **Payment allowed only when** `state = issued` and `status != paid`.
- **`recalculatePaymentState()`** sums only non-voided payments.


---

# Part 2 — Build phase by phase

Each phase has: **Goal · Why · How it works · Steps · Tests · Exit criteria**.

---

## Phase 0 — Project setup & environment

**Goal:** an empty Laravel 12 + Vue app running on your machine, using PostgreSQL, Redis and Mailpit from Docker.

**Why:** everything else depends on a working, repeatable environment. The reviewer must be able to run the project with a few commands.

**How it works:** Docker runs only the **services** (the same style as your other projects). Laravel, the queue worker, the scheduler and Vite run on your machine with `php` / `npm`.

| Runs in Docker (`docker-compose.yml`) | Purpose | Address from Laravel |
|---|---|---|
| `postgres` (18.1) | Main DB `saas_platform` + test DB `saas_platform_testing` (created by `docker/postgres/init/01-create-testing-db.sql`) | `127.0.0.1:5432`, user `postgres`, no password (trust, local only) |
| `redis` (8.4) | Cache, queue and sessions | `127.0.0.1:6379` |
| `mailpit` | Fake mail server; inbox at `http://localhost:8025` | SMTP `127.0.0.1:1025` |

| Runs on your machine | Command |
|---|---|
| Web server + queue worker + logs + Vite | `composer run dev` (starter-kit script: `php artisan serve`, `queue:listen`, `pail`, `npm run dev`) |
| Scheduler (daily overdue job) | `php artisan schedule:work` (second terminal) |

`docker-compose.yml` sets `name: saas-accounting`, so Docker Desktop groups its containers and volumes under **saas-accounting** and they never mix with your other projects. Ports can be moved with `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MAILPIT_PORT` and `FORWARD_MAILPIT_DASHBOARD_PORT` in `.env` if another project already uses them. Stop the other project's containers or change these ports before `docker compose up`.

Browsers resolve `*.localhost` to 127.0.0.1 automatically, so `http://acme.localhost:8000` reaches `php artisan serve` without editing the hosts file (Chrome, Firefox, Edge). Note this in the README.

**Steps**
1. `docker compose up -d` → all three services `healthy`. (Done.)
2. `composer create-project laravel/vue-starter-kit` (or `laravel new` → Vue) into the repository root.
3. `composer require predis/predis` so Redis works without installing the `phpredis` extension (easier on Windows).
4. `.env` / `.env.example`:
   ```
   APP_URL=http://localhost:8000
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=saas_platform
   DB_USERNAME=postgres
   DB_PASSWORD=
   REDIS_CLIENT=predis
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379
   CACHE_STORE=redis
   QUEUE_CONNECTION=redis
   SESSION_DRIVER=redis
   MAIL_MAILER=smtp
   MAIL_HOST=127.0.0.1
   MAIL_PORT=1025
   ```
5. `phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=saas_platform_testing` (not SQLite: SQLite ignores row locks, so tests would not prove the real behaviour).
6. In `AppServiceProvider::boot()`:
   ```php
   Model::shouldBeStrict(! app()->isProduction()); // throws on N+1 lazy loading & silent mistakes
   ```
7. Your PHP needs the `pdo_pgsql` extension enabled (`php -m | grep pdo_pgsql`).

**Exit criteria**
- [x] `docker compose up -d` → postgres, redis, mailpit healthy; both databases exist
- [ ] `php artisan migrate` works · welcome page loads at `http://localhost:8000`
- [ ] `php artisan test` passes (starter kit tests) · `npm run build` succeeds
- [ ] Mailpit inbox opens at `http://localhost:8025`

---

## Phase 1 — Multi-tenancy foundation & login

**Goal:** every request runs inside exactly one tenant; a user can log in only on their own company's subdomain.

**Why:** this is worth 25 points and is the #1 automatic-fail risk. Building it first means every later feature is isolated by default.

**How it works**
- `localhost` is the **central domain** (landing page only, no company data).
- `acme.localhost` is a **tenant domain**. The middleware `InitializeTenancyBySubdomain` takes `acme`, looks it up in `domains`, and initializes that tenant.
- `PreventAccessFromCentralDomains` stops tenant pages from being opened on `localhost`.
- The `users` table gets `tenant_id` + `BelongsToTenant`. When the login form checks the email, the query already has `WHERE tenant_id = 'acme'`, so a Globex user's email simply does not exist on Acme's login page.
- Sessions: the cookie belongs to `acme.localhost` only, so being logged in at Acme does not log you in at Globex.

**Steps**
1. Install:
   ```bash
   composer require stancl/tenancy:^3.10
   php artisan tenancy:install      # creates config/tenancy.php, routes/tenant.php, TenancyServiceProvider, migrations
   ```
2. `config/tenancy.php`:
   - `tenant_model` → `App\Models\Tenant`
   - `central_domains` → `['localhost', '127.0.0.1']`
   - `bootstrappers` → **remove** `DatabaseTenancyBootstrapper` (single DB), keep `CacheTenancyBootstrapper` and `QueueTenancyBootstrapper`, remove `FilesystemTenancyBootstrapper` (not needed)
3. `app/Models/Tenant.php`:
   ```php
   class Tenant extends \Stancl\Tenancy\Database\Models\Tenant
   {
       use \Stancl\Tenancy\Database\Concerns\HasDomains;
       public static function getCustomColumns(): array { return ['id', 'name']; }
   }
   ```
4. Migration: add `name` to `tenants`; add `tenant_id` (FK, cascade) to `users`; replace `users.email` unique with `UNIQUE(tenant_id, email)`.
5. `User` model: `use BelongsToTenant;`.
6. Routes:
   - `routes/web.php` → central only: a landing page listing demo company links.
   - `routes/tenant.php` → **all** app routes inside
     `Route::middleware(['web', InitializeTenancyBySubdomain::class, PreventAccessFromCentralDomains::class])`.
   - Move the starter kit's auth routes in too. (If the kit uses **Fortify**, add the two tenancy middlewares to `config/fortify.php` → `'middleware'`; if it uses `routes/auth.php`, `require` that file inside the tenant group.)
7. Turn off self-registration (accounts come from the seeder).
8. `HandleInertiaRequests::share()` → add `tenant: { id, name }` and `flash: { success, error }` so every Vue page can show the company name and messages.
9. `tests/TestCase.php` helpers:
   ```php
   protected function createTenant(string $id = 'acme'): Tenant   // creates tenant + domain
   protected function tenantUrl(string $tenant, string $path): string // "http://acme.localhost{$path}"
   protected function actingAsTenantUser(Tenant $tenant): User    // initializes tenancy + creates & logs in a user
   ```

**Tests** — `tests/Feature/Auth/AuthenticationTest.php`
- login page loads at `acme.localhost/login`
- correct password → redirected to dashboard
- wrong password → error, still a guest
- logout works
- **an Acme user's credentials fail at `globex.localhost/login`**
- a guest visiting `acme.localhost/dashboard` is redirected to login
- `localhost/dashboard` (central) → 404

**Exit criteria**
- [ ] Login works at `acme.localhost`; the same credentials fail at `globex.localhost`
- [ ] All auth tests green

---

## Phase 2 — Database schema, models & factories

**Goal:** every table, index and constraint; every model with its relationships; factories for tests.

**Why:** "Database design" is 15 points ("normalized and scalable"), and the constraints here are our last line of defence for data integrity.

**How it works**
- **Foreign keys** keep relationships valid (a payment cannot point to a missing invoice).
- **`CHECK` constraints** make the database refuse impossible values even if our PHP has a bug.
- **Indexes** are chosen for the queries we will actually run (lists filtered by status/date, sums per invoice, monthly revenue). Each index starts with `tenant_id` because every query filters by tenant first.
- **Factories** create realistic fake rows in one line (`Invoice::factory()->partial()->create()`), which the spec requires for tests.

**Steps — migrations**

| Table | Columns | Indexes & constraints |
|---|---|---|
| `tenants` (add columns) | invoice_prefix (default `IV-`), currency (default `MYR`), default_payment_terms_days (default 30) | — |
| `customers` | id, tenant_id, name, email (nullable), phone, address (text), registration_no (nullable), payment_terms_days (default 30), timestamps, soft deletes | `INDEX(tenant_id, name)`, `UNIQUE(tenant_id, email)`, `CHECK(payment_terms_days BETWEEN 0 AND 365)` |
| `invoices` | id, tenant_id, customer_id, sequence (int, nullable until issued), number (`IV-00001`, nullable until issued), issue_date, due_date, state (default `draft`), status (default `unpaid`), subtotal_cents, total_cents, amount_paid_cents (default 0), issued_at, voided_at, void_reason, overdue_notified_at (all nullable), timestamps | `UNIQUE(tenant_id, sequence)`, `UNIQUE(tenant_id, number)`, `INDEX(tenant_id, state, status, due_date)`, `CHECK(state IN ('draft','issued','void'))`, `CHECK(state = 'draft' OR number IS NOT NULL)`, `INDEX(tenant_id, issue_date)`, `INDEX(tenant_id, customer_id)`, `CHECK(amount_paid_cents BETWEEN 0 AND total_cents)`, `CHECK(due_date >= issue_date)`, `CHECK(status IN ('unpaid','partial','paid'))` |
| `invoice_items` | id, tenant_id, invoice_id (cascade), position, description, quantity `decimal(10,2)`, unit_price_cents, line_total_cents | `INDEX(invoice_id)`, `CHECK(quantity > 0)`, `CHECK(unit_price_cents >= 0)` |
| `payments` | id, tenant_id, invoice_id (restrict), amount_cents, paid_at (date), method (`cash`/`bank_transfer`/`cheque`/`card`/`e_wallet`), reference (nullable), voided_at, void_reason (nullable), timestamps | `INDEX(tenant_id, invoice_id)`, `INDEX(tenant_id, paid_at)`, `CHECK(amount_cents > 0)` |
| `invoice_email_logs` | id, tenant_id, invoice_id, event, sent_at (nullable), timestamps | `UNIQUE(tenant_id, invoice_id, event)` |

All `tenant_id` columns: `foreignId`-style string FK → `tenants.id`, cascade on delete. `CHECK` constraints are added with `DB::statement('ALTER TABLE … ADD CONSTRAINT …')`.

**Steps — code**
1. `app/Enums/InvoiceState.php` (`Draft`, `Issued`, `Void`), `app/Enums/InvoiceStatus.php` (`Unpaid`, `Partial`, `Paid`) with a `label()` method; `app/Enums/InvoiceEmailEvent.php` (`Issued`, `Overdue`); `app/Enums/PaymentMethod.php`.
2. Models with `BelongsToTenant`, `$fillable`, `casts()` (dates, enums), relationships:
   - `Customer hasMany Invoice`
   - `Invoice belongsTo Customer`, `hasMany InvoiceItem` (ordered by position), `hasMany Payment`
   - `Payment belongsTo Invoice`
3. On `Invoice`:
   ```php
   public function scopeOverdue(Builder $q): void {
       $q->where('state', InvoiceState::Issued)->whereDate('due_date', '<', today())->where('status', '!=', InvoiceStatus::Paid);
   }
   public function scopeIssued(Builder $q): void { $q->where('state', InvoiceState::Issued); }
   public function scopeOutstanding(Builder $q): void { $q->issued()->where('status', '!=', InvoiceStatus::Paid); }

   protected function isOverdue(): Attribute {      // $invoice->is_overdue
       return Attribute::get(fn () => $this->state === InvoiceState::Issued && $this->status !== InvoiceStatus::Paid && $this->due_date->lt(today()));
   }
   protected function displayStatus(): Attribute { /* 'draft' / 'void' from state; else 'overdue' if is_overdue; else status */ }
   protected function balanceCents(): Attribute { /* total_cents - amount_paid_cents */ }
   ```
4. Factories: `TenantFactory`, `UserFactory`, `CustomerFactory`, `InvoiceFactory` (states `paid()`, `partial()`, `overdue()`, `withItems(int $n)` — the states must keep totals and payments consistent), `InvoiceItemFactory`, `PaymentFactory`.
5. `docs/ERD.md`: Mermaid ERD + one line explaining each index.

**Tests** — `tests/Feature/Tenancy/TenantIsolationTest.php`
- create data in tenant A and tenant B; inside A, `Customer::count()` and `Invoice::count()` only count A's rows
- `Invoice::find($idFromB)` returns `null` inside A
- `$customerA->invoices` never contains B's invoices
- creating a model inside A auto-fills `tenant_id = A`
- DB rejects `payments.amount_cents = 0` and `invoices.amount_paid_cents > total_cents`

**Exit criteria**
- [ ] `php artisan migrate:fresh` runs cleanly
- [ ] Isolation and constraint tests green · ERD written

---

## Phase 3 — Customer management

**Goal:** list, search, create, view, edit and archive customers (Section 1.8 B9).

**Why:** the simplest CRUD — it sets the pattern (controller → FormRequest → Inertia page) and the shared Vue components every later screen reuses.

**How it works (request flow for "create customer")**
```
Create.vue   useForm({name,email,phone,address}) → form.post('/customers')
   ▼
StoreCustomerRequest  validates; on error Inertia puts messages in form.errors (no page reload)
   ▼
CustomerController@store  Customer::create($request->validated())  (tenant_id filled automatically)
   ▼
redirect()->route('customers.index')->with('success', 'Customer created')
   ▼
Index.vue shows the toast from the shared `flash` prop
```
UX states the spec asks for:
- **Loading** — `form.processing` disables the submit button and shows a spinner; Inertia's progress bar shows during page visits.
- **Error** — field errors under each input; a toast for general errors; a friendly 404/403 page.
- **Empty** — `EmptyState.vue` ("No customers yet" + "Add customer" button) when the list is empty.

**Steps**
1. `CustomerController` (resource). `index`: search by name/email, `paginate(15)->withQueryString()`.
2. `StoreCustomerRequest` / `UpdateCustomerRequest`:
   ```php
   'email' => ['nullable','email', Rule::unique('customers')->where('tenant_id', tenant('id'))->ignore($this->customer)],
   ```
3. `CustomerPolicy` — extra safety: `$user->tenant_id === $customer->tenant_id`.
4. `destroy` = **archive** (soft delete): allowed even with invoices; the history stays. Archived customers are hidden from the list (filter "Show archived") and from the invoice customer picker. `restore` route brings one back.
5. Form fields include `registration_no` and `payment_terms_days` (select: Due on receipt / 7 / 14 / 30 / 60 days).
6. Shared Vue: `DataTable.vue`, `Pagination.vue`, `EmptyState.vue`, `FlashToast.vue`, error page for 403/404/500 (registered in `bootstrap/app.php` → `withExceptions`).
7. Pages: `customers/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue` (Show lists that customer's invoices).

**Tests** — `tests/Feature/Customers/CustomerManagementTest.php`
- create / update / delete happy paths
- validation: name required, invalid email, email duplicated within the same tenant (but allowed in another tenant)
- archiving a customer with invoices keeps the invoices; archived customer cannot be picked for a new invoice; restore works
- another tenant's customer → 404 on show, edit, update, delete

**Exit criteria**
- [ ] Customer CRUD works in the browser with loading, error and empty states
- [ ] Tests green

---

## Phase 4 — Invoices with line items

**Goal:** create drafts, issue, edit, void and view invoices with several line items; totals always correct (lifecycle in Section 1.8 B1–B5).

**Why:** "invoice totals are inconsistent" is an automatic fail.

**How it works**

*Calculation* (`InvoiceCalculator`, pure PHP, no database):
```
line_total_cents = round(quantity × unit_price_cents)
subtotal_cents   = sum of line_total_cents
total_cents      = subtotal_cents            (no tax in scope; one place to add it later)
```
Quantity has 2 decimals, so to stay in integer maths: `qty_hundredths = quantity × 100`, then
`line_total_cents = intdiv(qty_hundredths × unit_price_cents + 50, 100)` (rounds half up, no floats).

*Money input:* the user types `"125.50"`; the FormRequest validates it with a regex
(`^\d+(\.\d{1,2})?$`) and converts the string to `12550` cents without using floats.

*Invoice numbers:* per tenant, `IV-00001` format with the tenant's prefix, assigned **when the invoice is issued**, sequential, no gaps or duplicates even when two invoices are issued at the same moment. Inside the transaction we lock the tenant's latest invoice row (`lockForUpdate`), take `max(sequence) + 1`, and the `UNIQUE(tenant_id, sequence)` index is the safety net.

*Create flow:*
```
StoreInvoiceRequest (validate) → InvoiceData DTO → CreateInvoiceAction:
  DB::transaction:
    next sequence → create invoice → create items → InvoiceCalculator → save totals
  → Observer queues "invoice created" email after commit (Phase 7)
```

*Lifecycle:* the form has two buttons, **Save as draft** and **Save & issue**. `IssueInvoiceAction` (in a transaction): lock, assign the next number, set `state = issued`, `issued_at = now()`, then queue the "invoice issued" email after commit. Due date is pre-filled from the customer's payment terms (B5).

*Edit rules (B4):* drafts are freely editable. Issued invoices are editable only while they have **no active payments**; with payments the edit button is hidden and the server returns 403 (`InvoicePolicy::update`). Editing replaces all items and recalculates totals.

*Delete / void (B2):* drafts can be deleted. Issued invoices can only be **voided** (`VoidInvoiceAction`, requires a reason and no active payments). Void invoices keep their number and stay visible with a "Void" badge.

**Steps**
1. `app/Services/InvoiceCalculator.php` + `app/Support/Money.php` (string ↔ cents helpers).
2. `app/Data/InvoiceData.php`, `InvoiceItemData.php`.
3. `StoreInvoiceRequest` / `UpdateInvoiceRequest`:
   ```php
   'customer_id' => ['required', Rule::exists('customers','id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
   'issue_date'  => ['required','date'],
   'due_date'    => ['required','date','after_or_equal:issue_date'],
   'items'       => ['required','array','min:1','max:100'],
   'items.*.description' => ['required','string','max:255'],
   'items.*.quantity'    => ['required','numeric','gt:0','decimal:0,2'],
   'items.*.unit_price'  => ['required','regex:/^\d+(\.\d{1,2})?$/'],
   ```
   Any `subtotal` / `total` field sent by the browser is simply not in the rules, so it is dropped.
4. `CreateInvoiceAction`, `UpdateInvoiceAction`, `IssueInvoiceAction`, `VoidInvoiceAction`, `DeleteDraftInvoiceAction`; `InvoicePolicy` (`update`, `issue`, `void`, `delete`).
5. `Invoice::recalculatePaymentState()` (also used by payments in Phase 5).
6. `InvoiceController`:
   - `index`: filter by `draft`/`unpaid`/`partial`/`paid`/`overdue`/`void`, customer, date; `with('customer:id,name')`; paginate
   - `show`: `load(['items', 'payments' => fn ($q) => $q->latest('paid_at')])`
7. Vue: `InvoiceItemsEditor.vue` (add/remove rows, live total preview), `StatusBadge.vue` (draft outline, unpaid grey, partial amber, paid green, overdue red, void struck-through), `composables/useMoney.ts` (format cents for display), pages `invoices/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue`.

**Tests**
- `tests/Unit/InvoiceCalculatorTest.php`: one item; several items; decimal quantity (1.5 × 3.33); rounding half up; zero price
- `tests/Feature/Invoices/InvoiceCreationTest.php`:
  - creates the invoice + items with correct `subtotal_cents` / `total_cents`
  - a tampered `total` sent by the client is ignored
  - validation: no items, `due_date` before `issue_date`, negative price, zero quantity
  - a customer from another tenant → validation error
  - invoice numbers are sequential per tenant (A: 1, 2 · B: 1)
  - draft has no number; issuing assigns `IV-00001`, `IV-00002` …; deleting a draft leaves no gap
  - due date defaults to issue date + customer payment terms
  - an issued invoice with payments cannot be edited (403); without payments it can
  - issued invoices cannot be deleted; voiding needs no active payments; void invoices are excluded from totals
  - a draft cannot receive payments and sends no email

**Exit criteria**
- [ ] Multi-item invoices can be created, edited and viewed in the browser
- [ ] Tests green

---

## Phase 5 — Payments & invoice status (highest risk)

**Goal:** record full or partial payments safely; status always correct; overpayment impossible.

**Why:** "overpayment is possible" and "invoice state logic is unreliable" are automatic fails; Business Logic is 25 points.

**How it works — `RecordPaymentAction`**
```php
public function execute(int $invoiceId, PaymentData $data): Payment
{
    return DB::transaction(function () use ($invoiceId, $data) {
        // 1. lock the invoice row (tenant scope still applies → other tenants' invoices = 404)
        $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();

        // 2. fresh total from the payments table (not the stored copy)
        if ($invoice->state !== InvoiceState::Issued) throw InvoiceNotPayableException::make();
        $paid    = (int) $invoice->payments()->whereNull('voided_at')->sum('amount_cents');
        $balance = $invoice->total_cents - $paid;

        // 3. business rule
        if ($data->amountCents <= 0 || $data->amountCents > $balance) {
            throw OverpaymentException::make($balance);   // rendered as a 422 field error on "amount"
        }

        // 4. write + recalculate
        $payment = $invoice->payments()->create([...]);
        $invoice->recalculatePaymentState();              // amount_paid_cents + status
        return $payment;
    });
}
```
Why the lock matters — without it:
```
Request 1 reads balance 100 ─┐
Request 2 reads balance 100 ─┤  both think 100 is allowed
Request 1 pays 100           │
Request 2 pays 100           ┘  → 200 paid on a 100 invoice
```
With `lockForUpdate`, request 2 waits until request 1 commits, then reads balance 0 and is rejected. Layer 3 (the `CHECK` constraint) would stop it even if this code were wrong.

`recalculatePaymentState()`:
```php
$paid = (int) $this->payments()->whereNull('voided_at')->sum('amount_cents');
$this->amount_paid_cents = $paid;
$this->status = match (true) {
    $paid === 0                  => InvoiceStatus::Unpaid,
    $paid < $this->total_cents   => InvoiceStatus::Partial,
    default                      => InvoiceStatus::Paid,
};
$this->save();
```

**Steps**
1. `app/Data/PaymentData.php`, `app/Exceptions/OverpaymentException.php`.
2. `StorePaymentRequest`: `amount` (money regex, > 0, ≤ current balance for a friendly message), `paid_at` (date, not in the future, not before `issue_date`), `method` (enum), `reference` (optional).
3. `RecordPaymentAction` as above.
4. Routes: `POST /invoices/{invoice}/payments` → `PaymentController@store`; `GET /payments` → `PaymentController@index` (all payments, `with('invoice.customer')`, date filter, paginated).
5. `invoices/Show.vue`: summary (total / paid / balance), "Record payment" form (amount pre-filled with the balance, hidden when paid), payment history table.
6. `payments/Index.vue`: payments screen required by the spec.
7. `VoidPaymentAction` (B8): in a transaction, lock the invoice, set `voided_at` + `void_reason`, call `recalculatePaymentState()`. Route `POST /payments/{payment}/void`. Payments are never deleted.

**Tests** — `tests/Feature/Payments/PaymentProcessingTest.php`
- partial payment → `partial`, `amount_paid_cents` and balance correct
- two partial payments adding up exactly to the total → `paid`
- one payment equal to the total → `paid`
- payment larger than the balance → 422, no payment row, status unchanged
- second payment exceeding the remaining balance → rejected
- zero and negative amounts → rejected
- payment on a paid invoice → rejected
- payment on another tenant's invoice → 404
- payment on a draft or void invoice → rejected
- voiding a payment on a `paid` invoice → `partial`; voided payment excluded from totals and the balance can be paid again
- direct DB update making `amount_paid_cents > total_cents` → throws (constraint)

**Exit criteria**
- [ ] All payment tests green
- [ ] Section 1.6 re-checked in the review

---

## Phase 6 — Overdue logic & daily scheduler

**Goal:** overdue is always computed correctly; the daily job runs once per tenant in that tenant's context.

**Why:** "scheduler ignores tenant isolation" is an automatic fail.

**How it works**
- A scheduled task runs from the command line, where there is **no subdomain** and therefore no tenant. If our command ran directly, the `BelongsToTenant` scope would have no tenant to filter by.
- stancl's `tenants:run` command solves this: it loops over every tenant, **initializes that tenant**, runs our command, then ends tenancy before moving to the next one. Inside our command, `Invoice::overdue()` therefore only sees the current tenant's invoices.
  ```php
  // routes/console.php
  Schedule::command('tenants:run invoices:mark-overdue')
      ->dailyAt('00:05')->withoutOverlapping()->onOneServer();
  ```
- Our command (`invoices:mark-overdue`):
  ```php
  if (! tenancy()->initialized) { $this->error('Run via tenants:run'); return self::FAILURE; }

  Invoice::overdue()->whereNull('overdue_notified_at')->chunkById(500, function ($invoices) {
      foreach ($invoices as $invoice) {
          $invoice->forceFill(['overdue_notified_at' => now()])->saveQuietly();
          SendInvoiceEmail::dispatch($invoice->id, InvoiceEmailEvent::Overdue);
      }
  });
  DashboardCache::flush();
  ```
  The guard at the top makes it impossible to run the command outside a tenant by accident.

**Steps**
1. `app/Console/Commands/MarkOverdueInvoices.php` as above.
2. Schedule in `routes/console.php`.
3. Invoices index: "Overdue" filter uses `Invoice::overdue()`; `StatusBadge` shows "Overdue" from `display_status`.
4. Start `docs/ARCHITECTURE.md` with the "why overdue is computed" section.

**Tests** — `tests/Feature/Invoices/OverdueLogicTest.php` (time is controlled with `$this->travelTo()`)
- on the due date itself → not overdue; the next day → overdue
- unpaid and partial past-due invoices → overdue; paid → never overdue
- paying an overdue invoice in full → no longer overdue (and not in the overdue scope)
- the overdue scope and the `is_overdue` accessor always agree
- running `tenants:run invoices:mark-overdue` with 2 tenants: each tenant's overdue invoices get `overdue_notified_at`, `status` stays unchanged, and one overdue email job is queued per invoice with the correct tenant
- running it twice does not queue a second email
- running `invoices:mark-overdue` without a tenant → fails safely

**Exit criteria**
- [ ] Overdue tests green · `php artisan schedule:list` shows the job

---

## Phase 7 — Queued invoice emails (idempotent, retry-safe)

**Goal:** emails go out in the background, exactly once per event, inside the correct tenant.

**Why:** "queue runs outside correct tenant context" is an automatic fail; idempotency is explicitly required.

**How it works**
- **Tenant context:** when a job is dispatched inside Acme, `QueueTenancyBootstrapper` stores `tenant_id = acme` in the job payload. When the `queue` worker picks it up, it initializes Acme before `handle()` runs, so `Invoice::find()` inside the job is scoped to Acme.
- **Only after commit:** jobs are dispatched with `->afterCommit()`. If the invoice transaction is rolled back, no email is queued, and the job never looks for an invoice that does not exist yet.
- **Idempotency:** the `invoice_email_logs` table has `UNIQUE(tenant_id, invoice_id, event)`.
  ```php
  public function handle(): void
  {
      $invoice = Invoice::with('customer')->find($this->invoiceId);
      if (! $invoice || ! $invoice->customer->email) return;

      InvoiceEmailLog::insertOrIgnore([...tenant_id, invoice_id, event, created_at...]); // claim the slot once
      $log = InvoiceEmailLog::where([...])->lockForUpdate()->first();                   // inside a transaction
      if ($log->sent_at) return;                     // already sent → do nothing (duplicate run)

      Mail::to($invoice->customer->email)->send(new InvoiceIssuedMail($invoice));
      $log->update(['sent_at' => now()]);            // mark as sent
  }
  ```
  - Duplicate dispatch → the second run sees `sent_at` and exits.
  - Crash before sending → `sent_at` is still empty, so the retry sends it.
- **Retry-safe:** `$tries = 5`, `backoff = [10, 60, 300]` seconds, `ShouldBeUnique` with `uniqueId = "{tenant}:{invoice}:{event}"` to avoid piling up identical jobs; a `failed()` method logs the failure.

**Steps**
1. `app/Mail/InvoiceIssuedMail.php`, `InvoiceOverdueMail.php` + Markdown templates (invoice number, total, due date, balance).
2. `app/Jobs/SendInvoiceEmail.php` as above.
3. `IssueInvoiceAction` dispatches `SendInvoiceEmail::dispatch($invoice->id, InvoiceEmailEvent::Issued)->afterCommit();` (drafts never email).
4. Confirm `QueueTenancyBootstrapper` is in `config/tenancy.php`.
5. `invoices/Show.vue`: "Email history" panel from `invoice_email_logs` (like Bukku's email tracker).

**Tests** — `tests/Feature/Jobs/SendInvoiceEmailTest.php`
- issuing an invoice queues exactly one `SendInvoiceEmail`; saving a draft queues none (`Queue::fake()`)
- running the job twice → exactly one email (`Mail::fake()`)
- a log row with `sent_at = null` (simulated crash) → the next run sends
- **tenant context:** dispatch in tenant A on a real (database) queue, end tenancy, process the job with `queue:work --once`, assert it read tenant A's invoice and `tenant('id') === 'A'` inside the job
- an invoice created in a rolled-back transaction queues nothing
- a customer without email → no email, no error

**Exit criteria**
- [ ] Issuing an invoice in the browser → email appears in Mailpit (via the `queue` container)
- [ ] Tests green

---

## Phase 8 — Dashboard & caching

**Goal:** four tenant-scoped numbers, calculated by one SQL query and cached per tenant.

**How it works**

| Metric | Definition (document in README) |
|---|---|
| Current month revenue | Sum of **payments received** this month (cash basis) |
| Outstanding invoices total | Sum of balances of all not-fully-paid invoices |
| Paid invoices total | Sum of totals of fully paid invoices |
| Overdue invoices total | Sum of balances of overdue invoices |

One query, aggregated by the database (no PHP loops):
```sql
SELECT
  COALESCE(SUM(total_cents - amount_paid_cents) FILTER (WHERE status <> 'paid'), 0)                         AS outstanding,
  COALESCE(SUM(total_cents)                     FILTER (WHERE status = 'paid'), 0)                          AS paid,
  COALESCE(SUM(total_cents - amount_paid_cents) FILTER (WHERE status <> 'paid' AND due_date < CURRENT_DATE), 0) AS overdue,
  (SELECT COALESCE(SUM(amount_cents), 0) FROM payments
     WHERE tenant_id = :tenant AND paid_at >= date_trunc('month', CURRENT_DATE))                              AS month_revenue
FROM invoices WHERE tenant_id = :tenant;
```
(Written with the query builder on the tenant-scoped models, so `tenant_id` comes from the scope. The real query also adds `state = 'issued'` to the invoice filters and `voided_at IS NULL` to the payments sub-query — B1, B8.)

**Caching:**
- `Cache::remember('dashboard:stats:'.today()->toDateString(), 600, fn () => …)`. `CacheTenancyBootstrapper` automatically separates the cache per tenant, so Acme and Globex can never read each other's numbers. The date in the key means overdue numbers refresh at midnight.
- **Invalidation:** `InvoiceObserver` and `PaymentObserver` (`created`, `updated`, `deleted`) call `DB::afterCommit(fn () => DashboardCache::flush())`. Waiting for commit stops a request from re-caching old numbers while the transaction is still open.

**Steps**
1. `app/Services/DashboardStatsService.php`, `app/Services/DashboardCache.php` (`key()`, `get()`, `flush()`).
2. `app/Observers/PaymentObserver.php`; extend `InvoiceObserver`; register both with `#[ObservedBy]` on the models.
3. `DashboardController` → `Inertia::render('Dashboard', ['stats' => …])`.
4. `Dashboard.vue`: 4 stat cards, links into the filtered invoice lists, empty state for a new company.

**Tests** — `tests/Feature/Dashboard/DashboardTest.php`
- correct values for a known data set (including a partial and an overdue invoice)
- second request served from cache (count the queries with `DB::enableQueryLog()`)
- creating an invoice / recording a payment → cache cleared, new numbers shown
- tenant A's dashboard never includes tenant B's numbers, even with a warm cache

**Exit criteria**
- [ ] Tests green · dashboard is fast with the performance seed (Phase 10)

---

## Phase 9 — Reports

**Goal:** sales report, outstanding invoices report and customer invoice summary — all calculated in SQL.

**How it works**

| Report | Input | SQL idea | Output |
|---|---|---|---|
| Sales | `from`, `to` | invoices with `issue_date` in range, `GROUP BY date_trunc('month', issue_date)` | per month: invoice count, total billed, total collected; grand total row |
| Outstanding / receivables aging | optional customer, "overdue only" | `WHERE state = 'issued' AND status <> 'paid'`; aging bucket with `CASE WHEN CURRENT_DATE - due_date <= 0 THEN 'current' WHEN … <= 30 …` | (a) aging summary: balance per bucket Current / 1–30 / 31–60 / 61–90 / 90+; (b) invoice list: invoice, customer, due date, total, paid, balance, days overdue; paginated |
| Customer summary | optional date range | `customers LEFT JOIN invoices (state = 'issued') … GROUP BY customers.id` | per customer: invoice count, billed, paid, balance, overdue amount; sortable, paginated |

Every number comes from `SUM` / `COUNT` in the database (spec: "reports must not rely on row-by-row PHP processing"). The indexes from Phase 2 (`tenant_id, issue_date`, `tenant_id, status, due_date`, `tenant_id, customer_id`) are what keep these fast.

**Steps**
1. `app/Services/Reports/SalesReport.php`, `OutstandingInvoicesReport.php`, `CustomerSummaryReport.php` (each returns a query/paginator).
2. `app/Http/Requests/Reports/DateRangeRequest.php` (`from ≤ to`, max range 2 years; default = this month).
3. `ReportController` (`sales`, `outstanding`, `customers`).
4. Pages `reports/Sales.vue`, `Outstanding.vue`, `CustomerSummary.vue` with date filters and loading/empty states.

**Tests** — `tests/Feature/Reports/ReportsTest.php`
- each report's totals match a known data set; drafts and void invoices are excluded
- aging buckets: invoices 0, 15, 45, 75, 120 days past due land in the right bucket
- date boundaries are inclusive (invoices on `from` and `to` are counted)
- only the current tenant's data appears
- invalid range (`from > to`) → validation error

**Exit criteria**
- [ ] Reports work in the browser with the performance seed · tests green

---

## Phase 10 — Seed data & performance check (bonus)

**Goal:** realistic data volumes that prove the indexes, SQL aggregation and caching work.

**How it works**
- Creating 5,000 invoices one by one with factories would take minutes. Instead `PerformanceSeeder` builds arrays in PHP and uses chunked bulk `DB::table()->insert()` (1,000 rows per insert).
- It must keep the data **consistent**: each invoice's `total_cents` = sum of its items; its payments never exceed the total; `amount_paid_cents` and `status` match the payments. A final check query verifies this.
- Then we run `EXPLAIN ANALYZE` on the dashboard and report queries and confirm they use our indexes.

**Steps**
1. `DatabaseSeeder`: tenants `acme` (Acme Sdn Bhd) and `globex` (Globex Trading) + domains + one login each (`admin@acme.test` / `password`, `admin@globex.test` / `password`).
2. `DemoTenantSeeder`: ~20 customers, ~50 invoices with a mix of draft/unpaid/partial/paid/overdue/void and a few voided payments.
3. `PerformanceSeeder` (per tenant): 500 customers, 5,000 invoices, ~15,000 items, ~5,000 payments.
4. Consistency check + `EXPLAIN ANALYZE`; record before/after timings (with and without cache) in `docs/ARCHITECTURE.md`.
5. Browse every page with strict mode on (Phase 0) → fix any N+1 problem by eager loading.

**Exit criteria**
- [ ] `php artisan db:seed --class=PerformanceSeeder` finishes in under ~1 minute
- [ ] Consistency check returns 0 bad rows · pages stay fast · no lazy-loading errors

---

## Phase 11 — Hardening, documentation & submission

**Goal:** pass every guardrail and deliver the required documents.

**Steps**
1. **Isolation sweep:** `tests/Feature/Tenancy/CrossTenantRoutesTest.php` — log in as tenant A and request every route that takes an ID (customers, invoices, payments, reports filtered by customer) using tenant B's IDs → all 404. Also confirm dashboard and report numbers ignore B.
2. Walk through section 1.6 line by line.
3. Final review of the whole repository (structure, naming, dead code, N+1).
4. `README.md`: requirements (PHP 8.3 + pdo_pgsql, Composer, Node, Docker), `docker compose up -d`, `migrate --seed`, `composer run dev`, demo URLs + logins, how to run the scheduler and tests, the key design decisions (overdue, cents, locking, idempotency, caching).
5. `docs/ERD.md` (final) and `docs/ARCHITECTURE.md` (tenancy, payment locking, overdue, email idempotency, caching, performance results).
6. Fresh clone → follow the README → everything works.

**Exit criteria**
- [ ] `php artisan test` all green · `npm run build` succeeds
- [ ] Fresh-clone setup works by following only the README

---

# Part 3 — Reference

## 3.1 Folder structure (target)

```
SaaS-Platform/
├── app/
│   ├── Actions/
│   │   ├── Invoices/{CreateInvoiceAction,UpdateInvoiceAction,IssueInvoiceAction,VoidInvoiceAction,DeleteDraftInvoiceAction}.php
│   │   └── Payments/RecordPaymentAction.php
│   ├── Console/Commands/MarkOverdueInvoices.php
│   ├── Data/{InvoiceData,InvoiceItemData,PaymentData}.php
│   ├── Enums/{InvoiceStatus,InvoiceEmailEvent,PaymentMethod}.php
│   ├── Exceptions/OverpaymentException.php
│   ├── Http/
│   │   ├── Controllers/{Dashboard,Customer,Invoice,Payment,Report}Controller.php
│   │   ├── Middleware/HandleInertiaRequests.php
│   │   └── Requests/{Customers,Invoices,Payments,Reports}/…
│   ├── Jobs/SendInvoiceEmail.php
│   ├── Mail/{InvoiceIssuedMail,InvoiceOverdueMail}.php
│   ├── Models/{Tenant,User,Customer,Invoice,InvoiceItem,Payment,InvoiceEmailLog}.php
│   ├── Observers/{InvoiceObserver,PaymentObserver}.php
│   ├── Policies/{CustomerPolicy,InvoicePolicy}.php
│   ├── Providers/{AppServiceProvider,TenancyServiceProvider}.php
│   ├── Services/
│   │   ├── InvoiceCalculator.php
│   │   ├── DashboardStatsService.php
│   │   ├── DashboardCache.php
│   │   └── Reports/{SalesReport,OutstandingInvoicesReport,CustomerSummaryReport}.php
│   └── Support/Money.php
├── config/tenancy.php
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/{DatabaseSeeder,DemoTenantSeeder,PerformanceSeeder}.php
├── resources/
│   ├── js/
│   │   ├── components/{ui/,DataTable,EmptyState,Pagination,FlashToast,StatusBadge,MoneyInput,InvoiceItemsEditor}.vue
│   │   ├── composables/useMoney.ts
│   │   ├── layouts/AppLayout.vue
│   │   ├── pages/
│   │   │   ├── auth/Login.vue
│   │   │   ├── Dashboard.vue
│   │   │   ├── Error.vue
│   │   │   ├── customers/{Index,Create,Edit,Show}.vue
│   │   │   ├── invoices/{Index,Create,Edit,Show}.vue
│   │   │   ├── payments/Index.vue
│   │   │   └── reports/{Sales,Outstanding,CustomerSummary}.vue
│   │   └── types/index.d.ts
│   └── views/mail/invoices/{created,overdue}.blade.php
├── routes/{web,tenant,console}.php
├── tests/
│   ├── TestCase.php
│   ├── Feature/{Auth,Tenancy,Customers,Invoices,Payments,Jobs,Dashboard,Reports}/…
│   └── Unit/InvoiceCalculatorTest.php
├── docker/postgres/init/01-create-testing-db.sql
├── docker-compose.yml
├── docs/{ERD,ARCHITECTURE}.md
├── PLAN.md
└── README.md
```

## 3.2 Routes (all in `routes/tenant.php`, behind `auth`)

| Method | URL | Controller | Page |
|---|---|---|---|
| GET | `/dashboard` | `DashboardController` | `Dashboard` |
| resource | `/customers` | `CustomerController` | `customers/*` |
| resource | `/invoices` | `InvoiceController` | `invoices/*` |
| POST | `/invoices/{invoice}/issue` | `InvoiceController@issue` | (redirect back) |
| POST | `/invoices/{invoice}/void` | `InvoiceController@void` | (redirect back) |
| POST | `/customers/{customer}/restore` | `CustomerController@restore` | (redirect back) |
| POST | `/invoices/{invoice}/payments` | `PaymentController@store` | (redirect back) |
| POST | `/payments/{payment}/void` | `PaymentController@void` | (redirect back) |
| GET | `/payments` | `PaymentController@index` | `payments/Index` |
| GET | `/reports/sales` | `ReportController@sales` | `reports/Sales` |
| GET | `/reports/outstanding` | `ReportController@outstanding` | `reports/Outstanding` |
| GET | `/reports/customers` | `ReportController@customers` | `reports/CustomerSummary` |

## 3.3 How each spec requirement is covered

| Spec section | Phase |
|---|---|
| 1 Authentication | 1 |
| 2 Customers | 3 |
| 3 Invoices | 4 |
| 4 Payments | 5 |
| 5 Dashboard | 8 |
| 6 Reports | 9 |
| 7 Multi-tenancy | 1, 2, 11 (+ every phase) |
| 8 Queue | 7 |
| 9 Caching | 8 |
| 10 Scheduling | 6 |
| 11 Testing | every phase |
| 12 Performance (bonus) | 2, 8, 9, 10 |
| 13 Seeding (bonus) | 10 |
| Frontend screens | 3, 4, 5, 8, 9 |
| Deliverables (README, ERD, seeds, architecture) | 2, 10, 11 |

## 3.4 Suggested timing

| Day | Phases |
|---|---|
| 1 | 0, 1 |
| 2 | 2, 3, 4 |
| 3 | 5, 6, 7 |
| 4 | 8, 9, 10 |
| 5 | 11 |
