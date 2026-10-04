<?php

namespace Tests\Feature;

use App\Models\{AuthToken, ClassSession, Invoice, ParentSession, Payment, PaymentReport, Student, StudentClass, StudentClassPricingAmendment, User, UserCampus};
use App\Services\{BillingPayableResolver, DunningService, NotificationSyncService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** in-app #349 / #361: a transaction discount (allocated into Charge; Rate keeps the list price) drives billing. #346: NT$0 may only settle a free course. */
class BillingDiscountAndZeroAmountTest extends TestCase
{
    use RefreshDatabase;

    private const FREE_DATE_MODE = ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0, 'SessionCount' => 0, 'settlement_day' => 1];
    private const FREE_COUNT_MODE = ['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0, 'RemainingSessions' => 4];
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = $this->newStudent();
        $user = User::create(['LoginName' => 'disc_' . uniqid() . '@test.com', 'Name' => 'Dir', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => $token = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']); // every request is a campus director
    }

    public function test_discount_drives_contract_total_queue_index_and_free_detection_and_director_record_codes(): void
    {
        $plain = $this->course(['Rate' => 1800, 'SessionCount' => 4, 'Charge' => 7200]);
        $discounted = $this->course(['Rate' => 1800, 'SessionCount' => 4, 'Charge' => 5400], 1800);
        $freeTrial = $this->freeTrial();
        $paid = $this->paidCourse();
        foreach ([[$plain, 7200, false], [$discounted, 5400, false], [$freeTrial, 0, true]] as [$course, $total, $free]) {
            $this->assertSame($total, $course->effectiveContractTotal());
            $this->assertSame($free, $course->isFreeOfCharge(), 'a trial discounted to NT$0 is free even though Rate > 0');
        }
        $queue = $this->tuitionQueue()->whereNotNull('id')->keyBy(fn ($r) => (int) $r['id']);
        $this->assertTrue($queue->has($discounted->ID), 'discounted unpaid course is listed');
        $this->assertSame(5400, (int) $queue[$discounted->ID]['charge'], 'charge is the discounted total, not Rate × sessions (7200)');
        $this->assertFalse($queue->has($freeTrial->ID), 'a trial discounted to NT$0 does not ask for payment');
        $rows = $this->courseIndex();
        $this->assertSame('free', $rows[$freeTrial->ID]['payment_status']);
        $this->assertSame(0, (int) $rows[$freeTrial->ID]['charge'], 'not replaced by Rate × sessions (1500)');
        $this->assertFalse((bool) $rows[$freeTrial->ID]['charge_is_fallback']);
        $this->assertSame('unpaid', $rows[$paid->ID]['payment_status']);
        $unset = $this->course(['ScheduleMode' => 'date', 'Rate' => 0, 'Charge' => 0, 'SessionCount' => 0]);
        $monthlyDiscounted = $this->course(['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0], 1500);
        $tutoring = $this->course(['ScheduleMode' => 'date', 'Rate' => 0, 'Charge' => 0, 'ClassType' => 'tutoring']);
        $this->assertFalse($unset->isFreeOfCharge());
        $this->assertTrue($monthlyDiscounted->isFreeOfCharge());
        $this->assertTrue($tutoring->isFreeOfCharge());
        $this->directorRecord($paid, 0)->assertStatus(422)->assertJsonPath('code', 'zero_amount_for_paid_course');
        $this->directorRecord($freeTrial, 0)->assertStatus(422)->assertJsonPath('code', 'no_payment_obligation')
            ->assertJsonPath('message', '此課程免收費（折扣後 0 元或未設定收費），不需要登記繳費。');
        $this->directorRecord($freeTrial, 500)->assertStatus(422)->assertJsonPath('code', 'no_payment_obligation');
        $this->directorRecord($unset, 0)->assertStatus(422)->assertJsonPath('code', 'monthly_fee_unset');
        $this->directorRecord($monthlyDiscounted, 0)->assertStatus(422)->assertJsonPath('code', 'no_payment_obligation');
        $this->directorRecord($paid, 0.5)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->directorRecord($paid, '0.50')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(0, PaymentReport::count(), 'nothing recorded');
        $this->directorRecord($paid, 1)->assertSuccessful();
        $this->assertSame(1, PaymentReport::where('StudentClassID', $paid->ID)->count());
    }

    public function test_reminder_producers_skip_free_courses(): void
    {
        $freeTrial = $this->freeTrial([], $this->newStudent());
        $unpaid = $this->paidCourse($this->newStudent());
        $stale = ['MDate' => now()->subDays(15)] + (Schema::hasColumn('StudentClass', 'created_at') ? ['created_at' => now()->subDays(15)] : []);
        StudentClass::whereIn('ID', [$freeTrial->ID, $unpaid->ID])->update($stale);
        $legacyPaidFree = $this->freeTrial(['Paid' => 1, 'RemainingSessions' => 1], $this->newStudent());
        $events = collect(app(DunningService::class)->evaluateAll(1, false));
        $this->assertContains((int) $unpaid->ID, $events->where('rule_key', 'unpaid_reminder')->pluck('student_class_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(1, (int) $freeTrial->RemainingSessions);
        $this->assertSame([], $events->whereIn('student_class_id', [$freeTrial->ID, $legacyPaidFree->ID])->all(), 'dunning: no event of ANY rule for a free course, even low_sessions');
        NotificationSyncService::sync([1]);
        $this->assertDatabaseHas('Notifications', ['SourceKey' => "tuition:1:{$unpaid->ID}"]);
        $this->assertDatabaseMissing('Notifications', ['SourceKey' => "tuition:1:{$freeTrial->ID}"]);
        $this->assertDatabaseMissing('Notifications', ['SourceKey' => "low_sessions:1:{$legacyPaidFree->ID}"]);
        $this->artisan('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7])->expectsOutput('Found 1 overdue unpaid course(s).')->assertSuccessful();
    }

    public function test_parent_payment_message_never_demands_money_for_a_free_course(): void
    {
        $this->freeTrial();
        $this->assertSame('此學生目前無待繳費課程', $this->paymentMessage()['message'], 'no NT$1,500 demand for a 100%-discounted trial');
        $this->paidCourse();
        $this->assertSame(8800, $this->paymentMessage()['total_amount']);
        $this->assertCount(1, $this->paymentMessage()['items']);
    }

    /** Only the amendment in force decides free (a future / superseded one does not); a void invoice does not bill, a live one does. */
    public static function freePredicateCases(): array
    {
        return [
            'no amendment' => [[], null, true],
            'amendment in force' => [[['2026-08-15', 1200]], null, false],
            'future amendment' => [[[date('Y-m-d', strtotime('+1 month')), 1200]], null, true],
            'superseded by a later zero' => [[['2026-06-01', 1200], ['2026-08-01', 0]], null, true],
            'void invoice does not bill' => [[], 'void', true],
            'a non-void NT$3000 invoice bills' => [[], 'unpaid', false],
        ];
    }

    /** @dataProvider freePredicateCases */
    public function test_free_predicate_is_shared_with_the_resolver_and_honours_amendments_and_invoices(array $amendments, ?string $invoice, bool $expectFree): void
    {
        $course = $this->course(['Rate' => 0, 'Charge' => 0, 'SessionCount' => 4]);
        foreach ($amendments as [$from, $rate]) {
            $this->amend($course, $rate, $from);
        }
        if ($invoice !== null) {
            $this->invoice($course, 3000, $invoice);
        }
        $this->assertSame($expectFree, $course->isFreeOfCharge());
        $this->assertSame($expectFree, app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$course->ID])[$course->ID]['status'] === 'free');
    }

    public function test_free_date_mode_course_is_skipped_by_dunning_and_tuition_queue_open_and_awaiting_settlement(): void
    {
        $free = $this->course(self::FREE_DATE_MODE, 1500);
        $amendedFree = $this->course(self::FREE_DATE_MODE, 1500);
        $billed = $this->course(self::FREE_DATE_MODE);
        // attended lessons make MonthlyBillingService price a positive charge
        array_map(fn ($c) => ClassSession::create(['StudentClassID' => $c->ID, 'SessionDate' => now()->toDateString(), 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'completed']), [$free, $amendedFree, $billed]);
        $events = collect(app(DunningService::class)->evaluateAll(1, false))
            ->filter(fn ($e) => str_starts_with((string) $e['rule_key'], 'monthly_'))->pluck('student_class_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $billed->ID, $events, 'control: a billed date-mode course is reminded');
        $this->assertNotContains((int) $free->ID, $events);
        $this->assertContains((int) $billed->ID, $this->queuedIds(), 'control: a billed date-mode course is queued');
        $this->assertNotContains((int) $free->ID, $this->queuedIds());
        // closed after the lesson: 結案未繳 / 提前結束未繳
        StudentClass::whereIn('ID', [$free->ID, $billed->ID])->update(['Stop' => 1, 'closed_reason' => 'settled_pending']);
        StudentClass::whereKey($amendedFree->ID)->update(['Stop' => 1, 'closed_reason' => 'contract_amended']);
        $this->assertContains((int) $billed->ID, $this->queuedIds(), 'control: a billed closed date-mode course awaits settlement');
        $this->assertNotContains((int) $free->ID, $this->queuedIds());
        $this->assertNotContains((int) $amendedFree->ID, $this->queuedIds());
    }

    public static function refusedReports(): array
    {
        return [
            'below one dollar' => [false, 0.5, 'invalid_report_amount'],
            'free course' => [true, 500, 'no_payment_obligation'],
            'fractional amount' => [false, 1.5, 'invalid_report_amount'],
        ];
    }

    /** @dataProvider refusedReports */
    public function test_confirm_refuses_a_pending_report_for_a_free_course_or_a_non_whole_amount(bool $free, float|int $amount, string $code): void
    {
        $course = $free ? $this->course(self::FREE_COUNT_MODE, 1500) : $this->paidCourse();
        $report = PaymentReport::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'reported_by_name' => 'x',
            'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'reported_amount' => $amount, 'status' => 'pending',
            'report_token_hash' => str_repeat('a', 64), 'token_expires_at' => now()->addDay()]);
        $this->putJson("/api/v1/payment-reports/{$report->id}/confirm")->assertStatus(422)->assertJsonPath('code', $code);
        $this->assertSame($code, $this->postJson('/api/v1/payment-reports/confirm-batch', ['ids' => [$report->id]])->json('results.0.code'));
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, (int) $course->fresh()->Paid);
        $this->assertSame('pending', $report->fresh()->status);
    }

    public function test_amended_discounted_course_is_priced_at_the_amended_rate_on_every_surface(): void
    {
        $course = $this->course(['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0], 1500);
        $this->assertNull($this->tuitionQueue()->firstWhere('id', $course->ID), 'free before the amendment');
        $this->amend($course, 1200);
        $this->assertSame(4800, (int) $this->tuitionQueue()->firstWhere('id', $course->ID)['charge'], 'amended rate 1200 x 4 sessions, not the frozen discounted 0');
        $this->assertSame(4800, $this->courseIndex()[$course->ID]['effective_total']);
        $body = $this->paymentMessage();
        $this->assertSame(4800, $body['total_amount'], 'amended 1200 x 4, not frozen Rate 1500 x 4');
        $this->assertStringContainsString('4,800', $body['message']);
    }

    public function test_free_courses_are_free_in_the_parent_portal_and_course_index_even_with_a_legacy_paid_flag(): void
    {
        $billed = $this->course(['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800, 'RemainingSessions' => 8]);
        $freeMonthly = $this->course(self::FREE_DATE_MODE + ['RemainingSessions' => 0, 'monthly_sessions' => 4], 1500);
        foreach ([0, 1] as $legacyPaid) {
            $free = $this->course(self::FREE_COUNT_MODE + ['Paid' => $legacyPaid], 1500);
            $classes = $this->parentClasses();
            $this->assertSame('free', $classes[$free->ID]['payment_status']);
            $this->assertSame('免費（不適用）', $classes[$free->ID]['payment_status_label']);
            $this->assertSame('unpaid', $classes[$billed->ID]['payment_status']);
            $this->assertSame('free', $classes[$freeMonthly->ID]['payment_status']);
            $this->assertContains($classes[$freeMonthly->ID]['monthly_fee_estimate'], [0, null]);
            $this->assertSame('free', $this->courseIndex()[$free->ID]['payment_status']);
            $this->assertSame($legacyPaid, (int) $free->fresh()->Paid, 'ledger untouched');
        }
    }

    /** StudentClass::effectiveContractTotal precedence; columns: course fields, discount, amendment rate, invoice total => expected (null = free). */
    public static function precedenceMatrix(): array
    {
        return [
            'invoice wins over 100% discount' => [['Rate' => 1500, 'Charge' => 0], 6000, null, 3000, 3000], // positive non-void invoice (Codex P1)
            'zero amendment over positive discount is free' => [['Rate' => 1500, 'Charge' => 4000], 2000, 0, null, null], // amendment in force, 0 included (Codex P1)
            'positive amendment over no snapshot is priced' => [['Rate' => 0, 'Charge' => 0], 0, 1200, null, 4800], // legacy Charge 0 / Rate 0 (Codex P1)
            'discount only' => [['Rate' => 1500, 'Charge' => 5400], 600, null, null, 5400], // the allocated Charge, not Rate x sessions (6000)
            'plain' => [['Rate' => 1500, 'Charge' => 6000], 0, null, null, 6000], // list price
        ];
    }

    /** @dataProvider precedenceMatrix */
    public function test_one_pricing_authority_gives_the_same_number_on_every_surface(array $fields, int $discount, ?int $amendRate, ?int $invoice, ?int $expected): void
    {
        $course = $this->course($fields + ['SessionCount' => 4, 'RemainingSessions' => 4], $discount);
        if ($amendRate !== null) {
            $this->amend($course, $amendRate);
        }
        if ($invoice !== null) {
            $this->invoice($course, $invoice, 'unpaid');
        }
        $this->assertSame($expected ?? 0, $course->fresh()->effectiveContractTotal());
        $this->assertSame($expected === null, $course->fresh()->isFreeOfCharge());
        $queue = $this->tuitionQueue()->firstWhere('id', $course->ID);
        $this->assertSame($expected, $queue === null ? null : (int) $queue['charge'], 'tuition queue');
        $index = $this->courseIndex()[$course->ID];
        $this->assertSame($expected ?? 0, $index['effective_total'], 'course index effective_total');
        $this->assertSame($expected === null, $index['payment_status'] === 'free', 'course index free status');
        $this->assertSame($expected, $this->paymentMessage()['total_amount'] ?? null, 'parent payment message');
    }

    private function course(array $overrides, int $discount = 0, ?Student $student = null): StudentClass
    {
        $course = StudentClass::create(array_merge([
            'StudentID' => ($student ?? $this->student)->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 20, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionDuration' => 120, 'RemainingSessions' => 1, 'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'rate_unit' => 'session',
        ], $overrides));
        if ($discount > 0) {
            $original = (int) $overrides['Charge'] + $discount;
            $course->initializePricingSnapshot(['transaction_id' => 'test', 'original_amount' => $original, 'type' => 'FIXED_AMOUNT',
                'value' => (string) $discount, 'discount_amount' => $discount, 'final_amount' => $original - $discount,
                'reason' => '舊生介紹', 'actor_id' => 1, 'actor_role' => 'director']);
        }
        return $course->fresh();
    }

    private function freeTrial(array $extra = [], ?Student $student = null): StudentClass
    {
        return $this->course($extra + ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500, $student);
    }

    private function paidCourse(?Student $student = null): StudentClass
    {
        return $this->course(['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800], 0, $student);
    }

    private function amend(StudentClass $course, int $rate, string $from = '2026-08-15'): void
    {
        StudentClassPricingAmendment::create(['student_class_id' => $course->ID, 'effective_from' => $from,
            'rate' => $rate, 'rate_unit' => 'session', 'source_reference' => 'fx', 'reason' => 'test', 'created_at' => now()]);
    }

    private function invoice(StudentClass $course, int $total, string $status): Invoice
    {
        return Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => now(),
            'DueDate' => now(), 'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => $status]);
    }

    private function directorRecord(StudentClass $course, float|int|string $amount)
    {
        return $this->postJson('/api/v1/payment-reports/director-record', ['student_class_id' => $course->ID, 'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'amount' => $amount]);
    }

    private function tuitionQueue()
    {
        return collect($this->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
    }

    private function queuedIds(): array
    {
        return $this->tuitionQueue()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function courseIndex()
    {
        return collect($this->getJson("/api/v1/student-classes?student_id={$this->student->id}&per_page=100")->assertOk()->json('data'))
            ->keyBy(fn ($r) => (int) ($r['ID'] ?? $r['id']));
    }

    private function paymentMessage(): array
    {
        return $this->getJson("/api/v1/parent/payment-message/{$this->student->id}")->assertOk()->json();
    }

    private function parentClasses()
    {
        $raw = bin2hex(random_bytes(16));
        ParentSession::create(['StudentID' => $this->student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);
        return collect($this->getJson('/api/v1/parent/dashboard', ['Authorization' => "Bearer {$raw}"])->assertOk()->json('classes'))->keyBy('id');
    }

    private function newStudent(): Student
    {
        return Student::create(['name' => '折扣學生' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
    }
}

