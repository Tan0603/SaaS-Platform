<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/** Thrown when a payment targets an invoice that is not issued (draft or void). Rendered on `amount`. */
class InvoiceNotPayableException extends ValidationException
{
    public static function make(): static
    {
        return static::withMessages([
            'amount' => 'Only an issued invoice can receive payments.',
        ]);
    }
}
