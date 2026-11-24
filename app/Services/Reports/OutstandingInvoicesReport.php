<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Receivables: issued invoices that are not paid and still have a balance.
 *
 * Days past due = today - due_date, with "today" bound from PHP (so tests can freeze time).
 * Buckets: current (days <= 0, not yet due or due today), 1-30, 31-60, 61-90, 90+ (days > 90).
 * Overdue = days past due >= 1, the same rule as Invoice::overdue().
 */
class OutstandingInvoicesReport
{
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

    /**
     * Balance and invoice count per aging bucket plus a total, in one SQL statement.
     *
     * @return array<string, array{balance_cents: int, count: int}>
     */
    public function aging(?int $customerId, bool $overdueOnly): array
    {
        $days = '(?::date - due_date)';
        $today = today()->toDateString();

        $conditions = [
            'current' => "$days <= 0",
            'days_1_30' => "$days BETWEEN 1 AND 30",
            'days_31_60' => "$days BETWEEN 31 AND 60",
            'days_61_90' => "$days BETWEEN 61 AND 90",
            'days_90_plus' => "$days > 90",
        ];

        $select = [];
        $bindings = [];
        foreach ($conditions as $key => $condition) {
            $select[] = "COALESCE(SUM(total_cents - amount_paid_cents) FILTER (WHERE $condition), 0) AS {$key}_cents";
            $select[] = "COUNT(*) FILTER (WHERE $condition) AS {$key}_count";
            array_push($bindings, $today, $today);
        }
        $select[] = 'COALESCE(SUM(total_cents - amount_paid_cents), 0) AS total_cents';
        $select[] = 'COUNT(*) AS total_count';

        $row = $this->base($customerId, $overdueOnly)
            ->selectRaw(implode(', ', $select), $bindings)
            ->toBase()
            ->first();

        $result = [];
        foreach ([...self::BUCKETS, 'total'] as $key) {
            $result[$key] = ['balance_cents' => (int) $row->{$key.'_cents'}, 'count' => (int) $row->{$key.'_count'}];
        }

        return $result;
    }

    /**
     * Oldest due date first. The customer is eager loaded (no N+1).
     *
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function invoices(?int $customerId, bool $overdueOnly, int $perPage = 15): LengthAwarePaginator
    {
        return $this->base($customerId, $overdueOnly)
            ->select('invoices.*')
            ->selectRaw('GREATEST(0, ?::date - due_date) AS days_overdue', [today()->toDateString()])
            ->with('customer:id,name')
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return Builder<Invoice> */
    private function base(?int $customerId, bool $overdueOnly): Builder
    {
        return Invoice::query()
            ->outstanding()
            ->whereRaw('total_cents - amount_paid_cents > 0')
            ->when($customerId, fn (Builder $query, int $id) => $query->where('customer_id', $id))
            ->when($overdueOnly, fn (Builder $query) => $query->overdue());
    }
}
