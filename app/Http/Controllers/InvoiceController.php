<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Actions\Invoices\DeleteDraftInvoiceAction;
use App\Actions\Invoices\IssueInvoiceAction;
use App\Actions\Invoices\UpdateInvoiceAction;
use App\Actions\Invoices\VoidInvoiceAction;
use App\Enums\PaymentMethod;
use App\Http\Requests\Invoices\StoreInvoiceRequest;
use App\Http\Requests\Invoices\UpdateInvoiceRequest;
use App\Http\Requests\Invoices\VoidInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    /** Values of the status filter; they match what the status badge shows (Invoice::display_status). */
    private const STATUS_FILTERS = ['draft', 'unpaid', 'partial', 'paid', 'overdue', 'void'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUS_FILTERS)],
            'customer_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $invoices = Invoice::query()
            ->select(['id', 'customer_id', 'number', 'issue_date', 'due_date', 'state', 'status', 'total_cents', 'amount_paid_cents'])
            ->with('customer:id,name')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->withDisplayStatus($status))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('issue_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('issue_date', '<=', $to))
            ->latest('issue_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'customer' => $invoice->customer->name,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'display_status' => $invoice->display_status,
                'total_cents' => $invoice->total_cents,
                'balance_cents' => $invoice->balance_cents,
            ]);

        return Inertia::render('invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'customer_id' => $filters['customer_id'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'statuses' => self::STATUS_FILTERS,
            'customers' => Customer::withTrashed()->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Invoice::class);

        return Inertia::render('invoices/Form', [
            'invoice' => null,
            'customers' => $this->customerChoices(),
            'defaultPaymentTerms' => (int) tenant('default_payment_terms_days'),
            'today' => today()->toDateString(),
        ]);
    }

    public function store(StoreInvoiceRequest $request, CreateInvoiceAction $createInvoice): RedirectResponse
    {
        $invoice = $createInvoice->execute($request->toData(), $request->shouldIssue());

        return redirect()->route('invoices.show', $invoice)
            ->with('success', $request->shouldIssue() ? "Invoice {$invoice->number} issued." : 'Draft saved.');
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load([
            'customer:id,name,email,deleted_at',
            'items',
            'payments' => fn ($query) => $query->latest('paid_at')->latest('id'),
        ]);

        $user = $request->user();

        return Inertia::render('invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'state' => $invoice->state->value,
                'display_status' => $invoice->display_status,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'subtotal_cents' => $invoice->subtotal_cents,
                'total_cents' => $invoice->total_cents,
                'amount_paid_cents' => $invoice->amount_paid_cents,
                'balance_cents' => $invoice->balance_cents,
                'void_reason' => $invoice->void_reason,
                'customer' => $invoice->customer->only(['id', 'name', 'email']),
                'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'line_total_cents' => $item->line_total_cents,
                ])->values(),
                'payments' => $invoice->payments->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'amount_cents' => $payment->amount_cents,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'method' => $payment->method->value,
                    'reference' => $payment->reference,
                    'voided' => $payment->voided_at !== null,
                    'void_reason' => $payment->void_reason,
                    'can_void' => $user->can('void', $payment),
                ])->values(),
            ],
            'can' => [
                'update' => $user->can('update', $invoice),
                'issue' => $user->can('issue', $invoice),
                'void' => $user->can('void', $invoice),
                'delete' => $user->can('delete', $invoice),
                'record_payment' => $user->can('recordPayment', $invoice),
            ],
            'methods' => PaymentMethod::options(),
            'today' => today()->toDateString(),
        ]);
    }

    public function edit(Invoice $invoice): Response
    {
        Gate::authorize('update', $invoice);

        $invoice->load('items');

        return Inertia::render('invoices/Form', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'state' => $invoice->state->value,
                'customer_id' => $invoice->customer_id,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => Money::format($item->unit_price_cents),
                ])->values(),
            ],
            'customers' => $this->customerChoices($invoice->customer_id),
            'defaultPaymentTerms' => (int) tenant('default_payment_terms_days'),
            'today' => today()->toDateString(),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice, UpdateInvoiceAction $updateInvoice): RedirectResponse
    {
        $invoice = $updateInvoice->execute($invoice, $request->toData(), $request->shouldIssue());

        return redirect()->route('invoices.show', $invoice)->with('success', 'Invoice updated.');
    }

    /** Drafts only; issued invoices are voided instead. */
    public function destroy(Invoice $invoice, DeleteDraftInvoiceAction $deleteDraft): RedirectResponse
    {
        Gate::authorize('delete', $invoice);

        $deleteDraft->execute($invoice);

        return redirect()->route('invoices.index')->with('success', 'Draft deleted.');
    }

    public function issue(Invoice $invoice, IssueInvoiceAction $issueInvoice): RedirectResponse
    {
        Gate::authorize('issue', $invoice);

        $invoice = $issueInvoice->execute($invoice);

        return redirect()->route('invoices.show', $invoice)->with('success', "Invoice {$invoice->number} issued.");
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice, VoidInvoiceAction $voidInvoice): RedirectResponse
    {
        $voidInvoice->execute($invoice, $request->validated('reason'));

        return redirect()->route('invoices.show', $invoice)->with('success', 'Invoice voided.');
    }

    /**
     * Customers that can be picked for an invoice: active ones (plus the invoice's current customer when editing).
     *
     * @return Collection<int, Customer>
     */
    private function customerChoices(?int $keepCustomerId = null): Collection
    {
        return Customer::withTrashed()
            ->select(['id', 'name', 'payment_terms_days'])
            ->where(function ($query) use ($keepCustomerId) {
                $query->whereNull('deleted_at');

                if ($keepCustomerId !== null) {
                    $query->orWhere('id', $keepCustomerId);
                }
            })
            ->orderBy('name')
            ->get();
    }
}
