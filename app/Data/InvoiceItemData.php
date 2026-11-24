<?php

namespace App\Data;

use App\Support\Money;

/** One line of an invoice, already converted to integers (quantity in hundredths, price in cents). */
final readonly class InvoiceItemData
{
    public function __construct(
        public string $description,
        public int $quantityHundredths,
        public int $unitPriceCents,
    ) {}

    /** @param  array{description: string, quantity: string|int|float, unit_price: string|int|float}  $item */
    public static function fromArray(array $item): self
    {
        return new self(
            description: trim($item['description']),
            quantityHundredths: Money::toHundredths($item['quantity']),
            unitPriceCents: Money::toCents($item['unit_price']),
        );
    }
}
