<?php

namespace App\Http\Controllers;

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VoidPaymentAction;
use App\Enums\PaymentMethod;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Requests\Payments\VoidPaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    /** The payments screen: every payment of the tenant, newest first. */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payment::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'include_voided' => ['nullable', 'boolean'],
        ]);
        $includeVoided = (bool) ($filters['include_voided'] ?? false);

        $payments = Payment::query()
            ->select(['id', 'invoice_id', 'amount_cents', 'paid_at', 'method', 'reference', 'voided_at'])
            ->with('invoice:id,number,customer_id', 'invoice.customer:id,name')
            ->when(! $includeVoided, fn ($query) => $query->active())
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('paid_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('paid_at', '<=', $to))
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->where('method', $method))
            ->latest('paid_at')
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'paid_at' => $payment->paid_at->toDateString(),
                'invoice_id' => $payment->invoice->id,
                'invoice_number' => $payment->invoice->number,
                'customer' => $payment->invoice->customer->name,
                'method' => $payment->method->value,
                'reference' => $payment->reference,
                'amount_cents' => $payment->amount_cents,
                'voided' => $payment->voided_at !== null,
            ]);

        return Inertia::render('payments/Index', [
            'payments' => $payments,
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'method' => $filters['method'] ?? '',
                'include_voided' => $includeVoided,
            ],
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function store(StorePaymentRequest $request, Invoice $invoice, RecordPaymentAction $recordPayment): RedirectResponse
    {
        $recordPayment->execute($invoice->id, $request->toData());

        return redirect()->route('invoices.show', $invoice)->with('success', 'Payment recorded.');
    }

    public function void(VoidPaymentRequest $request, Payment $payment, VoidPaymentAction $voidPayment): RedirectResponse
    {
        $voidPayment->execute($payment, $request->validated('reason'));

        return redirect()->route('invoices.show', $payment->invoice_id)->with('success', 'Payment voided.');
    }
}
