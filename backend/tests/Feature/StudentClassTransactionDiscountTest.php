<?php

namespace Tests\Feature;

use App\Services\TransactionDiscountCalculator;
use App\Models\StudentClass;
use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentClassTransactionDiscountTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_teacher_discount_mutations_are_rejected_before_any_preview_fields_are_returned(): void
    {
        [$teacher, $token] = $this->teacherToken();
        $student = $this->student();
        $course = $this->course($student->id, $teacher->id);

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode' => 'purchase_batch',
            'sessions' => 2,
            'start_date' => '2026-10-01',
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'forged'],
        ]);

        $response->assertForbidden()
            ->assertJsonMissingPath('billing.discount')
            ->assertJsonMissingPath('payload.discount')
            ->assertJsonMissingPath('pricing_snapshot');
    }

    public function test_teacher_batch_create_discount_is_rejected_before_validation(): void
    {
        [, $token] = $this->teacherToken();
        $this->withToken($token)->postJson('/api/v1/class-sessions/batch', [
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '1', 'reason' => 'forged'],
        ])->assertForbidden()->assertJsonMissingPath('pricing_snapshot');
    }

    public function test_teacher_preview_and_confirm_errors_never_serialize_discount_data(): void
    {
        [$teacher, $token] = $this->teacherToken();
        $student = $this->student();
        $course = $this->course($student->id, $teacher->id);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $preview = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode' => 'purchase_batch', 'sessions' => 2, 'start_date' => '2026-10-01',
        ]);

        $preview->assertOk()
            ->assertJsonMissingPath('billing.discount')
            ->assertJsonMissingPath('payload.discount')
            ->assertJsonMissingPath('pricing_snapshot');

        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $preview->json('preview_id'),
            'state_hash' => 'forged-state',
            'mode' => 'purchase_batch',
            'payload' => ['sessions' => 2, 'start_date' => '2026-10-01'],
        ])->assertStatus(409)
            ->assertJsonMissingPath('preview.billing.discount')
            ->assertJsonMissingPath('preview.payload.discount')
            ->assertJsonMissingPath('preview.pricing_snapshot');
    }

    public function test_authorized_purchase_uses_server_price_and_does_not_inherit_legacy_discount(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student->id, $director->id);
        $course->Disconunt = 777;
        $course->save();

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 4, 'start_date' => '2026-10-01', 'mode' => 'new_purchase',
            'price_per_session' => 1,
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '500', 'reason' => 'approved'],
        ]);

        $response->assertCreated();
        $new = StudentClass::findOrFail($response->json('new_course.id'));
        $this->assertSame(1500, (int) $new->Charge);
        $this->assertNull($new->Disconunt);
        $this->assertSame('FIXED_AMOUNT', $new->pricing_snapshot['type']);
        $this->assertSame(2000, $new->pricing_snapshot['original_amount']);
    }

    public function test_authorized_purchase_without_discount_keeps_full_server_charge(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student->id, $director->id);

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 4, 'start_date' => '2026-10-01', 'mode' => 'new_purchase',
        ]);

        $response->assertCreated();
        $new = StudentClass::findOrFail($response->json('new_course.id'));
        $this->assertSame(2000, (int) $new->Charge);
        $this->assertSame('NONE', $new->pricing_snapshot['type']);
        $this->assertSame(2000, $new->pricing_snapshot['final_amount']);
    }

    public function test_convert_trial_creates_a_full_price_none_snapshot_without_inheriting_discount(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $trial = $this->course($student->id, $director->id);
        $trial->ClassType = 'trial';
        $trial->Disconunt = 999;
        $trial->StartDate = '2026-09-01';
        $trial->save();

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$trial->ID}/convert-trial", [
            'sessions' => 2, 'start_date' => '2026-10-01', 'class_type' => 'one_on_one',
        ]);

        $response->assertCreated();
        $new = StudentClass::findOrFail($response->json('new_course.id'));
        $this->assertSame(1000, (int) $new->Charge);
        $this->assertNull($new->Disconunt);
        $this->assertSame('NONE', $new->pricing_snapshot['type']);
    }

    public function test_authorized_monthly_renewal_invoice_matches_discounted_course_charge(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student->id, $director->id);
        $course->ScheduleMode = 'date';
        $course->SessionCount = 0;
        $course->RemainingSessions = 0;
        $course->monthly_sessions = 8;
        $course->settlement_day = 15;
        $course->EndDate = '2026-09-30';
        $course->Paid = 1;
        $course->save();

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-10-31',
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '800', 'reason' => 'approved'],
        ]);

        $response->assertCreated();
        $newId = $response->json('new_course.id');
        $new = StudentClass::findOrFail($newId);
        $invoice = Invoice::where('StudentClassID', $newId)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertSame((int) $new->Charge, (int) $invoice->TotalAmount);
        $this->assertSame(4000, (int) $new->Charge);
        $this->assertNull($new->Disconunt);
    }

    /** @return array{0: User, 1: string} */
    private function teacherToken(): array
    {
        $user = User::create([
            'LoginName' => 'teacher-' . bin2hex(random_bytes(4)) . '@example.com',
            'Name' => '測試老師', 'PSW' => 'secret', 'type' => 'T', 'phone' => '0912345678',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$user, $token];
    }

    /** @return array{0: User, 1: string} */
    private function directorToken(): array
    {
        $user = User::create([
            'LoginName' => 'director-' . bin2hex(random_bytes(4)) . '@example.com',
            'Name' => '測試主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$user, $token];
    }

    private function student(): Student
    {
        return Student::create([
            'name' => '折扣測試學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function course(int $studentId, int $teacherId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId, 'TeacherID' => $teacherId, 'GradeID' => 1,
            'SubjectID' => 1, 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'RemainingSessions' => 8, 'UsedSessions' => 0,
            'SessionDuration' => 60, 'TotalHours' => 8, 'Rate' => 500, 'Charge' => 4000,
            'Paid' => 0, 'Stop' => 0, 'StartDate' => '2026-09-01', 'Period' => 4,
            'by1' => $teacherId, 'MDate' => now(),
        ]);
    }
}
