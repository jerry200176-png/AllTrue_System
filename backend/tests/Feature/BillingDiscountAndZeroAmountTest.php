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

        $this->assertSame(7200, $plain->effectiveContractTotal());
        $this->assertSame(5400, $discounted->effectiveContractTotal());
        $this->assertSame(0, $freeTrial->effectiveContractTotal());
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

        $legacyPaidFree = $this->course($this->student()->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial', 'Paid' => 1, 'RemainingSessions' => 1], 1500);
        $events = collect(app(DunningService::class)->evaluateAll(1, false));
        $dunned = $events->where('rule_key', 'unpaid_reminder')->pluck('student_class_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $unpaid->ID, $dunned);
        $this->assertSame(1, (int) $freeTrial->RemainingSessions);
        $this->assertSame([], $events->whereIn('student_class_id', [$freeTrial->ID, $legacyPaidFree->ID])->all(), 'dunning: no event of ANY rule for a free course, even low_sessions');

        NotificationSyncService::sync([1]);
        $this->assertDatabaseHas('Notifications', ['SourceKey' => "tuition:1:{$unpaid->ID}"]);
        $this->assertDatabaseMissing('Notifications', ['SourceKey' => "tuition:1:{$freeTrial->ID}"]);
        $this->assertDatabaseMissing('Notifications', ['SourceKey' => "low_sessions:1:{$legacyPaidFree->ID}"]);

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

    public function test_parent_payment_message_never_demands_money_for_a_free_course(): void
    {
        $token = $this->director();
        $student = $this->student();
        $freeTrial = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 1, 'Charge' => 0, 'ClassType' => 'trial'], 1500);
        $get = fn () => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/parent/payment-message/{$student->id}")->assertOk()->json();

        $this->assertSame('此學生目前無待繳費課程', $get()['message'], 'no NT$1,500 demand for a 100%-discounted trial');
        $unpaid = $this->course($student->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);
        $body = $get();
        $this->assertSame(8800, $body['total_amount']);
        $this->assertCount(1, $body['items']);
    }

    public function test_monthly_course_without_fee_is_fee_unset_not_free_but_discount_and_tutoring_are(): void
    {
        $token = $this->director();
        $student = $this->student();
        $monthly = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 0, 'Charge' => 0, 'SessionCount' => 0]);
        $monthlyDiscounted = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0], 1500);
        $tutoring = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 0, 'Charge' => 0, 'ClassType' => 'tutoring']);

        $this->assertFalse($monthly->isFreeOfCharge());
        $this->assertTrue($monthlyDiscounted->isFreeOfCharge());
        $this->assertTrue($tutoring->isFreeOfCharge());
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/payment-reports/director-record', [
                'student_class_id' => $monthly->ID, 'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'amount' => 0,
            ])->assertStatus(422)->assertJsonPath('code', 'monthly_fee_unset');
    }

    public function test_resolver_shares_the_free_predicate_including_amendments(): void
    {
        $student = $this->student();
        $plain = $this->course($student->id, ['Rate' => 0, 'Charge' => 0, 'SessionCount' => 4]);
        $amended = $this->course($student->id, ['Rate' => 0, 'Charge' => 0, 'SessionCount' => 4]);
        \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $amended->ID, 'effective_from' => '2026-08-15',
            'rate' => 1200, 'rate_unit' => 'session', 'source_reference' => 'fx', 'reason' => 'test', 'created_at' => now()]);

        $this->assertFalse($amended->isFreeOfCharge());
        $out = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$plain->ID, $amended->ID]);
        $this->assertSame('free', $out[$plain->ID]['status']);
        $this->assertNotSame('free', $out[$amended->ID]['status']);
    }

    public function test_director_record_amount_must_be_a_whole_dollar_amount(): void
    {
        $token = $this->director();
        $paid = $this->course($this->student()->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);
        $record = fn ($amount) => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/payment-reports/director-record', [
                'student_class_id' => $paid->ID, 'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'amount' => $amount,
            ]);

        $record(0.5)->assertStatus(422)->assertJsonValidationErrors('amount');
        $record('0.50')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(0, PaymentReport::where('StudentClassID', $paid->ID)->count());
        $record(1)->assertSuccessful();
        $this->assertSame(1, PaymentReport::where('StudentClassID', $paid->ID)->count());
    }

    public function test_date_mode_free_course_is_skipped_by_dunning_and_tuition_queue(): void
    {
        $token = $this->director();
        $student = $this->student();
        $free = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0, 'SessionCount' => 0, 'settlement_day' => 1], 1500);
        $billed = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0, 'SessionCount' => 0, 'settlement_day' => 1]);

        foreach ([$free, $billed] as $c) { // attended lessons make MonthlyBillingService price a positive charge
            \App\Models\ClassSession::create(['StudentClassID' => $c->ID, 'SessionDate' => now()->toDateString(),
                'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'completed']);
        }

        $events = collect(app(DunningService::class)->evaluateAll(1, false))
            ->filter(fn ($e) => str_starts_with((string) $e['rule_key'], 'monthly_'))->pluck('student_class_id')->map(fn ($id) => (int) $id);
        $this->assertContains((int) $billed->ID, $events->all(), 'control: a billed date-mode course is reminded');
        $this->assertNotContains((int) $free->ID, $events->all());

        $ids = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertContains((int) $billed->ID, $ids->all(), 'control: a billed date-mode course is queued');
        $this->assertNotContains((int) $free->ID, $ids->all());
    }

    public function test_confirm_refuses_a_pending_report_below_one_dollar(): void
    {
        $token = $this->director();
        $course = $this->course($this->student()->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);
        $report = PaymentReport::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'reported_by_name' => 'x',
            'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'reported_amount' => 0.5, 'status' => 'pending', 'report_token_hash' => str_repeat('a', 64), 'token_expires_at' => now()->addDay()]);
        $h = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/confirm")
            ->assertStatus(422)->assertJsonPath('code', 'invalid_report_amount');
        $batch = $this->withHeaders($h)->postJson('/api/v1/payment-reports/confirm-batch', ['ids' => [$report->id]]);
        $this->assertSame('invalid_report_amount', $batch->json('results.0.code'));
        $this->assertSame(0, \App\Models\Payment::count());
        $this->assertSame(0, (int) $course->fresh()->Paid);
        $this->assertSame('pending', $report->fresh()->status);
    }

    public function test_discounted_course_restored_by_amendment_shows_the_amended_charge_in_the_queue(): void
    {
        $token = $this->director();
        $course = $this->course($this->student()->id, ['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0], 1500);
        $row = fn () => collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $course->ID);

        $this->assertNull($row(), 'free before the amendment');
        \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $course->ID, 'effective_from' => '2026-08-15',
            'rate' => 1200, 'rate_unit' => 'session', 'source_reference' => 'fx', 'reason' => 'test', 'created_at' => now()]);
        $this->assertSame(4800, (int) $row()['charge'], 'amended rate 1200 x 4 sessions, not the frozen discounted 0');
    }

    public function test_parent_portal_projects_a_free_course_as_free_not_unpaid(): void
    {
        $student = $this->student();
        $free = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0, 'RemainingSessions' => 4], 1500);
        $billed = $this->course($student->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800, 'RemainingSessions' => 8]);
        $raw = \Illuminate\Support\Str::random(32);
        \App\Models\ParentSession::create(['StudentID' => $student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);

        $classes = collect($this->getJson('/api/v1/parent/dashboard', ['Authorization' => "Bearer {$raw}"])->assertOk()->json('classes'))->keyBy('id');
        $this->assertSame('free', $classes[$free->ID]['payment_status']);
        $this->assertSame('免費（不適用）', $classes[$free->ID]['payment_status_label']);
        $this->assertSame('unpaid', $classes[$billed->ID]['payment_status']);
    }

    public function test_confirm_refuses_free_course_and_fractional_amounts(): void
    {
        $token = $this->director();
        $h = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $report = fn (StudentClass $c, $amount) => PaymentReport::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'reported_by_name' => 'x',
            'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'reported_amount' => $amount, 'status' => 'pending', 'report_token_hash' => str_repeat('b', 64), 'token_expires_at' => now()->addDay()]);
        $student = $this->student();
        $free = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0], 1500);
        $paid = $this->course($student->id, ['Rate' => 1100, 'SessionCount' => 8, 'Charge' => 8800]);

        $r = $report($free, 500);
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$r->id}/confirm")->assertStatus(422)->assertJsonPath('code', 'no_payment_obligation');
        $b = $this->withHeaders($h)->postJson('/api/v1/payment-reports/confirm-batch', ['ids' => [$r->id]]);
        $this->assertSame('no_payment_obligation', $b->json('results.0.code'));
        $this->assertSame(0, \App\Models\Payment::count());
        $this->assertSame(0, (int) $free->fresh()->Paid);

        $f = $report($paid, 1.5);
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$f->id}/confirm")->assertStatus(422)->assertJsonPath('code', 'invalid_report_amount');
        $this->assertSame(0, \App\Models\Payment::count());
    }

    public function test_only_the_currently_effective_amendment_decides_free(): void
    {
        $student = $this->student();
        $amend = fn (StudentClass $c, string $from, int $rate) => \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $c->ID,
            'effective_from' => $from, 'rate' => $rate, 'rate_unit' => 'session', 'source_reference' => 'fx', 'reason' => 'test', 'created_at' => now()]);
        $future = $this->course($student->id, ['Rate' => 0, 'Charge' => 0, 'SessionCount' => 4]);
        $amend($future, now()->addMonth()->toDateString(), 1200);
        $superseded = $this->course($student->id, ['Rate' => 0, 'Charge' => 0, 'SessionCount' => 4]);
        $amend($superseded, '2026-06-01', 1200);
        $amend($superseded, '2026-08-01', 0);

        $this->assertTrue($future->isFreeOfCharge());
        $this->assertTrue($superseded->isFreeOfCharge());
        $out = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$future->ID, $superseded->ID]);
        $this->assertSame('free', $out[$future->ID]['status']);
        $this->assertSame('free', $out[$superseded->ID]['status']);
    }

    public function test_discounted_date_mode_course_gets_no_obligation_but_unset_fee_stays_fee_unset(): void
    {
        $token = $this->director();
        $student = $this->student();
        $discounted = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0], 1500);
        $unset = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 0, 'Charge' => 0, 'SessionCount' => 0]);
        $code = fn (StudentClass $c) => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/payment-reports/director-record', ['student_class_id' => $c->ID, 'payment_date' => '2026-10-04', 'payment_method' => 'cash', 'amount' => 0])
            ->assertStatus(422)->json('code');

        $this->assertSame('no_payment_obligation', $code($discounted));
        $this->assertSame('monthly_fee_unset', $code($unset));
    }

    public function test_parent_portal_free_course_has_no_monthly_fee_estimate(): void
    {
        $student = $this->student();
        $free = $this->course($student->id, ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0, 'SessionCount' => 0, 'RemainingSessions' => 0, 'monthly_sessions' => 4], 1500);
        $raw = \Illuminate\Support\Str::random(32);
        \App\Models\ParentSession::create(['StudentID' => $student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);

        $classes = collect($this->getJson('/api/v1/parent/dashboard', ['Authorization' => "Bearer {$raw}"])->assertOk()->json('classes'))->keyBy('id');
        $this->assertSame('free', $classes[$free->ID]['payment_status']);
        $this->assertContains($classes[$free->ID]['monthly_fee_estimate'], [0, null]);
    }

    public function test_parent_payment_message_prices_an_amended_discounted_course_like_the_queue(): void
    {
        $token = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0], 1500);
        \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $course->ID, 'effective_from' => '2026-08-15',
            'rate' => 1200, 'rate_unit' => 'session', 'source_reference' => 'fx', 'reason' => 'test', 'created_at' => now()]);
        $body = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/parent/payment-message/{$student->id}")->assertOk()->json();

        $this->assertSame(4800, $body['total_amount'], 'amended 1200 x 4, not frozen Rate 1500 x 4');
        $this->assertStringContainsString('4,800', $body['message']);
    }

    public function test_free_overrides_legacy_paid_flag_in_course_index_and_parent_portal(): void
    {
        $token = $this->director();
        $student = $this->student();
        $free = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0, 'RemainingSessions' => 4, 'Paid' => 1], 1500);
        $rows = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/student-classes?student_id={$student->id}&per_page=100")->assertOk()->json('data'))
            ->keyBy(fn ($r) => (int) ($r['ID'] ?? $r['id']));
        $this->assertSame('free', $rows[$free->ID]['payment_status']);

        $raw = \Illuminate\Support\Str::random(32);
        \App\Models\ParentSession::create(['StudentID' => $student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);
        $classes = collect($this->getJson('/api/v1/parent/dashboard', ['Authorization' => "Bearer {$raw}"])->assertOk()->json('classes'))->keyBy('id');
        $this->assertSame('free', $classes[$free->ID]['payment_status']);
        $this->assertSame(1, (int) $free->fresh()->Paid, 'ledger untouched');
    }

    public function test_course_index_exposes_effective_total_for_amended_discounted_course(): void
    {
        $token = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['Rate' => 1500, 'SessionCount' => 4, 'Charge' => 0], 1500);
        \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $course->ID, 'effective_from' => '2026-08-15',
            'rate' => 1200, 'rate_unit' => 'session', 'source_reference' => 'fx', 'reason' => 'test', 'created_at' => now()]);
        $rows = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/student-classes?student_id={$student->id}&per_page=100")->assertOk()->json('data'))
            ->keyBy(fn ($r) => (int) ($r['ID'] ?? $r['id']));
        $this->assertSame(4800, $rows[$course->ID]['effective_total']);
    }

    /**
     * The single pricing authority's precedence (StudentClass::effectiveContractTotal); null = free.
     * @return array<string, array{array<string, mixed>, int, ?int, ?int}> course fields, discount, amendment rate, invoice total => expected
     */
    public static function precedenceMatrix(): array
    {
        return [
            // 1. a positive non-void invoice wins, even over a 100% discount (Codex P1)
            'invoice wins over 100% discount' => [['Rate' => 1500, 'Charge' => 0], 6000, null, 3000, 3000],
            // 2. the amendment in force wins, a rate of 0 included, over a positive frozen discount (Codex P1)
            'zero amendment over positive discount is free' => [['Rate' => 1500, 'Charge' => 4000], 2000, 0, null, null],
            // 2. a positive amendment prices a legacy Charge 0 / Rate 0 course with no snapshot (Codex P1)
            'positive amendment over no snapshot is priced' => [['Rate' => 0, 'Charge' => 0], 0, 1200, null, 4800],
            // 3. discount snapshot: the allocated Charge, not Rate x sessions (6000)
            'discount only' => [['Rate' => 1500, 'Charge' => 5400], 600, null, null, 5400],
            // 4. list price
            'plain' => [['Rate' => 1500, 'Charge' => 6000], 0, null, null, 6000],
        ];
    }

    /**
     * @dataProvider precedenceMatrix
     */
    public function test_one_pricing_authority_gives_the_same_number_on_every_surface(array $fields, int $discount, ?int $amendRate, ?int $invoice, ?int $expected): void
    {
        $token = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, $fields + ['SessionCount' => 4, 'RemainingSessions' => 4], $discount);
        if ($amendRate !== null) {
            \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $course->ID, 'effective_from' => '2026-08-15',
                'rate' => $amendRate, 'rate_unit' => 'session', 'source_reference' => 'fx', 'reason' => 'test', 'created_at' => now()]);
        }
        if ($invoice !== null) {
            Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => now(),
                'DueDate' => now(), 'TotalAmount' => $invoice, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        }
        $h = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->assertSame($expected ?? 0, $course->fresh()->effectiveContractTotal());
        $this->assertSame($expected === null, $course->fresh()->isFreeOfCharge());

        $queue = collect($this->withHeaders($h)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $course->ID);
        $this->assertSame($expected, $queue === null ? null : (int) $queue['charge'], 'tuition queue');

        $index = collect($this->withHeaders($h)->getJson("/api/v1/student-classes?student_id={$student->id}&per_page=100")->assertOk()->json('data'))
            ->first(fn ($r) => (int) ($r['ID'] ?? $r['id']) === (int) $course->ID);
        $this->assertSame($expected ?? 0, $index['effective_total'], 'course index effective_total');
        $this->assertSame($expected === null, $index['payment_status'] === 'free', 'course index free status');

        $message = $this->withHeaders($h)->getJson("/api/v1/parent/payment-message/{$student->id}")->assertOk()->json();
        $this->assertSame($expected, $message['total_amount'] ?? null, 'parent payment message');
    }

    public function test_free_date_mode_course_awaiting_settlement_is_not_queued_at_list_rate(): void
    {
        $token = $this->director();
        $student = $this->student();
        $closed = ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 0, 'SessionCount' => 0, 'settlement_day' => 1];
        $free = $this->course($student->id, $closed, 1500);
        $amendedFree = $this->course($student->id, $closed, 1500);
        $billed = $this->course($student->id, $closed);
        foreach ([$free, $amendedFree, $billed] as $c) { // attended lessons make MonthlyBillingService price a positive charge
            \App\Models\ClassSession::create(['StudentClassID' => $c->ID, 'SessionDate' => now()->toDateString(),
                'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'completed']);
        }
        // closed after the lesson: 結案未繳 / 提前結束未繳
        StudentClass::whereIn('ID', [$free->ID, $billed->ID])->update(['Stop' => 1, 'closed_reason' => 'settled_pending']);
        StudentClass::whereKey($amendedFree->ID)->update(['Stop' => 1, 'closed_reason' => 'contract_amended']);

        $ids = collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertContains((int) $billed->ID, $ids->all(), 'control: a billed closed date-mode course awaits settlement');
        $this->assertNotContains((int) $free->ID, $ids->all());
        $this->assertNotContains((int) $amendedFree->ID, $ids->all());
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
