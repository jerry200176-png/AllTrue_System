<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #349 / #361: a transaction discount (allocated into Charge; Rate keeps the list price) must drive billing.
 * in-app #346: a NT$0 payment record may only settle a genuinely free course.
 */
class BillingDiscountAndZeroAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_drives_contract_total_and_free_detection(): void
    {
        $student = $this->student();
        $plain = $this->course($student->id, ['Rate' => 1800, 'SessionCount' => 4, 'Charge' => 7200]);
        $discounted = $this->course($student->id, ['Rate' => 1800, 'SessionCount' => 4, 'Charge' => 5400], 1800);
        $freeTrial = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500);

        $this->assertNull($plain->discountedContractTotal());
        $this->assertSame(5400, $discounted->discountedContractTotal());
        $this->assertSame(0, $freeTrial->discountedContractTotal());
        $this->assertFalse($plain->isFreeOfCharge());
        $this->assertFalse($discounted->isFreeOfCharge());
        $this->assertTrue($freeTrial->isFreeOfCharge(), 'a trial discounted to NT$0 is free even though Rate > 0');
    }

    public function test_tuition_alerts_use_the_discounted_total_and_drop_free_trials(): void
    {
        $token = $this->director();
        $student = $this->student();
        $discounted = $this->course($student->id, ['Rate' => 1800, 'SessionCount' => 4, 'Charge' => 5400], 1800);
        $freeTrial = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500);

        $rows = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
        $byCourse = $rows->whereNotNull('id')->keyBy(fn ($r) => (int) $r['id']);

        $this->assertTrue($byCourse->has($discounted->ID), 'discounted unpaid course is listed');
        $this->assertSame(5400, (int) $byCourse[$discounted->ID]['charge'], 'charge is the discounted total, not Rate × sessions (7200)');
        $this->assertFalse($byCourse->has($freeTrial->ID), 'a trial discounted to NT$0 does not ask for payment');
    }

    public function test_zero_amount_record_only_for_free_courses(): void
    {
        $token = $this->director();
        $student = $this->student();
        $paid = $this->course($student->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);
        $freeTrial = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500);
        $record = fn (StudentClass $sc) => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/payment-reports/director-record', [
                'student_class_id' => $sc->ID, 'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'amount' => 0,
            ]);

        $record($paid)->assertStatus(422)->assertJsonPath('code', 'zero_amount_for_paid_course');
        $this->assertSame(0, PaymentReport::where('StudentClassID', $paid->ID)->count(), 'nothing recorded for a paid course');
        $record($freeTrial)->assertOk();
    }

    private function course(int $studentId, array $overrides, int $discount = 0): StudentClass
    {
        $course = StudentClass::create(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 20, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionDuration' => 120, 'RemainingSessions' => 1, 'ClassType' => 'one_on_one',
            'UsedSessions' => 0, 'rate_unit' => 'session',
        ], $overrides));
        if ($discount > 0) {
            $original = (int) $overrides['Charge'] + $discount;
            $course->initializePricingSnapshot([
                'transaction_id' => 'test', 'original_amount' => $original, 'type' => 'FIXED_AMOUNT', 'value' => (string) $discount,
                'discount_amount' => $discount, 'final_amount' => $original - $discount, 'reason' => '舊生介紹',
                'actor_id' => 1, 'actor_role' => 'director',
            ]);
        }

        return $course->fresh();
    }

    private function student(): Student
    {
        return Student::create(['name' => '折扣學生' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function director(): string
    {
        $user = User::create(['LoginName' => 'disc_' . uniqid() . '@test.com', 'Name' => 'Dir', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }
}
