<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\TransactionDiscountCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Founder 2026-10-14「不要有折扣功能了」: no new transaction discount anywhere, by default. */
class TransactionDiscountDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_discounts_are_off_by_default(): void
    {
        $this->assertFalse(config('billing.transaction_discounts_enabled'));
    }

    /** @dataProvider realDiscountProvider */
    public function test_calculator_refuses_any_real_discount(array $input): void
    {
        try {
            (new TransactionDiscountCalculator())->calculate(1000, $input, 7, 'director');
            $this->fail('a real discount was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors());
        }
    }

    public static function realDiscountProvider(): array
    {
        return [
            'fixed' => [['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'promo']],
            'percentage' => [['type' => 'PERCENTAGE', 'value' => '12.5', 'reason' => 'promo']],
            'full waiver' => [['type' => 'PERCENTAGE', 'value' => '100', 'reason' => 'waiver']],
            'fixed equal to price' => [['type' => 'FIXED_AMOUNT', 'value' => '1000', 'reason' => 'waiver']],
        ];
    }

    public function test_no_discount_still_prices_at_full_amount(): void
    {
        $calculator = new TransactionDiscountCalculator();
        foreach ([null, ['type' => 'NONE', 'value' => '999'], ['type' => 'FIXED_AMOUNT', 'value' => '0'], ['type' => 'PERCENTAGE', 'value' => '0']] as $input) {
            $snapshot = $calculator->calculate(1000, $input, 7, 'director');
            $this->assertSame('NONE', $snapshot['type']);
            $this->assertSame(1000, $snapshot['final_amount']);
        }
    }

    public function test_director_purchase_with_a_discount_is_refused_and_writes_nothing(): void
    {
        [$director, $token] = $this->director();
        $student = Student::create([
            'name' => '折扣停用測試', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $director->id, 'GradeID' => 1,
            'SubjectID' => 1, 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'RemainingSessions' => 8, 'UsedSessions' => 0,
            'SessionDuration' => 60, 'TotalHours' => 8, 'Rate' => 500, 'Charge' => 4000,
            'Paid' => 0, 'Stop' => 0, 'StartDate' => '2026-09-01', 'Period' => 4,
            'by1' => $director->id, 'MDate' => now(),
        ]);
        $courses = StudentClass::where('StudentID', $student->id)->count();
        $invoices = Invoice::where('StudentID', $student->id)->count();

        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 2, 'start_date' => '2032-05-01', 'mode' => 'new_purchase',
            'discount' => ['type' => 'PERCENTAGE', 'value' => '100', 'reason' => 'waiver'],
        ])->assertStatus(422)->assertJsonValidationErrors('discount');

        $this->assertSame($courses, StudentClass::where('StudentID', $student->id)->count());
        $this->assertSame($invoices, Invoice::where('StudentID', $student->id)->count());

        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 2, 'start_date' => '2032-05-01', 'mode' => 'new_purchase',
        ])->assertCreated();
    }

    /** @return array{0: User, 1: string} */
    private function director(): array
    {
        $user = User::create([
            'LoginName' => 'a-' . bin2hex(random_bytes(4)) . '@example.com',
            'Name' => '測試主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$user, $token];
    }
}
