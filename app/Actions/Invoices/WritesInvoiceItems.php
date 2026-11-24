<?php

namespace App\Actions\Invoices;

use App\Data\InvoiceData;
use App\Models\Invoice;
use App\Services\InvoiceCalculator;
use App\Support\Money;

/**
 * Shared by Create/Update: (re)writes the item rows and the invoice totals from the items alone.
 * Must be called inside a DB transaction.
 */
trait WritesInvoiceItems
{
    private function writeItems(Invoice $invoice, InvoiceData $data, InvoiceCalculator $calculator): void
    {
        $result = $calculator->calculate($data->items);

        $invoice->items()->delete();

        foreach ($data->items as $index => $item) {
            // tenant_id is filled by BelongsToTenant when the row is created through the relation.
            $invoice->items()->create([
                'position' => $index + 1,
                'description' => $item->description,
                'quantity' => Money::formatHundredths($item->quantityHundredths),
                'unit_price_cents' => $item->unitPriceCents,
                'line_total_cents' => $result['lines'][$index],
            ]);
        }

        $invoice->forceFill([
            'subtotal_cents' => $result['subtotal_cents'],
            'total_cents' => $result['total_cents'],
        ])->save();
    }
}
