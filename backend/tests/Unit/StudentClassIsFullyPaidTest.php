<?php

namespace Tests\Unit;

use App\Models\StudentClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TD-083 B0 — display-path isFullyPaid lives on StudentClass (R94).
 */
class StudentClassIsFullyPaidTest extends TestCase
{
    #[DataProvider('fullyPaidCases')]
    public function test_is_fully_paid_matrix(bool $flag, int $paidAmount, int $charge, bool $expected): void
    {
        $this->assertSame($expected, StudentClass::isFullyPaid($flag, $paidAmount, $charge));
    }

    public static function fullyPaidCases(): array
    {
        return [
            'paid flag alone' => [true, 0, 8000, true],
            'full invoice without flag' => [false, 8000, 8000, true],
            'overpay without flag' => [false, 9000, 8000, true],
            'partial must not count' => [false, 4000, 8000, false],
            'zero charge ignores amount' => [false, 100, 0, false],
            'unpaid empty' => [false, 0, 8000, false],
        ];
    }

    public function test_instance_wrapper_reads_paid_and_charge(): void
    {
        $sc = new StudentClass(['Paid' => 0, 'Charge' => 5000]);
        $this->assertTrue($sc->isFullyPaidWithInvoiceAmount(5000));
        $this->assertFalse($sc->isFullyPaidWithInvoiceAmount(2500));

        $sc->Paid = 1;
        $this->assertTrue($sc->isFullyPaidWithInvoiceAmount(0));
    }
}
