<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('search', ''));
        $archived = $request->boolean('archived');

        $customers = Customer::query()
            ->select(['id', 'name', 'email', 'phone', 'payment_terms_days', 'deleted_at'])
            ->withCount('invoices')
            ->when($archived, fn ($query) => $query->onlyTrashed())
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'ILIKE', $term)->orWhere('email', 'ILIKE', $term));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search, 'archived' => $archived],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/Form', [
            'customer' => null,
            'paymentTerms' => Customer::PAYMENT_TERMS,
            'defaultPaymentTerms' => 30,
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        Customer::create($request->validated());

        return redirect()->route('customers.index')->with('success', 'Customer created.');
    }

    public function show(Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        $invoices = $customer->invoices()
            ->select(['id', 'customer_id', 'number', 'issue_date', 'due_date', 'state', 'status', 'total_cents', 'amount_paid_cents'])
            ->latest('issue_date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'display_status' => $invoice->display_status,
                'total_cents' => $invoice->total_cents,
                'balance_cents' => $invoice->balance_cents,
            ]);

        return Inertia::render('customers/Show', [
            'customer' => $customer->only([
                'id', 'name', 'email', 'phone', 'address', 'registration_no', 'payment_terms_days', 'deleted_at',
            ]),
            'invoices' => $invoices,
        ]);
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('customers/Form', [
            'customer' => $customer->only([
                'id', 'name', 'email', 'phone', 'address', 'registration_no', 'payment_terms_days',
            ]),
            'paymentTerms' => Customer::PAYMENT_TERMS,
            'defaultPaymentTerms' => 30,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    /** Archive (soft delete): invoices and history are kept. */
    public function destroy(Customer $customer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $customer->delete();

        return redirect()->route('customers.index')->with('success', 'Customer archived.');
    }

    /**
     * Permanent delete: only for customers with no invoices at all (any state).
     * A customer with invoices must be archived instead so the history stays intact.
     */
    public function forceDestroy(Customer $customer): RedirectResponse
    {
        Gate::authorize('forceDelete', $customer);

        if ($customer->invoices()->exists()) {
            return back()->with('error', 'This customer has invoices and cannot be deleted. Archive it instead.');
        }

        try {
            $customer->forceDelete();
        } catch (QueryException $e) {
            // An invoice was created for this customer at the same moment: the FK (restrict) refuses the delete.
            if ($e->getCode() === '23503') {
                return back()->with('error', 'This customer has invoices and cannot be deleted. Archive it instead.');
            }

            throw $e;
        }

        return redirect()->route('customers.index')->with('success', 'Customer deleted permanently.');
    }

    public function restore(Customer $customer): RedirectResponse
    {
        Gate::authorize('restore', $customer);

        $customer->restore();

        return redirect()->route('customers.index', ['archived' => 1])->with('success', 'Customer restored.');
    }
}
