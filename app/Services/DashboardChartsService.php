<?php

namespace App\Services;

use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Query\JoinClause;

/**
 * Chart data for the dashboard of the current tenant. Every chart is ONE grouped SQL statement.
 *
 * All queries start from a BelongsToTenant model (Invoice / Payment), so `tenant_id = <current tenant>` is applied by
 * the global scope; the customers join repeats the tenant on its ON clause. Dates are computed in PHP and bound
 * (never CURRENT_DATE), so the charts agree with Invoice::overdue() and with travelTo() in tests.
 * Drafts and void invoices (state <> issued) and voided payments never count. The only PHP loop is over the 12 month
 * buckets (zero-filling), never over invoice or payment rows.
 */
class DashboardChartsService
{
    public const MONTHS = 12;

    public const TOP_CUSTOMERS = 5;

    /**
     * @return array{
     *     monthly: array{labels: list<string>, months: list<string>, invoiced_cents: list<int>, collected_cents: list<int>},
     *     status: list<array{key: string, label: string, count: int, total_cents: int}>,
     *     top_customers: list<array{id: int, name: string, outstanding_cents: int, invoice_count: int}>
     * }
     */
    public function compute(): array
    {
        return [
            'monthly' => $this->monthly(),
            'status' => $this->statusBreakdown(),
            'top_customers' => $this->topCustomers(),
        ];
    }

    /**
     * Invoiced (issued invoices by issue_date, SUM total) vs collected (non-voided payments by paid_at, SUM amount)
     * for the current month and the 11 before it.
     *
     * @return array{labels: list<string>, months: list<string>, invoiced_cents: list<int>, collected_cents: list<int>}
     */
    private function monthly(): array
    {
        $first = now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $from = $first->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $invoiced = Invoice::query()
            ->where('state', InvoiceState::Issued->value)
            ->whereBetween('issue_date', [$from, $to])
            ->selectRaw("to_char(date_trunc('month', issue_date), 'YYYY-MM') AS ym, SUM(total_cents) AS cents")
            ->groupByRaw("date_trunc('month', issue_date)")
            ->toBase()
            ->pluck('cents', 'ym');

        $collected = Payment::query()->active()
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("to_char(date_trunc('month', paid_at), 'YYYY-MM') AS ym, SUM(amount_cents) AS cents")
            ->groupByRaw("date_trunc('month', paid_at)")
            ->toBase()
            ->pluck('cents', 'ym');

        $labels = $months = $invoicedSeries = $collectedSeries = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $first->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $months[] = $key;
            $labels[] = $month->format('M Y');
            $invoicedSeries[] = (int) ($invoiced[$key] ?? 0);
            $collectedSeries[] = (int) ($collected[$key] ?? 0);
        }

        return [
            'labels' => $labels,
            'months' => $months,
            'invoiced_cents' => $invoicedSeries,
            'collected_cents' => $collectedSeries,
        ];
    }

    /**
     * Issued invoices grouped like the badges / list filters: overdue (past due, not paid), paid, and
     * unpaid / partial that are NOT overdue. The four groups never overlap. Amount = invoice totals.
     *
     * @return list<array{key: string, label: string, count: int, total_cents: int}>
     */
    private function statusBreakdown(): array
    {
        $today = today()->toDateString();
        $paid = InvoiceStatus::Paid->value;
        $unpaid = InvoiceStatus::Unpaid->value;
        $partial = InvoiceStatus::Partial->value;

        $row = Invoice::query()
            ->where('state', InvoiceState::Issued->value)
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status = ? AND due_date >= ?) AS unpaid_count,
                 COALESCE(SUM(total_cents) FILTER (WHERE status = ? AND due_date >= ?), 0) AS unpaid_cents,
                 COUNT(*) FILTER (WHERE status = ? AND due_date >= ?) AS partial_count,
                 COALESCE(SUM(total_cents) FILTER (WHERE status = ? AND due_date >= ?), 0) AS partial_cents,
                 COUNT(*) FILTER (WHERE status = ?) AS paid_count,
                 COALESCE(SUM(total_cents) FILTER (WHERE status = ?), 0) AS paid_cents,
                 COUNT(*) FILTER (WHERE status <> ? AND due_date < ?) AS overdue_count,
                 COALESCE(SUM(total_cents) FILTER (WHERE status <> ? AND due_date < ?), 0) AS overdue_cents',
                [
                    $unpaid, $today, $unpaid, $today,
                    $partial, $today, $partial, $today,
                    $paid, $paid,
                    $paid, $today, $paid, $today,
                ],
            )
            ->toBase()
            ->first();

        return [
            ['key' => 'unpaid', 'label' => 'Unpaid', 'count' => (int) $row->unpaid_count, 'total_cents' => (int) $row->unpaid_cents],
            ['key' => 'partial', 'label' => 'Partial', 'count' => (int) $row->partial_count, 'total_cents' => (int) $row->partial_cents],
            ['key' => 'paid', 'label' => 'Paid', 'count' => (int) $row->paid_count, 'total_cents' => (int) $row->paid_cents],
            ['key' => 'overdue', 'label' => 'Overdue', 'count' => (int) $row->overdue_count, 'total_cents' => (int) $row->overdue_cents],
        ];
    }

    /**
     * The 5 customers (archived ones included) owing the most on issued, not-paid invoices.
     *
     * @return list<array{id: int, name: string, outstanding_cents: int, invoice_count: int}>
     */
    private function topCustomers(): array
    {
        return Invoice::query()
            ->join('customers', function (JoinClause $join) {
                $join->on('customers.id', '=', 'invoices.customer_id')
                    ->on('customers.tenant_id', '=', 'invoices.tenant_id');
            })
            ->where('invoices.state', InvoiceState::Issued->value)
            ->where('invoices.status', '<>', InvoiceStatus::Paid->value)
            ->groupBy('customers.id', 'customers.name')
            ->selectRaw('customers.id AS id, customers.name AS name, SUM(invoices.total_cents - invoices.amount_paid_cents) AS outstanding_cents, COUNT(*) AS invoice_count')
            ->havingRaw('SUM(invoices.total_cents - invoices.amount_paid_cents) > 0')
            ->orderByDesc('outstanding_cents')
            ->orderBy('customers.name')
            ->limit(self::TOP_CUSTOMERS)
            ->toBase()
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'outstanding_cents' => (int) $r->outstanding_cents,
                'invoice_count' => (int) $r->invoice_count,
            ])
            ->all();
    }
}
