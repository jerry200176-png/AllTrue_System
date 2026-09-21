<?php

namespace Tests\Feature;

use App\Services\TransactionDiscountCalculator;
use App\Models\StudentClass;
use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            [['type' => 'FIXED_AMOUNT', 'value' => '-1', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '1e1', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '-1', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '100.001', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '12.345', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '10', 'reason' => '']],
        ];
    }

    public function test_percentage_boundary_at_100_is_authoritative(): void
    {
        $snapshot = (new TransactionDiscountCalculator())->calculate(
            1001,
            ['type' => 'PERCENTAGE', 'value' => '100', 'reason' => 'full waiver'],
            7,
            'director'
        );

        $this->assertSame(1001, $snapshot['discount_amount']);
        $this->assertSame(0, $snapshot['final_amount']);
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

    public function test_financial_role_matrix_accepts_admin_and_super_admin_discount_contexts(): void
    {
        $calculator = new TransactionDiscountCalculator();
        foreach (['admin', 'super_admin'] as $role) {
            $snapshot = $calculator->calculate(1000, [
                'type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'approved',
            ], 7, $role);
            $this->assertSame($role, $snapshot['actor_role']);
            $this->assertSame(900, $snapshot['final_amount']);
        }
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

    public function test_persisted_snapshot_cannot_be_mutated_after_creation(): void
    {
        [$teacher] = $this->teacherToken();
        $student = $this->student();
        $course = $this->course($student->id, $teacher->id);
        $course->initializePricingSnapshot(['type' => 'NONE', 'final_amount' => 0]);
        $course->pricing_snapshot = ['type' => 'FIXED_AMOUNT'];
        $this->expectException(\LogicException::class);
        $course->save();
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

        $response->assertForbidden();
        $this->assertArrayNotHasKey('discount', (array) $response->json('billing'));
        $this->assertArrayNotHasKey('discount', (array) $response->json('payload'));
        $this->assertArrayNotHasKey('pricing_snapshot', $response->json());
    }

    public function test_teacher_batch_create_discount_is_rejected_before_validation(): void
    {
        [, $token] = $this->teacherToken();
        $response = $this->withToken($token)->postJson('/api/v1/class-sessions/batch', [
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '1', 'reason' => 'forged'],
        ]);
        $response->assertForbidden();
        $this->assertArrayNotHasKey('pricing_snapshot', $response->json());
    }

    public function test_teacher_direct_purchase_and_monthly_renewal_discount_mutations_are_forbidden(): void
    {
        [$teacher, $token] = $this->teacherToken();
        $student = $this->student();
        $course = $this->course($student->id, $teacher->id);
        $monthly = $this->course($student->id, $teacher->id);
        $monthly->ScheduleMode = 'date';
        $monthly->SessionCount = 0;
        $monthly->RemainingSessions = 0;
        $monthly->monthly_sessions = 8;
        $monthly->settlement_day = 15;
        $monthly->EndDate = '2026-09-30';
        $monthly->save();
        $discount = ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'forged'];
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 2, 'start_date' => '2026-10-01', 'mode' => 'new_purchase', 'discount' => $discount,
        ])->assertForbidden();
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$monthly->ID}/renew-monthly", [
            'end_date' => '2026-10-31', 'discount' => $discount,
        ])->assertForbidden();
    }

    public function test_authorized_batch_create_applies_server_discount_and_allocation(): void
    {
        [$director, $token] = $this->directorToken();
        [$teacher] = $this->teacherToken();
        $this->grantTeacherSubjects($teacher->id, ['Math', 'English']);
        $student = $this->student();
        $response = $this->withToken($token)->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1, 'student_id' => $student->id, 'teacher_id' => $teacher->id,
            'subject' => 'Math', 'class_type' => 'one_on_one', 'total_classes' => 2,
            'confirmed_dates' => [], 'future_dates' => ['2030-10-07'],
            'session_plan' => [
                ['session_date' => '2030-10-07', 'start_time' => '11:00', 'kind' => 'future', 'subject' => 'Math'],
                ['session_date' => '2030-10-07', 'start_time' => '13:00', 'kind' => 'future', 'subject' => 'English'],
            ],
            'days_of_week' => [1],
            'day_time_slots' => [
                ['day' => 1, 'start_time' => '11:00', 'duration_minutes' => 120, 'subject' => 'Math'],
                ['day' => 1, 'start_time' => '13:00', 'duration_minutes' => 120, 'subject' => 'English'],
            ],
            'start_time' => '11:00', 'duration_minutes' => 120,
            'price_per_session' => 500, 'payment_type' => 'session', 'course_start_date' => '2030-10-07',
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '200', 'reason' => 'approved'],
        ]);
        $this->assertSame(201, $response->status(), 'Batch endpoint response: ' . $response->getContent());
        $courses = StudentClass::where('StudentID', $student->id)->get();
        $this->assertCount(2, $courses);
        $this->assertSame(800, (int) $courses->sum('Charge'));
        $this->assertSame([800, 800], $courses->map(fn (StudentClass $course) => $course->pricing_snapshot['final_amount'])->sort()->values()->all());
        $this->assertSame([1000, 1000], $courses->map(fn (StudentClass $course) => $course->pricing_snapshot['original_amount'])->sort()->values()->all());
        $this->assertSame([200, 200], $courses->map(fn (StudentClass $course) => $course->pricing_snapshot['discount_amount'])->sort()->values()->all());
        $this->assertSame([800, 800], $courses->map(fn (StudentClass $course) => $course->pricing_snapshot['final_amount'])->sort()->values()->all());
    }

    public function test_super_admin_can_authorize_discounted_batch_endpoint(): void
    {
        [, $token] = $this->superAdminToken();
        [$teacher] = $this->teacherToken();
        $this->grantTeacherSubjects($teacher->id, ['Math']);
        $student = $this->student();
        $response = $this->withToken($token)->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1, 'student_id' => $student->id, 'teacher_id' => $teacher->id,
            'subject' => 'Math', 'class_type' => 'one_on_one', 'total_classes' => 1,
            'confirmed_dates' => [], 'future_dates' => ['2026-10-01'],
            'session_plan' => [['session_date' => '2026-10-01', 'start_time' => '16:00', 'kind' => 'future', 'subject' => 'Math']],
            'days_of_week' => [4], 'start_time' => '16:00', 'duration_minutes' => 120,
            'price_per_session' => 500, 'payment_type' => 'session', 'course_start_date' => '2026-10-01',
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'approved'],
        ]);
        $response->assertCreated('Batch endpoint response: ' . $response->getContent());
        $new = StudentClass::findOrFail($response->json('student_class_ids.0'));
        $this->assertSame(400, (int) $new->pricing_snapshot['final_amount']);
    }

    public function test_authorized_renewal_preview_normalizes_discount_without_writing_source(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student->id, $director->id);
        $before = $course->fresh()->getRawOriginal();
        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode' => 'purchase_batch', 'sessions' => 2, 'start_date' => '2026-10-01',
            'discount' => ['type' => 'PERCENTAGE', 'value' => '12.50', 'reason' => 'approved'],
        ]);
        $response->assertOk()->assertJsonPath('billing.discount.type', 'PERCENTAGE')
            ->assertJsonPath('billing.discount.value', '12.5');
        $this->assertSame($before, $course->fresh()->getRawOriginal());
        $this->assertNotEmpty($response->json('state_hash'));
    }

    public function test_authorized_renewal_confirm_recalculates_and_forwards_discount(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student->id, $director->id);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $discount = ['type' => 'FIXED_AMOUNT', 'value' => '200', 'reason' => 'approved'];
        $preview = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode' => 'purchase_batch', 'sessions' => 4, 'start_date' => '2026-10-01', 'discount' => $discount,
        ])->assertOk()->json();
        $response = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $preview['preview_id'], 'state_hash' => $preview['state_hash'],
            'mode' => $preview['mode'],
            'payload' => [
                'sessions' => 4,
                'start_date' => '2026-10-01',
                'discount' => $discount,
            ],
        ]);
        $this->assertSame(201, $response->status(), 'Renewal confirm response: ' . $response->getContent());
        $new = StudentClass::findOrFail($response->json('new_course.id'));
        $this->assertSame(1800, (int) $new->Charge);
        $this->assertSame('FIXED_AMOUNT', $new->pricing_snapshot['type']);
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

        $preview->assertOk();
        $this->assertArrayNotHasKey('discount', (array) $preview->json('billing'));
        $this->assertArrayNotHasKey('discount', (array) $preview->json('payload'));
        $this->assertArrayNotHasKey('pricing_snapshot', $preview->json());

        $confirm = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $preview->json('preview_id'),
            'state_hash' => 'forged-state',
            'mode' => 'purchase_batch',
            'payload' => ['sessions' => 2, 'start_date' => '2026-10-01'],
        ])->assertStatus(409);
        $previewJson = (array) $confirm->json('preview');
        $this->assertArrayNotHasKey('discount', (array) ($previewJson['billing'] ?? null));
        $this->assertArrayNotHasKey('discount', (array) ($previewJson['payload'] ?? null));
        $this->assertArrayNotHasKey('pricing_snapshot', $previewJson);
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

    public function test_discounted_purchase_does_not_rewrite_existing_invoice_or_payment(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student->id, $director->id);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 4000, 'PaidAmount' => 4000,
            'Status' => 'paid', 'Note' => 'legacy invoice',
        ]);
        $payment = Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 4000, 'PaidAt' => '2026-09-01',
            'Method' => 'cash', 'Note' => 'legacy payment',
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 2, 'start_date' => '2026-10-01', 'mode' => 'new_purchase',
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'approved'],
        ]);

        $response->assertCreated();
        $this->assertSame(4000, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame(4000, (int) $invoice->fresh()->PaidAmount);
        $this->assertSame(4000, (int) $payment->fresh()->Amount);
        $this->assertSame($invoice->id, $payment->fresh()->InvoiceID);
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
        $this->assertSame((int) $new->Charge, (int) InvoiceItem::where('InvoiceID', $invoice->id)->sum('Amount'));
        $this->assertSame(3200, (int) $new->Charge);
        $this->assertNull($new->Disconunt);
    }

    public function test_convert_trial_does_not_inherit_snapshot_or_legacy_discount(): void
    {
        [$director, $token] = $this->directorToken();
        $student = $this->student();
        $trial = $this->course($student->id, $director->id);
        $trial->ClassType = 'trial';
        $trial->Disconunt = 999;
        $trial->save();
        $trial->initializePricingSnapshot(['type' => 'FIXED_AMOUNT', 'value' => '999', 'final_amount' => 1]);
        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$trial->ID}/convert-trial", [
            'sessions' => 2, 'start_date' => '2026-10-01', 'class_type' => 'one_on_one',
        ]);
        $response->assertCreated();
        $new = StudentClass::findOrFail($response->json('new_course.id'));
        $this->assertSame('NONE', $new->pricing_snapshot['type']);
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

    /** @return array{0: User, 1: string} */
    private function superAdminToken(): array
    {
        $user = User::create([
            'LoginName' => 'super-' . bin2hex(random_bytes(4)) . '@example.com',
            'Name' => '測試超級管理員', 'PSW' => 'secret', 'type' => 'S', 'phone' => '0912345678',
        ]);
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

    private function grantTeacherSubjects(int $teacherId, array $names): void
    {
        foreach ($names as $name) {
            $subjectId = (int) DB::table('Subject')->where('Subject_Name', $name)->value('id');
            if ($subjectId <= 0 || !DB::getSchemaBuilder()->hasTable('teacher_subject_levels')) {
                continue;
            }
            DB::table('teacher_subject_levels')->insertOrIgnore([
                'teacher_id' => $teacherId,
                'subject_id' => $subjectId,
                'level' => 'junior',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
