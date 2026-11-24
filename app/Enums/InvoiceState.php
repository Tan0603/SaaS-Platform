<?php

namespace App\Enums;

/** Document lifecycle of an invoice (separate from its payment status). */
enum InvoiceState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
