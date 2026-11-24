<?php

namespace App\Data;

use App\Enums\PaymentMethod;
use App\Support\Money;

/** Validated input for recording a payment. The amount is already integer cents. */
final readonly class PaymentData
{
    public function __construct(
        public int $amountCents,
        public string $paidAt,
        public PaymentMethod $method,
        public ?string $reference,
    ) {}

    /** @param  array{amount: string|int, paid_at: string, method: string, reference?: string|null}  $validated */
    public static function fromArray(array $validated): self
    {
        $reference = isset($validated['reference']) ? trim($validated['reference']) : null;

        return new self(
            amountCents: Money::toCents($validated['amount']),
            paidAt: $validated['paid_at'],
            method: PaymentMethod::from($validated['method']),
            reference: $reference === '' ? null : $reference,
        );
    }
}
