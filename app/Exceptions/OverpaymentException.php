<?php

namespace App\Exceptions;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Thrown when a payment is <= 0 or larger than the invoice balance. It is a ValidationException, so it is
 * rendered as an error on the `amount` field (422 for JSON, redirect back with errors for Inertia).
 */
class OverpaymentException extends ValidationException
{
    public static function make(int $balanceCents): static
    {
        return static::withMessages([
            'amount' => $balanceCents > 0
                ? 'The payment must be greater than 0.00 and at most the outstanding balance of '.Money::format($balanceCents).'.'
                : 'This invoice has no outstanding balance.',
        ]);
    }
}
