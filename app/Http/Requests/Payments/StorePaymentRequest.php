<?php

namespace App\Http\Requests\Payments;

use App\Data\PaymentData;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Overpayment layer 1: friendly field errors. The authoritative check is re-done under a row lock in
 * RecordPaymentAction; this one only reads the stored balance and can be stale.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The invoice comes from tenant-scoped route binding (other tenants = 404 before we get here).
        return $this->user()?->can('view', $this->route('invoice')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return [
            'amount' => [
                'bail',
                'required',
                'regex:/^\d{1,7}(\.\d{1,2})?$/',
                function (string $attribute, mixed $value, Closure $fail) use ($invoice) {
                    $cents = Money::toCents($value);

                    if ($cents <= 0) {
                        $fail('The payment must be greater than 0.00.');
                    } elseif ($cents > $invoice->balance_cents) {
                        $fail('The payment cannot exceed the outstanding balance of '.Money::format(max($invoice->balance_cents, 0)).'.');
                    }
                },
            ],
            'paid_at' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:'.$invoice->issue_date->toDateString(),
            ],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter a valid amount (for example 125.50).',
            'paid_at.before_or_equal' => 'The payment date cannot be in the future.',
            'paid_at.after_or_equal' => 'The payment date cannot be before the invoice issue date.',
        ];
    }

    public function toData(): PaymentData
    {
        return PaymentData::fromArray($this->validated());
    }
}
