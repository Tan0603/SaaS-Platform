<?php

namespace App\Services\Reports;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\JoinClause;

/**
 * Invoice summary per customer: customers LEFT JOIN issued invoices, GROUP BY customer.
 *
 * Customer carries the tenant scope; the joined invoices repeat the tenant condition explicitly because a raw
 * join bypasses global scopes. Customers without invoices show zeros. Archived (soft-deleted) customers appear
 * only when they have invoices in the range. Sorting uses a whitelist of columns, never user input.
 */
class CustomerSummaryReport
{
    /** Public sort key => SQL column / alias. */
    public const SORTS = [
        'name' => 'customers.name',
        'billed' => 'billed_cents',
        'paid' => 'paid_cents',
        'balance' => 'balance_cents',
        'overdue' => 'overdue_cents',
    ];

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function run(?string $from, ?string $to, string $sort = 'name', string $direction = 'asc', int $perPage = 15): LengthAwarePaginator
    {
        $column = self::SORTS[$sort] ?? self::SORTS['name'];
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        return Customer::withTrashed()
            ->leftJoin('invoices', function (JoinClause $join) use ($from, $to) {
                $join->on('invoices.customer_id', '=', 'customers.id')
                    ->on('invoices.tenant_id', '=', 'customers.tenant_id')
                    ->where('invoices.tenant_id', '=', tenant('id'))
                    ->where('invoices.state', '=', 'issued');

                if ($from) {
                    $join->where('invoices.issue_date', '>=', $from);
                }
                if ($to) {
                    $join->where('invoices.issue_date', '<=', $to);
                }
            })
            ->selectRaw(
                'customers.id, customers.name, customers.deleted_at,
                 COUNT(invoices.id) AS invoice_count,
                 COALESCE(SUM(invoices.total_cents), 0) AS billed_cents,
                 COALESCE(SUM(invoices.amount_paid_cents), 0) AS paid_cents,
                 COALESCE(SUM(invoices.total_cents - invoices.amount_paid_cents), 0) AS balance_cents,
                 COALESCE(SUM(invoices.total_cents - invoices.amount_paid_cents) FILTER (WHERE invoices.status <> ? AND invoices.due_date < ?), 0) AS overdue_cents',
                ['paid', today()->toDateString()],
            )
            ->groupBy('customers.id', 'customers.name', 'customers.deleted_at')
            ->havingRaw('customers.deleted_at IS NULL OR COUNT(invoices.id) > 0')
            ->orderByRaw("$column $direction")
            ->orderBy('customers.name')
            ->orderBy('customers.id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
