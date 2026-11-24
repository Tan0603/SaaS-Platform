<?php

namespace App\Services;

use App\Enums\InvoiceState;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;

/**
 * The four dashboard numbers for the current tenant, from ONE SQL statement (no PHP loops).
 *
 * Both models are tenant-scoped (BelongsToTenant), so the invoice query AND the payments sub-select carry
 * `tenant_id = <current tenant>`. Dates are computed in PHP (today(), startOfMonth()) and bound, so the
 * numbers agree exactly with Invoice::overdue() / is_overdue and with travelTo() in tests.
 * Drafts and void invoices are excluded (state = issued); voided payments are excluded (voided_at IS NULL).
 */
class DashboardStatsService
{
    /**
     * @return array{month_revenue_cents: int, month_payments_count: int, outstanding_cents: int, outstanding_count: int, paid_cents: int, paid_count: int, overdue_cents: int, overdue_count: int, generated_at: string}
     */
    public function compute(): array
    {
        $today = today()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $payments = fn (string $aggregate) => Payment::query()->active()
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->selectRaw($aggregate);

        $paid = InvoiceStatus::Paid->value;

        $row = Invoice::query()
            ->selectRaw(
                'COALESCE(SUM(total_cents - amount_paid_cents) FILTER (WHERE status <> ?), 0) AS outstanding_cents,
                 COUNT(*) FILTER (WHERE status <> ?) AS outstanding_count,
                 COALESCE(SUM(total_cents) FILTER (WHERE status = ?), 0) AS paid_cents,
                 COUNT(*) FILTER (WHERE status = ?) AS paid_count,
                 COALESCE(SUM(total_cents - amount_paid_cents) FILTER (WHERE status <> ? AND due_date < ?), 0) AS overdue_cents,
                 COUNT(*) FILTER (WHERE status <> ? AND due_date < ?) AS overdue_count',
                [$paid, $paid, $paid, $paid, $paid, $today, $paid, $today],
            )
            ->selectSub($payments('COALESCE(SUM(amount_cents), 0)'), 'month_revenue_cents')
            ->selectSub($payments('COUNT(*)'), 'month_payments_count')
            ->where('state', InvoiceState::Issued->value)
            ->toBase()
            ->first();

        return [
            'month_revenue_cents' => (int) $row->month_revenue_cents,
            'month_payments_count' => (int) $row->month_payments_count,
            'outstanding_cents' => (int) $row->outstanding_cents,
            'outstanding_count' => (int) $row->outstanding_count,
            'paid_cents' => (int) $row->paid_cents,
            'paid_count' => (int) $row->paid_count,
            'overdue_cents' => (int) $row->overdue_cents,
            'overdue_count' => (int) $row->overdue_count,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
