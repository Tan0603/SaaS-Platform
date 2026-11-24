<?php

namespace Tests\Unit;

use App\Data\InvoiceItemData;
use App\Services\InvoiceCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvoiceCalculator;
    }

    private function item(string $quantity, string $unitPrice): InvoiceItemData
    {
        return InvoiceItemData::fromArray(['description' => 'Item', 'quantity' => $quantity, 'unit_price' => $unitPrice]);
    }

    public function test_one_item(): void
    {
        $result = $this->calculator->calculate([$this->item('2', '10.00')]);

        $this->assertSame([2000], $result['lines']);
        $this->assertSame(2000, $result['subtotal_cents']);
        $this->assertSame(2000, $result['total_cents']);
    }

    public function test_several_items_are_summed(): void
    {
        $result = $this->calculator->calculate([
            $this->item('1', '100.00'),
            $this->item('3', '19.99'),
            $this->item('2', '0.50'),
        ]);

        $this->assertSame([10000, 5997, 100], $result['lines']);
        $this->assertSame(16097, $result['subtotal_cents']);
        $this->assertSame(16097, $result['total_cents']);
    }

    public function test_decimal_quantity_1_5_times_3_33_rounds_half_up(): void
    {
        // 1.5 x 3.33 = 4.995 -> 5.00
        $this->assertSame(500, $this->calculator->calculate([$this->item('1.5', '3.33')])['total_cents']);
    }

    public function test_rounding_is_half_up_and_below_half_rounds_down(): void
    {
        $this->assertSame(1, $this->calculator->lineTotal(50, 1));   // 0.005 -> 0.01 (exactly half rounds up)
        $this->assertSame(0, $this->calculator->lineTotal(49, 1));   // 0.0049 -> 0.00
        $this->assertSame(2, $this->calculator->lineTotal(150, 1));  // 1.5 cents -> 2
        $this->assertSame(1, $this->calculator->lineTotal(149, 1));  // 1.49 cents -> 1
    }

    public function test_zero_price_gives_zero(): void
    {
        $result = $this->calculator->calculate([$this->item('5', '0')]);

        $this->assertSame([0], $result['lines']);
        $this->assertSame(0, $result['total_cents']);
    }

    public function test_no_items_gives_zero(): void
    {
        $this->assertSame(0, $this->calculator->calculate([])['total_cents']);
    }

    public function test_results_are_integers_without_float_drift(): void
    {
        // 0.1 + 0.2 style trap: three lines of 0.10 must be exactly 0.30.
        $result = $this->calculator->calculate([$this->item('1', '0.10'), $this->item('1', '0.20')]);

        $this->assertSame(30, $result['total_cents']);
        $this->assertIsInt($result['total_cents']);
    }

    public function test_large_amounts_stay_exact(): void
    {
        // Largest allowed line: quantity 100000.00 x price 9,999,999.99 stays an exact integer.
        $total = $this->calculator->lineTotal(10000000, 999999999);

        $this->assertIsInt($total);
        $this->assertSame(999999999 * 100000, $total);
    }
}
