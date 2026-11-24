<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer-only money helpers. Amounts are stored as cents; "125.50" <-> 12550 without any float maths.
 */
final class Money
{
    /** Parse a decimal string ("125.50", "125", "0.5") into cents. */
    public static function toCents(string|int|float $amount): int
    {
        return self::toScaledInt($amount, 2);
    }

    /** Parse a quantity with up to 2 decimals ("1.5") into hundredths (150). */
    public static function toHundredths(string|int|float $quantity): int
    {
        return self::toScaledInt($quantity, 2);
    }

    /** Parse a non-negative decimal into an integer scaled by 10^$scale, using string maths only. */
    public static function toScaledInt(string|int|float $value, int $scale): int
    {
        if (is_float($value)) {
            $value = number_format($value, $scale, '.', '');
        }

        $value = trim((string) $value);

        if (! preg_match('/^(\d+)(?:\.(\d{1,'.$scale.'}))?$/', $value, $matches)) {
            throw new InvalidArgumentException("Invalid decimal amount: [{$value}]");
        }

        $fraction = str_pad($matches[2] ?? '', $scale, '0');

        return (int) ($matches[1].$fraction);
    }

    /** Format cents as a plain decimal string: 12550 -> "125.50". */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    /** Format hundredths as a quantity string: 150 -> "1.50". */
    public static function formatHundredths(int $hundredths): string
    {
        return self::format($hundredths);
    }

    public static function isValid(string $amount): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) === 1;
    }
}
