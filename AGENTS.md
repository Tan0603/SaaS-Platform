# AGENTS.md — SaaS Accounting System

Instructions for AI coding agents (Claude Code, Codex, Cursor, etc.) working in this repository.
Read this file first, then [PLAN.md](PLAN.md) for the detailed design and the phase-by-phase build order.

- **Assessment:** Pey Qi Assessment 2 — SaaS Accounting System (5 days)
- **Start:** 2026-11-23 · **Review:** 2026-11-30
- **Branching:** work on `feature/*` branches; never commit directly to `main`. Do not commit or push unless the user asks.

---

## 1. What we are building

A simplified **multi-tenant SaaS accounting system**. Each company (tenant) manages its own **customers**, **invoices** and **payments**.

The graders focus on:
1. **Backend correctness** — totals, statuses and balances are always right
2. **Data integrity** — the database itself refuses impossible data
3. **Multi-tenancy safety** — no cross-tenant data access under any condition

---

## 2. Tech stack (required by the spec)

| Layer | Technology | Notes |
|---|---|---|
| Backend | Laravel 12+ | Core framework |
| Frontend | Vue 3 via Inertia | Basic UI only; no separate API layer |
| Starter kit | Laravel Vue Inertia starter kit | Provides auth and base layout |
| Cache / queue | Redis | Caching and background jobs |
| Database | PostgreSQL 18 | Row locks and `CHECK` constraints |
| Multitenancy | `stancl/tenancy` (^3.10, single-database mode) | Tenant isolation layer |
| Local env (bonus) | Docker | `docker-compose.yml`, project name `saas-accounting` |

---

## 3. Spec requirements (from the question paper)

### Business scope
- Each user belongs to exactly one tenant (company).
- All data is strictly isolated per tenant.
- No cross-tenant data access is allowed under any condition.

### Core features
1. **Authentication** — login / logout.
2. **Customer management** — CRUD. Fields: `name`, `email`, `phone`, `address`.
3. **Invoice management** — create invoices with multiple items.
   - Item fields: `description`, `quantity`, `unit_price`.
   - The system calculates `subtotal` and `total`.
   - Invoice fields: `issue_date`, `due_date`, `status` (`unpaid`, `partial`, `paid`, `overdue`).
   - `overdue` is **computed, not stored as the source of truth**.
4. **Payment management**
   - Record payments against invoices; partial payments allowed.
   - **Overpayment is NOT allowed** (must be validated).
   - Payments update invoice status automatically.
   - Total payments must never exceed the invoice total.
   - Status must always reflect: `unpaid` → no payments · `partial` → partly paid · `paid` → fully settled · `overdue` → due_date passed AND not fully paid.
5. **Dashboard** (tenant-scoped) — current month revenue, outstanding invoices total, paid invoices total, overdue invoices total.
6. **Reports** — sales report (date range), outstanding invoices report, customer invoice summary.

### Technical requirements
7. **Multi-tenancy** — must use `stancl/tenancy`. All models tenant-scoped. Isolation must apply to Eloquent queries, relationships, **queued jobs** and **scheduled tasks**.
8. **Queue (Redis)** — send invoice emails asynchronously; jobs must be **idempotent** (no duplicate email per event) and **retry-safe**.
9. **Caching (Redis)** — cache dashboard stats per tenant; invalidate when an invoice or payment is created/updated.
10. **Task scheduling** — daily job marks invoices overdue when `due_date < today AND total_paid < total`.
11. **Testing** — required feature tests:
    - authentication
    - invoice creation
    - payment processing (partial + full + rejection)
    - overdue logic

    Rules: use **model factories**, use **database refresh**, and cover edge cases: overpayment prevention, partial payment correctness, overdue transition correctness.

### Bonus
12. **Performance** — no N+1 queries (eager loading); indexes on foreign keys, invoice lookups and payment aggregation; SQL-level aggregation (no PHP loops) for reports/dashboard; per-tenant dashboard caching with proper invalidation. Bonus signals: DB transactions, row locking, idempotent jobs, safe recalculation logic.
13. **Seeding** — per tenant: 200–500 customers, 2,000–5,000 invoices, 5,000–15,000 invoice items, 2,000–5,000 payments. The app must stay responsive; reports must not rely on row-by-row PHP processing.

### Frontend (Vue + Inertia)
- Screens: Login, Dashboard, Customers CRUD, Invoices CRUD, Payments screen.
- Inertia navigation only (no separate API). Basic components are fine. Backend-driven validation preferred.
- Every page must handle **loading**, **error** and **empty** states.

### Deliverables
Git repository · README setup guide · ERD/schema documentation · seed data · optional architecture notes.

---

## 4. Grading

| Area | Points | Full marks means |
|---|---|---|
| Multi-tenancy | 25 | Fully isolated across all layers; jobs and scheduler respect tenant context |
| Business logic | 25 | No overpayment, correct partial payments, consistent state logic, overdue computed |
| Database design | 15 | Normalized and scalable |
| Code quality | 20 | Clean architecture and reusable logic |
| Testing | 10 | Strong, with edge cases |
| Frontend | 5 | Usable with proper UX states |
| Bonus | 0–20 | Performance, seeding, transactions, locking |

**Pass rule:** Multi-tenancy ≥ 15, Business logic ≥ 15, Core ≥ 60, and no critical failure. 60–80 = borderline, 80+ = good.

### 🚨 Automatic fail conditions — never ship any of these
- A multi-tenant data leak
- Overpayment is possible
- Invoice totals are inconsistent
- Invoice state logic is unreliable
- A queued job runs outside the correct tenant context
- The scheduler ignores tenant isolation

---

## 5. Non-negotiable rules for agents

Check every change against these. They map directly to the automatic-fail list.

**Tenancy**
- Every business model uses stancl's `BelongsToTenant` trait and has a `tenant_id` column (including `invoice_items` and `payments`).
- Fetching another tenant's record by ID must return **404**.
- Validation `exists` / `unique` rules must be tenant-scoped (e.g. `Rule::exists('customers','id')->where('tenant_id', tenant('id'))`). A plain `exists:customers,id` bypasses the Eloquent scope.
- Queued jobs rely on `QueueTenancyBootstrapper`; scheduled work runs per tenant (`tenants:run` or iterate tenants and `$tenant->run(...)`).
- Cache keys are per tenant (`CacheTenancyBootstrapper` or an explicit tenant prefix).

**Money and totals**
- Store money as **integer cents** (`*_cents` columns). Never use floats.
- The server always recalculates line totals, subtotal and total from the items; totals sent by the browser are ignored.

**Payments and status**
- Overpayment is blocked in three layers: FormRequest validation → `lockForUpdate()` check inside `DB::transaction` → database `CHECK (amount_paid_cents <= total_cents)`.
- `amount_paid_cents` and `status` are written by **one method only**: `Invoice::recalculatePaymentState()`.
- `status` stores payment state (`unpaid` / `partial` / `paid`). **Overdue is computed** (accessor + query scope): `state = issued AND due_date < today AND status != paid`. The daily job may record `overdue_notified_at` and queue a reminder, but never treats a stored flag as the truth.
- Drafts, void invoices and voided payments are excluded from every total (see PLAN.md §1.8).

**Jobs and cache**
- Email jobs are idempotent: a unique `(tenant_id, invoice_id, event)` row in `invoice_email_logs` prevents duplicates; dispatch after commit.
- Invalidate the tenant's dashboard cache whenever an invoice or payment is created, updated or voided.

**Performance**
- Eager-load relationships; enable `Model::shouldBeStrict()` outside production.
- Aggregate with SQL (`SUM`, `GROUP BY`, `FILTER`), not PHP loops.
- Index foreign keys, `(tenant_id, …)` lookup columns and payment aggregation columns.

---

## 6. Code layout and conventions

```
Controller  →  FormRequest (validation)  →  Action (one write operation) / Service (calculation or read query)  →  Model
```

- Controllers stay thin: validate, call an Action, redirect or `Inertia::render()`.
- Actions (`app/Actions/...`) hold business writes, e.g. `RecordPaymentAction`, `IssueInvoiceAction`.
- Services (`app/Services/...`) hold calculations and read queries, e.g. `InvoiceCalculator`, `DashboardStatsService`.
- Observers handle cache invalidation and email dispatch.
- Vue pages live in `resources/js/pages/<area>/<Page>.vue` (TypeScript, shadcn-vue components from the starter kit).
- Follow existing Laravel and starter-kit conventions; run Pint before finishing a change.

---

## 7. Local development

Docker runs only the services; Laravel, the queue worker, the scheduler and Vite run on the host.

| Service | Address |
|---|---|
| PostgreSQL 18 | `127.0.0.1:5432`, user `postgres`, no password; DBs `saas_platform` and `saas_platform_testing` |
| Redis | `127.0.0.1:6379` |
| Mailpit | SMTP `127.0.0.1:1025`, inbox `http://localhost:8025` |

```bash
docker compose up -d            # start postgres, redis, mailpit (group: saas-accounting)
composer run dev                # app server + queue listener + logs + Vite
php artisan schedule:work       # scheduler (second terminal)
php artisan test                # feature tests against saas_platform_testing (PostgreSQL, not SQLite)
vendor/bin/pint                 # code style
npm run build                   # frontend build check
```

Tenants are reached by subdomain, e.g. `http://acme.localhost:8000` (`*.localhost` resolves to 127.0.0.1 in modern browsers).

---

## 8. How agents should work here

- Build **one PLAN.md phase at a time**. A phase is done only when all its exit criteria pass and the §5 rules hold.
- Write or update the feature tests in the same change as the behaviour they cover. Tests run on PostgreSQL so row locks and `CHECK` constraints are real.
- Before reporting a task done, run `php artisan test` and `vendor/bin/pint --test`, and report failures honestly.
- When delegating to subagents: **Opus** plans, **Sonnet** writes code, **Haiku** researches and runs tests.
- If the spec and PLAN.md disagree, the spec (§3 above) wins; flag the conflict to the user.
