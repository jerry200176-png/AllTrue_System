<?php

namespace Tests\Feature;

use App\Services\TransactionDiscountCalculator;
use App\Models\StudentClass;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentClassTransactionDiscountTest extends TestCase
{
    public function test_none_and_zero_normalize_without_reason(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $none = $calculator->calculate(1001, ['type' => 'NONE', 'value' => '999', 'reason' => 'ignored'], 7, 'director');
        $zero = $calculator->calculate(1001, ['type' => 'PERCENTAGE', 'value' => '0', 'reason' => ''], 7, 'director');

        $this->assertSame(0, $none['discount_amount']);
        $this->assertSame('NONE', $zero['type']);
        $this->assertSame(1001, $zero['final_amount']);
    }

    public function test_percentage_uses_string_basis_points_and_half_up(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $snapshot = $calculator->calculate(101, ['type' => 'PERCENTAGE', 'value' => '12.5', 'reason' => 'promo'], 7, 'admin');

        $this->assertSame(13, $snapshot['discount_amount']);
        $this->assertSame(88, $snapshot['final_amount']);
    }

    /**
     * @dataProvider invalidDiscountProvider
     */
    public function test_invalid_and_missing_reason_are_rejected(array $input): void
    {
        $calculator = new TransactionDiscountCalculator();
        $this->expectException(ValidationException::class);
        $calculator->calculate(100, $input, 7, 'director');
    }

    public static function invalidDiscountProvider(): array
    {
        return [
            [['type' => 'FIXED_AMOUNT', 'value' => '1.5', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '1e1', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '100.001', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '10', 'reason' => '']],
        ];
    }

    public function test_largest_remainder_allocation_is_deterministic(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $this->assertSame([34, 33, 33], $calculator->allocate([1, 1, 1], 100));
        $this->assertSame([70, 20, 10], $calculator->allocate([7, 2, 1], 100));
    }

    public function test_server_total_is_authoritative_over_forged_client_totals(): void
    {
        $snapshot = (new TransactionDiscountCalculator())->calculate(
            1000,
            ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'approved', 'original_amount' => 1, 'final_amount' => 999999],
            7,
            'director'
        );

        $this->assertSame(1000, $snapshot['original_amount']);
        $this->assertSame(900, $snapshot['final_amount']);
    }

    public function test_snapshot_is_hidden_by_default_and_legacy_rows_cannot_be_initialized(): void
    {
        $course = new StudentClass();
        $this->assertContains('pricing_snapshot', $course->getHidden());

        $legacy = new StudentClass();
        $legacy->exists = true;
        $legacy->setRawAttributes(['ID' => 1, 'pricing_snapshot' => null]);
        $this->expectException(\LogicException::class);
        $legacy->initializePricingSnapshot(['type' => 'NONE']);
    }
}
