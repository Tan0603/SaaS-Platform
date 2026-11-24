<?php

namespace App\Services;

use App\Data\InvoiceItemData;

/**
 * The single place where invoice amounts are calculated (pure integer maths, no database, no floats).
 *
 *   line_total = round_half_up(quantity x unit_price)
 *   subtotal   = sum of line totals
 *   total      = subtotal            (no tax in scope; add it here later)
 */
class InvoiceCalculator
{
    /** Quantity is in hundredths (1.5 => 150), unit price in cents. Rounds half up. */
    public function lineTotal(int $quantityHundredths, int $unitPriceCents): int
    {
        return intdiv($quantityHundredths * $unitPriceCents + 50, 100);
    }

    /**
     * @param  list<InvoiceItemData>  $items
     * @return array{lines: list<int>, subtotal_cents: int, total_cents: int}
     */
    public function calculate(array $items): array
    {
        $lines = array_map(
            fn (InvoiceItemData $item) => $this->lineTotal($item->quantityHundredths, $item->unitPriceCents),
            $items,
        );

        $subtotal = array_sum($lines);

        return [
            'lines' => array_values($lines),
            'subtotal_cents' => $subtotal,
            'total_cents' => $subtotal,
        ];
    }
}
