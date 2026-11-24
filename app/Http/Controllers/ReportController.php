<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reports\CustomerSummaryRequest;
use App\Http\Requests\Reports\DateRangeRequest;
use App\Http\Requests\Reports\OutstandingReportRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Reports\CustomerSummaryReport;
use App\Services\Reports\OutstandingInvoicesReport;
use App\Services\Reports\SalesReport;
use Inertia\Inertia;
use Inertia\Response;

/** Thin: the FormRequests authorize + validate, the services run the SQL. */
class ReportController extends Controller
{
    public function sales(DateRangeRequest $request, SalesReport $report): Response
    {
        ['from' => $from, 'to' => $to] = $request->range();

        return Inertia::render('reports/Sales', [
            'filters' => ['from' => $from, 'to' => $to],
            'report' => $report->run($from, $to),
        ]);
    }

    public function outstanding(OutstandingReportRequest $request, OutstandingInvoicesReport $report): Response
    {
        $customerId = $request->customerId();
        $overdueOnly = $request->overdueOnly();

        return Inertia::render('reports/Outstanding', [
            'filters' => ['customer_id' => $customerId ?? '', 'overdue_only' => $overdueOnly],
            'aging' => $report->aging($customerId, $overdueOnly),
            'invoices' => $report->invoices($customerId, $overdueOnly)->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'customer' => $invoice->customer->name,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'total_cents' => $invoice->total_cents,
                'paid_cents' => $invoice->amount_paid_cents,
                'balance_cents' => $invoice->balance_cents,
                'days_overdue' => (int) $invoice->days_overdue,
                'display_status' => $invoice->display_status,
            ]),
            'customers' => Customer::withTrashed()->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    public function customers(CustomerSummaryRequest $request, CustomerSummaryReport $report): Response
    {
        ['from' => $from, 'to' => $to] = $request->range();

        return Inertia::render('reports/CustomerSummary', [
            'filters' => ['from' => $from ?? '', 'to' => $to ?? '', 'sort' => $request->sort(), 'dir' => $request->direction()],
            'customers' => $report->run($from, $to, $request->sort(), $request->direction())->through(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'archived' => $customer->deleted_at !== null,
                'invoice_count' => (int) $customer->invoice_count,
                'billed_cents' => (int) $customer->billed_cents,
                'paid_cents' => (int) $customer->paid_cents,
                'balance_cents' => (int) $customer->balance_cents,
                'overdue_cents' => (int) $customer->overdue_cents,
            ]),
        ]);
    }
}
