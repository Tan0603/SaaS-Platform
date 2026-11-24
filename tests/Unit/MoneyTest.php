<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function validAmounts(): array
    {
        return [
            'two decimals' => ['125.50', 12550],
            'whole number' => ['125', 12500],
            'one decimal' => ['0.5', 50],
            'cents only' => ['0.07', 7],
            'zero' => ['0', 0],
            'padding spaces' => [' 10.00 ', 1000],
            'large' => ['999999999.99', 99999999999],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_it_converts_a_decimal_string_to_cents(string $input, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($input));
    }

    public function test_floats_and_ints_are_converted_without_drift(): void
    {
        $this->assertSame(1999, Money::toCents(19.99));
        $this->assertSame(1000, Money::toCents(10));
        $this->assertSame(10, Money::toCents(0.1));
    }

    /** @return array<string, array{string}> */
    public static function invalidAmounts(): array
    {
        return [
            'three decimals' => ['1.234'],
            'negative' => ['-1.00'],
            'letters' => ['abc'],
            'empty' => [''],
            'comma' => ['1,50'],
            'trailing dot' => ['1.'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents($input);
    }

    public function test_cents_are_formatted_back_to_a_decimal_string(): void
    {
        $this->assertSame('125.50', Money::format(12550));
        $this->assertSame('0.07', Money::format(7));
        $this->assertSame('0.00', Money::format(0));
        $this->assertSame('-1.05', Money::format(-105));
    }

    public function test_round_trip_is_lossless(): void
    {
        foreach ([0, 1, 99, 100, 12345, 99999999] as $cents) {
            $this->assertSame($cents, Money::toCents(Money::format($cents)));
        }
    }

    public function test_quantity_is_converted_to_hundredths(): void
    {
        $this->assertSame(150, Money::toHundredths('1.5'));
        $this->assertSame(300, Money::toHundredths(3));
        $this->assertSame('1.50', Money::formatHundredths(150));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Money::isValid('12.5'));
        $this->assertFalse(Money::isValid('12.555'));
        $this->assertFalse(Money::isValid('-1'));
    }
}
