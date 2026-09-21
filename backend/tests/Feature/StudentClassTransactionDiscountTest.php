<?php

namespace Tests\Feature;

use App\Models\StudentClass;
use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\TransactionDiscountCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentClassTransactionDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_none_and_zero_are_normalized_without_reason(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $none = $calculator->calculate(1001, ['type' => 'NONE', 'value' => '999'], 7, 'director');
        $zero = $calculator->calculate(1001, ['type' => 'PERCENTAGE', 'value' => '0', 'reason' => ''], 7, 'director');

        $this->assertSame(0, $none['discount_amount']);
        $this->assertSame('NONE', $zero['type']);
        $this->assertSame(1001, $zero['final_amount']);
    }

    public function test_percentage_uses_integer_half_up_math_and_forged_totals_are_ignored(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $snapshot = $calculator->calculate(101, [
            'type' => 'PERCENTAGE', 'value' => '12.5', 'reason' => 'promo',
            'original_amount' => 1, 'final_amount' => 999999, 'transaction_id' => 'forged-client-id',
        ], 7, 'director');

        $this->assertSame(13, $snapshot['discount_amount']);
        $this->assertSame(88, $snapshot['final_amount']);
        $this->assertSame(101, $snapshot['original_amount']);
        $this->assertNotSame('forged-client-id', $snapshot['transaction_id']);
    }

    /** @dataProvider invalidDiscountProvider */
    public function test_invalid_values_and_missing_reason_are_rejected(array $input): void
    {
        $this->expectException(ValidationException::class);
        (new TransactionDiscountCalculator())->calculate(100, $input, 7, 'director');
    }

    public static function invalidDiscountProvider(): array
    {
        return [
            [['type' => 'FIXED_AMOUNT', 'value' => '1.5', 'reason' => 'x']],
            [['type' => 'FIXED_AMOUNT', 'value' => '-1', 'reason' => 'x']],
            [['type' => 'FIXED_AMOUNT', 'value' => '400', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '1e1', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '-1', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '12.345', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '100.001', 'reason' => 'x']],
            [['type' => 'PERCENTAGE', 'value' => '10', 'reason' => '']],
            [['type' => 'FIXED_AMOUNT', 'value' => 100, 'reason' => 'x']],
            [['type' => 'FIXED_AMOUNT', 'value' => "\x00", 'reason' => 'x']],
        ];
    }

    public function test_percentage_100_preserves_money_invariant(): void
    {
        $snapshot = (new TransactionDiscountCalculator())->calculate(1001, [
            'type' => 'PERCENTAGE', 'value' => '100', 'reason' => 'full waiver',
        ], 7, 'director');

        $this->assertSame(1001, $snapshot['discount_amount']);
        $this->assertSame(0, $snapshot['final_amount']);
        $this->assertSame($snapshot['original_amount'] - $snapshot['discount_amount'], $snapshot['final_amount']);
    }

    public function test_largest_remainder_allocation_is_stable(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $this->assertSame([34, 33, 33], $calculator->allocate([1, 1, 1], 100));
        $this->assertSame([70, 20, 10], $calculator->allocate([7, 2, 1], 100));
    }

    public function test_percentage_discount_is_rounded_once_before_multi_row_allocation(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $snapshot = $calculator->calculate(303, [
            'type' => 'PERCENTAGE', 'value' => '12.5', 'reason' => 'multi-subject promotion',
        ], 7, 'director');
        $rows = $calculator->allocate([101, 101, 101], $snapshot['final_amount']);

        $this->assertSame(38, $snapshot['discount_amount']);
        $this->assertSame(265, $snapshot['final_amount']);
        $this->assertSame(265, array_sum($rows));
        $this->assertSame(303 - $snapshot['discount_amount'], $snapshot['final_amount']);
    }

    public function test_each_calculation_gets_an_explicit_transaction_id(): void
    {
        $calculator = new TransactionDiscountCalculator();
        $first = $calculator->calculate(100, null, 7, 'director');
        $second = $calculator->calculate(100, null, 7, 'director');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $first['transaction_id']
        );
        $this->assertNotSame($first['transaction_id'], $second['transaction_id']);
    }

    public function test_director_and_super_admin_are_the_only_real_discount_roles(): void
    {
        $student = $this->student();
        [$director, $directorToken] = $this->staffToken('A', true);
        [$superAdmin, $superToken] = $this->staffToken('S', false);

        foreach ([[$director, $directorToken, '2030-10-01'], [$superAdmin, $superToken, '2030-11-01']] as [$actor, $token, $start]) {
            $course = $this->course($student->id, $actor->id);
            $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
                'sessions' => 2,
                'start_date' => $start,
                'mode' => 'new_purchase',
                'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'authorized'],
            ]);

            $response->assertCreated();
            $new = StudentClass::findOrFail($response->json('new_course.id'));
            $this->assertSame($actor->id, (int) $new->pricing_snapshot['actor_id']);
            $this->assertSame($actor->type === 'S' ? 'super_admin' : 'director', $new->pricing_snapshot['actor_role']);
        }
    }

    public function test_teacher_and_pending_are_rejected_by_real_discount_middleware_without_snapshot(): void
    {
        $student = $this->student();

        foreach ([['T', true, '2032-01-01'], ['U', true, '2032-02-01']] as [$type, $campusScoped, $start]) {
            [$actor, $token] = $this->staffToken($type, $campusScoped);
            $course = $this->course($student->id, $actor->id);
            $before = StudentClass::where('StudentID', $student->id)->count();
            $beforeSnapshots = StudentClass::where('StudentID', $student->id)->whereNotNull('pricing_snapshot')->count();

            $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
                'sessions' => 2,
                'start_date' => $start,
                'mode' => 'new_purchase',
                'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'unauthorized'],
            ])->assertForbidden();

            $this->assertSame($before, StudentClass::where('StudentID', $student->id)->count());
            $this->assertSame($beforeSnapshots, StudentClass::where('StudentID', $student->id)->whereNotNull('pricing_snapshot')->count());
        }
    }

    public function test_teacher_discount_is_rejected_on_every_protected_renewal_endpoint(): void
    {
        $student = $this->student();
        [$teacher, $token] = $this->staffToken('T', true);
        $course = $this->course($student->id, $teacher->id);
        $before = StudentClass::where('StudentID', $student->id)->count();
        $discount = ['type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'teacher-forbidden'];

        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 2, 'start_date' => '2032-04-01', 'mode' => 'new_purchase', 'discount' => $discount,
        ])->assertForbidden();
        $course->ScheduleMode = 'date';
        $course->SessionCount = 0;
        $course->RemainingSessions = 0;
        $course->monthly_sessions = 2;
        $course->EndDate = '2026-09-30';
        $course->save();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", [
            'mode' => 'purchase_batch', 'sessions' => 2, 'start_date' => '2032-04-01', 'discount' => $discount,
        ])->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => 'teacher-forged-preview', 'state_hash' => 'teacher-forged-hash',
            'mode' => 'purchase_batch', 'payload' => [
                'sessions' => 2, 'start_date' => '2032-04-01', 'discount' => $discount,
            ],
        ])->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2032-04-30', 'discount' => $discount,
        ])->assertForbidden();

        $this->assertSame($before, StudentClass::where('StudentID', $student->id)->count());
        $this->assertSame(0, StudentClass::where('StudentID', $student->id)->whereNotNull('pricing_snapshot')->count());
    }

    public function test_direct_purchase_none_and_no_discount_do_not_inherit_legacy_or_prior_snapshot(): void
    {
        $student = $this->student();
        [$director, $token] = $this->staffToken('A', true);
        $course = $this->course($student->id, $director->id);
        $prior = (new TransactionDiscountCalculator())->calculate(4000, [
            'type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'old approval',
        ], $director->id, 'director');
        $course->initializePricingSnapshot($prior);
        $course->Disconunt = 999;
        $course->save();

        $noDiscount = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/purchase-batch", [
            'sessions' => 2, 'start_date' => '2032-05-01', 'mode' => 'new_purchase',
        ])->assertCreated();
        $new = StudentClass::findOrFail($noDiscount->json('new_course.id'));
        $this->assertSame(1000, (int) $new->Charge);
        $this->assertSame('NONE', $new->pricing_snapshot['type']);
        $this->assertSame(0, (int) $new->pricing_snapshot['discount_amount']);

        $noneSource = $this->course($student->id, $director->id);
        $noneSource->Disconunt = 888;
        $noneSource->save();
        $explicitNone = $this->withToken($token)->postJson("/api/v1/student-classes/{$noneSource->ID}/purchase-batch", [
            'sessions' => 2, 'start_date' => '2032-06-01', 'mode' => 'new_purchase',
            'discount' => ['type' => 'NONE', 'value' => '999'],
        ])->assertCreated();
        $noneNew = StudentClass::findOrFail($explicitNone->json('new_course.id'));
        $this->assertSame('NONE', $noneNew->pricing_snapshot['type']);
        $this->assertSame(1000, (int) $noneNew->Charge);
    }

    public function test_multi_subject_batch_shares_transaction_identity_and_allocates_percentage_once(): void
    {
        [$director, $directorToken] = $this->staffToken('A', true);
        [$teacher] = $this->staffToken('T', true);
        $this->grantTeacherSubjects($teacher->id, ['Math', 'English']);
        $student = $this->student();
        $response = $this->withToken($directorToken)->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1, 'student_id' => $student->id, 'teacher_id' => $teacher->id,
            'subject' => 'Math', 'class_type' => 'one_on_one', 'total_classes' => 2,
            'confirmed_dates' => [], 'future_dates' => ['2031-10-06'],
            'session_plan' => [
                ['session_date' => '2031-10-06', 'start_time' => '11:00', 'kind' => 'future', 'subject' => 'Math'],
                ['session_date' => '2031-10-06', 'start_time' => '13:00', 'kind' => 'future', 'subject' => 'English'],
            ],
            'days_of_week' => [1],
            'day_time_slots' => [
                ['day' => 1, 'start_time' => '11:00', 'duration_minutes' => 120, 'subject' => 'Math'],
                ['day' => 1, 'start_time' => '13:00', 'duration_minutes' => 120, 'subject' => 'English'],
            ],
            'start_time' => '11:00', 'duration_minutes' => 120,
            'price_per_session' => 101, 'payment_type' => 'session', 'course_start_date' => '2031-10-06',
            'discount' => ['type' => 'PERCENTAGE', 'value' => '12.5', 'reason' => 'multi-subject approval', 'transaction_id' => 'forged-client-id'],
        ]);
        $response->assertCreated();

        $courses = StudentClass::where('StudentID', $student->id)->orderBy('ID')->get();
        $snapshots = $courses->map(fn (StudentClass $course) => $course->pricing_snapshot);
        $this->assertCount(2, $courses);
        $this->assertCount(1, $snapshots->pluck('transaction_id')->unique());
        $this->assertSame([202], $snapshots->pluck('original_amount')->unique()->values()->all());
        foreach (['transaction_id', 'original_amount', 'discount_amount', 'final_amount', 'type', 'value', 'reason', 'actor_id', 'actor_role'] as $field) {
            $this->assertCount(1, $snapshots->pluck($field)->unique(), "sibling snapshots differ in {$field}");
        }
        $this->assertNotSame('forged-client-id', $snapshots->first()['transaction_id']);
        $this->assertSame('PERCENTAGE', $snapshots->first()['type']);
        $this->assertSame('12.5', $snapshots->first()['value']);
        $this->assertSame('multi-subject approval', $snapshots->first()['reason']);
        $this->assertSame((int) $director->id, (int) $snapshots->first()['actor_id']);
        $this->assertSame('director', $snapshots->first()['actor_role']);
        $this->assertSame(177, (int) $courses->sum('Charge'));
        $this->assertSame([89, 88], $courses->pluck('Charge')->sortDesc()->values()->all());
        $this->assertSame(177, (int) $snapshots->first()['final_amount']);
    }

    public function test_renewal_preview_hash_is_stable_and_discount_change_invalidates_confirm(): void
    {
        $student = $this->student();
        [$director, $token] = $this->staffToken('A', true);
        $course = $this->course($student->id, $director->id);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $payload = [
            'mode' => 'purchase_batch', 'sessions' => 2, 'start_date' => '2030-12-01',
            'discount' => ['type' => 'PERCENTAGE', 'value' => '12.5', 'reason' => 'approved'],
        ];

        $first = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", $payload)
            ->assertOk()->json();
        $normalizedPayload = $payload;
        $normalizedPayload['discount']['value'] = '12.50';
        $second = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-preview", $normalizedPayload)
            ->assertOk()->json();
        $this->assertSame($first['state_hash'], $second['state_hash']);
        $this->assertSame($first['preview_id'], $second['preview_id']);
        $this->assertNotSame(
            $first['billing']['discount']['created_at'],
            $second['billing']['discount']['created_at']
        );
        $this->assertNotSame(
            $first['billing']['discount']['transaction_id'],
            $second['billing']['discount']['transaction_id']
        );

        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $first['preview_id'], 'state_hash' => $first['state_hash'],
            'mode' => 'purchase_batch', 'payload' => $payload,
        ])->assertCreated();

        $changed = $payload;
        $changed['discount']['value'] = '25';
        $changed['discount']['reason'] = 'changed approval';
        $changedResponse = $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $first['preview_id'],
            'state_hash' => $first['state_hash'],
            'mode' => 'purchase_batch',
            'payload' => $changed,
        ])->assertStatus(409);
        $this->assertSame(409, $changedResponse->status());

        $reasonOnly = $payload;
        $reasonOnly['discount']['reason'] = 'reason-only mutation';
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $first['preview_id'], 'state_hash' => $first['state_hash'],
            'mode' => 'purchase_batch', 'payload' => $reasonOnly,
        ])->assertStatus(409);

        $periodChanged = $payload;
        $periodChanged['start_date'] = '2031-01-01';
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $first['preview_id'], 'state_hash' => $first['state_hash'],
            'mode' => 'purchase_batch', 'payload' => $periodChanged,
        ])->assertStatus(409);

        $course->Charge = (int) $course->Charge + 1;
        $course->save();
        $this->withHeaders($headers)->postJson("/api/v1/student-classes/{$course->ID}/renewal-confirm", [
            'preview_id' => $first['preview_id'], 'state_hash' => $first['state_hash'],
            'mode' => 'purchase_batch', 'payload' => $payload,
        ])->assertStatus(409);
    }

    public function test_discounted_renewal_preserves_negative_void_payment_reconciliation_and_old_invoice(): void
    {
        $student = $this->student();
        [$director, $token] = $this->staffToken('A', true);
        $course = $this->course($student->id, $director->id);
        $course->initializePricingSnapshot((new TransactionDiscountCalculator())->calculate(4000, [
            'type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'legacy monthly approval',
        ], $director->id, 'director'));
        $course->Disconunt = 999;
        $course->ScheduleMode = 'date';
        $course->SessionCount = 0;
        $course->RemainingSessions = 0;
        $course->monthly_sessions = 8;
        $course->settlement_day = 15;
        $course->StartDate = '2026-09-01';
        $course->EndDate = '2026-09-30';
        $course->Paid = 1;
        $course->save();

        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 4000, 'PaidAmount' => 3000,
            'Status' => 'partial', 'billing_period' => '2026-09',
        ]);
        $positive = Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => 4000,
            'PaidAt' => '2026-09-01', 'Method' => 'transfer',
        ]);
        $void = Payment::create([
            'InvoiceID' => $invoice->id, 'Amount' => -1000,
            'PaidAt' => '2026-09-02', 'Method' => 'void',
        ]);
        $legacyItem = InvoiceItem::create([
            'InvoiceID' => $invoice->id, 'Description' => 'legacy period', 'Amount' => 4000,
            'PeriodStart' => '2026-09-01', 'PeriodEnd' => '2026-09-30',
        ]);
        $beforeInvoiceCount = Invoice::count();
        $beforeItemCount = InvoiceItem::count();
        $beforeInvoice = $invoice->fresh()->getRawOriginal();
        $beforeLegacyItem = $legacyItem->fresh()->getRawOriginal();
        $beforePositive = $positive->fresh()->getRawOriginal();
        $beforeVoid = $void->fresh()->getRawOriginal();
        $beforeReconciliation = app(InvoiceAmountReconciliationService::class)
            ->resolve($invoice->fresh(), $course->fresh());

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", [
            'end_date' => '2026-10-31',
            'discount' => ['type' => 'FIXED_AMOUNT', 'value' => '800', 'reason' => 'next period approval'],
        ]);
        $response->assertCreated();

        $this->assertSame($beforeInvoice, $invoice->fresh()->getRawOriginal());
        $this->assertSame($beforePositive, $positive->fresh()->getRawOriginal());
        $this->assertSame($beforeVoid, $void->fresh()->getRawOriginal());
        $this->assertSame($beforeReconciliation, app(InvoiceAmountReconciliationService::class)
            ->resolve($invoice->fresh(), $course->fresh()));
        $newCourse = StudentClass::findOrFail($response->json('new_course.id'));
        $this->assertSame($beforeInvoiceCount + 1, Invoice::count());
        $this->assertSame($beforeItemCount + 1, InvoiceItem::count());
        $this->assertSame($beforeLegacyItem, $legacyItem->fresh()->getRawOriginal());
        $newInvoices = Invoice::where('StudentClassID', $newCourse->ID)->get();
        $this->assertCount(1, $newInvoices);
        $newItems = InvoiceItem::where('InvoiceID', $newInvoices->first()->id)->get();
        $this->assertCount(1, $newItems);
        $this->assertSame((int) $newCourse->Charge, (int) $newInvoices->first()->TotalAmount);
        $this->assertSame((int) $newCourse->Charge, (int) $newItems->sum('Amount'));
        $this->assertSame('FIXED_AMOUNT', $newCourse->pricing_snapshot['type']);
        $this->assertSame(800, (int) $newCourse->pricing_snapshot['discount_amount']);
        $this->assertSame(3200, (int) $newCourse->Charge);
    }

    public function test_snapshot_is_hidden_and_legacy_initialization_is_rejected(): void
    {
        $course = new StudentClass();
        $this->assertContains('pricing_snapshot', $course->getHidden());
        $legacy = new StudentClass();
        $legacy->exists = true;
        $legacy->setRawAttributes(['ID' => 1, 'pricing_snapshot' => null]);
        $this->expectException(\LogicException::class);
        $legacy->initializePricingSnapshot(['type' => 'NONE']);
    }

    public function test_persisted_pricing_snapshot_cannot_be_mutated(): void
    {
        $student = $this->student();
        [$director] = $this->staffToken('A', true);
        $course = $this->course($student->id, $director->id);
        $course->initializePricingSnapshot([
            'transaction_id' => 'immutable-test', 'original_amount' => 4000,
            'type' => 'NONE', 'value' => '0', 'discount_amount' => 0,
            'final_amount' => 4000, 'reason' => '', 'actor_id' => $director->id,
            'actor_role' => 'director', 'created_at' => now()->toISOString(),
        ]);

        $snapshot = $course->pricing_snapshot;
        $snapshot['reason'] = 'forged mutation';
        $course->pricing_snapshot = $snapshot;
        $this->expectException(\LogicException::class);
        $course->save();
    }

    public function test_convert_trial_does_not_inherit_legacy_discount_or_prior_snapshot(): void
    {
        $student = $this->student();
        [$director, $token] = $this->staffToken('A', true);
        $trial = $this->course($student->id, $director->id);
        $trial->ClassType = 'trial';
        $trial->Disconunt = 777;
        $trial->save();
        $prior = (new TransactionDiscountCalculator())->calculate(4000, [
            'type' => 'FIXED_AMOUNT', 'value' => '100', 'reason' => 'trial legacy approval',
        ], $director->id, 'director');
        $trial->initializePricingSnapshot($prior);

        $response = $this->withToken($token)->postJson("/api/v1/student-classes/{$trial->ID}/convert-trial", [
            'sessions' => 2, 'start_date' => '2032-07-01', 'class_type' => 'one_on_one',
        ])->assertCreated();
        $new = StudentClass::findOrFail($response->json('new_course.id'));

        $this->assertSame('NONE', $new->pricing_snapshot['type']);
        $this->assertSame(0, (int) $new->pricing_snapshot['discount_amount']);
        $this->assertSame(1000, (int) $new->Charge);
    }

    /** @return array{0: User, 1: string} */
    private function staffToken(string $type, bool $campusScoped): array
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . bin2hex(random_bytes(4)) . '@example.com',
            'Name' => '測試授權人員', 'PSW' => 'secret', 'type' => $type, 'phone' => '0912345678',
        ]);
        if ($campusScoped) {
            UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
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
