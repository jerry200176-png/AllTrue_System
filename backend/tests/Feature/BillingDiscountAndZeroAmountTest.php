<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\DunningService;
use App\Services\NotificationSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_free_course_has_no_payment_obligation_and_zero_amount_is_never_recorded(): void
    {
        $token = $this->director();
        $student = $this->student();
        $paid = $this->course($student->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);
        $freeTrial = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500);
        $record = fn (StudentClass $sc, int $amount) => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/payment-reports/director-record', [
                'student_class_id' => $sc->ID, 'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'amount' => $amount,
            ]);

        $record($paid, 0)->assertStatus(422)->assertJsonPath('code', 'zero_amount_for_paid_course');
        $record($freeTrial, 0)->assertStatus(422)->assertJsonPath('code', 'no_payment_obligation')
            ->assertJsonPath('message', '此課程免收費（折扣後 0 元或未設定收費），不需要登記繳費。');
        $record($freeTrial, 500)->assertStatus(422)->assertJsonPath('code', 'no_payment_obligation');
        $this->assertSame(0, PaymentReport::whereIn('StudentClassID', [$paid->ID, $freeTrial->ID])->count(), 'nothing recorded');
    }

    public function test_a_billed_course_is_never_free(): void
    {
        $student = $this->student();
        $course = $this->course($student->id, ['Rate' => 0, 'SessionCount' => 4, 'Charge' => 0]);
        $this->assertTrue($course->isFreeOfCharge());

        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => now(),
            'DueDate' => now(), 'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'void']);
        $this->assertTrue($course->isFreeOfCharge(), 'a void invoice does not bill the course');

        $invoice->update(['Status' => 'unpaid']);
        $this->assertFalse($course->isFreeOfCharge(), 'a non-void NT$3000 invoice means the course is billed');
    }

    public function test_reminder_producers_skip_free_courses(): void
    {
        $freeTrial = $this->course($this->student()->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500);
        $unpaid = $this->course($this->student()->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);
        StudentClass::whereIn('ID', [$freeTrial->ID, $unpaid->ID])->update(['MDate' => now()->subDays(15)]);
        if (Schema::hasColumn('StudentClass', 'created_at')) {
            StudentClass::whereIn('ID', [$freeTrial->ID, $unpaid->ID])->update(['created_at' => now()->subDays(15)]);
        }

        $dunned = collect(app(DunningService::class)->evaluateAll(1, false))
            ->where('rule_key', 'unpaid_reminder')->pluck('student_class_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $unpaid->ID, $dunned);
        $this->assertNotContains((int) $freeTrial->ID, $dunned, 'dunning: no unpaid event for a free trial');

        NotificationSyncService::sync([1]);
        $this->assertDatabaseHas('Notifications', ['SourceKey' => "tuition:1:{$unpaid->ID}"]);
        $this->assertDatabaseMissing('Notifications', ['SourceKey' => "tuition:1:{$freeTrial->ID}"]);

        $this->artisan('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7])
            ->expectsOutput('Found 1 overdue unpaid course(s).')
            ->assertSuccessful();
    }

    public function test_course_index_projects_free_trial_as_free(): void
    {
        $token = $this->director();
        $student = $this->student();
        $freeTrial = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500);
        $unpaid = $this->course($student->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);

        $rows = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/student-classes?student_id={$student->id}&per_page=100")->assertOk()->json('data'))
            ->keyBy(fn ($r) => (int) ($r['ID'] ?? $r['id']));

        $this->assertSame('free', $rows[$freeTrial->ID]['payment_status']);
        $this->assertSame(0, (int) $rows[$freeTrial->ID]['charge'], 'not replaced by Rate × sessions (1500)');
        $this->assertFalse((bool) $rows[$freeTrial->ID]['charge_is_fallback']);
        $this->assertSame('unpaid', $rows[$unpaid->ID]['payment_status']);
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
