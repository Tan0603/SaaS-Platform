<?php

namespace App\Enums;

/** Payment state of an invoice. "Overdue" is computed, never stored here. */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
