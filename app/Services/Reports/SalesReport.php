<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use Illuminate\Database\Query\Builder;

/**
 * Sales by month, from SQL aggregates only.
 *
 * Scope: issued invoices (drafts and void excluded) whose issue_date is in [from, to], both inclusive.
 * Billed      = SUM(total_cents)
 * Collected   = SUM(amount_paid_cents) of those same invoices (already excludes voided payments)
 * Outstanding = billed - collected
 * Invoice is a BelongsToTenant model, so the query carries `tenant_id = <current tenant>`.
 */
class SalesReport
{
    private const AGGREGATES = 'COUNT(*) AS invoice_count,
        COALESCE(SUM(total_cents), 0) AS billed_cents,
        COALESCE(SUM(amount_paid_cents), 0) AS collected_cents,
        COALESCE(SUM(total_cents - amount_paid_cents), 0) AS outstanding_cents';

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function run(string $from, string $to): array
    {
        $rows = $this->base($from, $to)
            ->selectRaw("to_char(date_trunc('month', issue_date), 'YYYY-MM') AS month, ".self::AGGREGATES)
            ->groupByRaw("date_trunc('month', issue_date)")
            ->orderByRaw("date_trunc('month', issue_date)")
            ->get()
            ->map(fn ($row) => $this->cast($row))
            ->all();

        $totals = $this->cast($this->base($from, $to)->selectRaw(self::AGGREGATES)->first());

        return ['rows' => $rows, 'totals' => $totals];
    }

    private function base(string $from, string $to): Builder
    {
        return Invoice::query()->issued()->whereBetween('issue_date', [$from, $to])->toBase();
    }

    /** @return array<string, mixed> */
    private function cast(object $row): array
    {
        $data = [
            'invoice_count' => (int) $row->invoice_count,
            'billed_cents' => (int) $row->billed_cents,
            'collected_cents' => (int) $row->collected_cents,
            'outstanding_cents' => (int) $row->outstanding_cents,
        ];

        return isset($row->month) ? ['month' => $row->month] + $data : $data;
    }
}
