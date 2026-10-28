<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder GO 2026-10-21 (docs/DIRECTOR_PAYMENT_ALERT_RULES.md §只有舊「已收」標記): a course whose `paid` rests on the
 * legacy StudentClass.Paid flag alone can be resolved either way — 已收過，補登收款 (director-record legacy_flag_backfill)
 * or 其實沒收，改成未繳 (payment-reports/legacy-flag-clear).
 */
class LegacyPaidFlagResolveTest extends TestCase
{
    use RefreshDatabase;

    /** A UUID built from a synthetic name: the API requires a uuid, and a literal one trips the secret scan's key rule. */
    private static function key(): string
    {
        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_DNS, 'synthetic-legacy-paid-flag')->toString();
    }

    public function test_backfill_records_confirmed_payment_and_clears_the_review_label(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1);
        $this->assertSame(['paid', true], $this->courseRow($token, $course));
        $res = $this->backfill($token, $course)->assertOk()->assertJsonPath('status', 'confirmed');
        $invoice = Invoice::where('StudentClassID', $course->ID)->sole();
        $this->assertSame([8800, 'paid'], [(int) $invoice->TotalAmount, (string) $invoice->Status]);
        $payment = Payment::where('InvoiceID', $invoice->id)->sole();
        $this->assertSame([8800, 'transfer', '2026-10-01'], [(int) $payment->Amount, $payment->Method, substr((string) $payment->PaidAt, 0, 10)]);
        $report = PaymentReport::findOrFail($res->json('report_id'));
        $this->assertSame(['confirmed', (int) $payment->id, (int) $invoice->id, true], [$report->status, (int) $report->payment_id, (int) $report->InvoiceID, $report->confirmed_by !== null && $invoice->reconciled_by !== null]);
        $this->assertSame(1, (int) $course->fresh()->Paid, 'the flag stays: the course is paid, now with evidence');
        $this->assertSame(['paid', false], $this->courseRow($token, $course));
    }

    public function test_backfill_replay_with_same_key_creates_no_second_payment(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1);
        $first = $this->backfill($token, $course)->assertOk();
        $this->backfill($token, $course)->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('report_id', $first->json('report_id'))->assertJsonPath('payment_id', $first->json('payment_id'));
        // Same key with changed details (or a new key) is not a replay: the course now has a payment record, so it is refused.
        $this->backfill($token, $course, ['amount' => 8000])
            ->assertStatus(422)->assertJsonPath('code', 'legacy_flag_has_payment');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentReport::count());
    }

    /** The backfill goes through confirm(): it collects on the open bill, capped by its balance; a refusal rolls the report back. */
    public function test_backfill_uses_the_open_bill_and_rolls_back_on_a_confirm_refusal(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1);
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->backfill($token, $course, ['idempotency_key' => null])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->backfill($token, $course, ['amount' => 0])->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->backfill($token, $course)->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->assertSame([0, 0], [PaymentReport::count(), Payment::count()]);
        // A partial payment leaves the bill owing, so the legacy flag is dropped (as 撤銷收款 does).
        $this->backfill($token, $course, ['amount' => 3000])->assertOk()->assertJsonPath('invoice_id', $invoice->id);
        $this->assertSame([1, 'partial', 0], [Invoice::count(), (string) $invoice->fresh()->Status, (int) $course->fresh()->Paid]);
        $this->clear($token, $this->legacyCourse(1, ['Charge' => 0]))->assertStatus(422); // 0 元 is out of scope
        $this->clear($token, $this->legacyCourse(1, ['ClassType' => 'trial']))->assertStatus(422)->assertJsonPath('code', 'legacy_flag_trial_fee'); // #4339 trial fee by date
    }

    public function test_both_actions_refuse_a_course_with_a_real_payment(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1);
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 8800, 'PaidAmount' => 8800, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 8800, 'PaidAt' => '2026-09-01', 'Method' => 'cash']);
        $this->backfill($token, $course)->assertStatus(422)->assertJsonPath('code', 'legacy_flag_has_payment');
        $this->clear($token, $course)->assertStatus(422)->assertJsonPath('code', 'legacy_flag_has_payment');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, (int) $course->fresh()->Paid);
    }

    public function test_both_actions_refuse_while_a_pending_report_exists_and_clear_refuses_a_confirmed_one(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1);
        $report = $this->report($course, 'pending');
        $this->backfill($token, $course)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
        $this->clear($token, $course)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
        $report->update(['status' => 'confirmed', 'confirmed_at' => Carbon::now()]);
        $this->clear($token, $course)->assertStatus(422)->assertJsonPath('code', 'confirmed_report_exists');
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, (int) $course->fresh()->Paid);
    }

    public function test_wrong_campus_and_teacher_are_refused(): void
    {
        $course = $this->legacyCourse(1);
        $other = $this->token('A', [2]);
        $this->backfill($other, $course)->assertForbidden();
        $this->clear($other, $course)->assertForbidden();
        $teacher = $this->token('T', []);
        $this->backfill($teacher, $course)->assertForbidden();
        $this->clear($teacher, $course)->assertForbidden();
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, (int) $course->fresh()->Paid);
    }

    public function test_clear_sets_unpaid_without_touching_bills_and_audits_only_a_reason_hash(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1, ['Pay' => 8800, 'closed_reason' => 'settled']);
        $reason = '家長確認尚未繳費';
        $this->clear($token, $course, '')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(1, (int) $course->fresh()->Paid);
        $this->clear($token, $course, $reason)->assertOk();
        $fresh = $course->fresh();
        $this->assertSame([0, 0, null, 'settled_pending'], [(int) $fresh->Paid, (int) $fresh->Pay, $fresh->PayDate, $fresh->closed_reason]);
        $this->assertSame([0, 0, 0], [Invoice::count(), Payment::count(), PaymentReport::count()]);
        $event = DB::table('security_audit_events')->where('event_type', 'student_class.legacy_paid_flag_cleared')->sole();
        $this->assertStringNotContainsString($reason, (string) $event->metadata);
        $this->assertSame(hash('sha256', $reason), json_decode((string) $event->metadata, true)['reason_hash']);
        // Existing rules now list it as owed for the whole contract amount; settled → settled_pending (as 撤銷收款) reads 待收款.
        $row = collect($this->withHeaders($this->auth($token))->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())
            ->firstWhere('id', $course->ID);
        $this->assertSame(['pending_reconciliation', 8800, false], [$row['payment_status'], $row['outstanding'], $row['legacy_paid_flag_only'] ?? false]);
        // Replay: already unpaid, nothing changes and no second audit event.
        $this->clear($token, $course, $reason)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.legacy_paid_flag_cleared')->count());
    }

    /** In-app #428 / #438: monthly contracts had the 需核對 label and no way out; same two actions, same audit. */
    public function test_a_monthly_contract_resolves_both_ways(): void
    {
        $token = $this->token('A', [1]);
        $monthly = ['ScheduleMode' => 'date', 'Charge' => 3000, 'Rate' => 3000, 'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31'];
        $cleared = $this->legacyCourse(1, $monthly);
        $this->clear($token, $cleared)->assertOk();
        $this->assertSame([0, null], [(int) $cleared->fresh()->Paid, $cleared->fresh()->PayDate]);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.legacy_paid_flag_cleared')->count());

        $paid = $this->legacyCourse(1, $monthly);
        // No bill and no session evidence: the stored charge still caps what a new bill may record (Codex P2).
        $this->backfill($token, $paid, ['amount' => 5000])->assertStatus(422)->assertJsonPath('code', 'monthly_amount_stale')->assertJsonPath('expected_amount', 3000);
        $this->assertSame([0, 0], [Invoice::count(), PaymentReport::count()]);
        $this->backfill($token, $paid, ['amount' => 3000, 'idempotency_key' => (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'synthetic-legacy-backfill-1')])
            ->assertOk()->assertJsonPath('status', 'confirmed');
        $invoice = Invoice::where('StudentClassID', $paid->ID)->sole();
        $this->assertSame([3000, 'paid', 1], [(int) $invoice->TotalAmount, (string) $invoice->Status, (int) $paid->fresh()->Paid]);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.legacy_paid_flag_backfill')->count());
        $this->assertSame(['paid', false], $this->courseRow($token, $paid));
    }

    /** A shared package is one obligation: clearing the mark clears the package and every member, like markPackagePaid sets them. */
    public function test_clearing_a_shared_package_mark_clears_the_package_and_every_member(): void
    {
        $token = $this->token('A', [1]);
        [$lead, $sibling, $package] = $this->legacyPackage(1);
        $this->assertSame(['paid', true], $this->courseRow($token, $sibling));
        $this->clear($token, $lead)->assertOk();
        $this->assertFalse((bool) $package->fresh()->paid);
        $this->assertNull($package->fresh()->paid_at);
        foreach ([$lead, $sibling] as $member) {
            $this->assertSame([0, null], [(int) $member->fresh()->Paid, $member->fresh()->PayDate]);
        }
        $event = DB::table('security_audit_events')->where('event_type', 'student_class.legacy_paid_flag_cleared')->sole();
        $this->assertSame(2, json_decode((string) $event->metadata, true)['row_count']);
        $this->assertSame([0, 0, 0], [Invoice::count(), Payment::count(), PaymentReport::count()]);
        $this->assertSame(false, $this->courseRow($token, $sibling)[1]);
    }

    /** A member whose own Paid is 0 is still paid by the package's mark: clearing from it must not answer 「已經是未繳」. */
    public function test_clearing_from_a_member_paid_only_by_the_package_mark_clears_the_package(): void
    {
        $token = $this->token('A', [1]);
        [$lead, $sibling, $package] = $this->legacyPackage(1);
        $sibling->update(['Paid' => 0, 'PayDate' => null]);
        $this->clear($token, $sibling)->assertOk()->assertJsonMissing(['replayed' => true]);
        $this->assertFalse((bool) $package->fresh()->paid);
        $this->assertSame([0, 0], [(int) $lead->fresh()->Paid, (int) $sibling->fresh()->Paid]);
        $this->clear($token, $sibling)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.legacy_paid_flag_cleared')->count());
    }

    public function test_backfilling_a_shared_package_records_one_payment_and_a_partial_one_drops_the_marks(): void
    {
        $token = $this->token('A', [1]);
        [$lead, $sibling, $package] = $this->legacyPackage(1);
        $this->backfill($token, $lead, ['amount' => 8800])->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertSame([1, true, 1, 1], [Invoice::where('StudentClassID', $lead->ID)->count(), (bool) $package->fresh()->paid, (int) $lead->fresh()->Paid, (int) $sibling->fresh()->Paid]);
        $this->assertSame([false, false], [$this->courseRow($token, $lead)[1], $this->courseRow($token, $sibling)[1]]);

        [$lead2, $sibling2, $package2] = $this->legacyPackage(1);
        Invoice::create(['StudentID' => $lead2->StudentID, 'StudentClassID' => $lead2->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->backfill($token, $lead2, ['amount' => 3000, 'idempotency_key' => (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'synthetic-legacy-backfill-2')])->assertOk();
        $this->assertSame([false, 0, 0], [(bool) $package2->fresh()->paid, (int) $lead2->fresh()->Paid, (int) $sibling2->fresh()->Paid]);
    }

    /** Evidence and reports are read across the whole package, so a sibling's money or report blocks both actions. */
    public function test_a_sibling_payment_or_report_blocks_a_shared_package(): void
    {
        $token = $this->token('A', [1]);
        [$lead, $sibling] = $this->legacyPackage(1);
        $report = $this->report($sibling, 'pending');
        $this->backfill($token, $lead)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
        $this->clear($token, $lead)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
        $report->update(['status' => 'confirmed', 'confirmed_at' => Carbon::now()]);
        $this->clear($token, $lead)->assertStatus(422)->assertJsonPath('code', 'confirmed_report_exists');
        $report->delete();
        $invoice = Invoice::create(['StudentID' => $sibling->StudentID, 'StudentClassID' => $sibling->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 8800, 'PaidAmount' => 8800, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 8800, 'PaidAt' => '2026-09-01', 'Method' => 'cash']);
        $this->backfill($token, $lead)->assertStatus(422)->assertJsonPath('code', 'legacy_flag_has_payment');
        $this->clear($token, $lead)->assertStatus(422)->assertJsonPath('code', 'legacy_flag_has_payment');
        $this->assertSame([1, 1], [(int) $lead->fresh()->Paid, (int) $sibling->fresh()->Paid]);
    }

    /**
     * Security review: the shared-package decision reads reports and evidence only after every sibling course row, their reports,
     * the package row and their bills are locked (confirm()'s order), so a concurrent confirm cannot slip past a snapshot read.
     */
    public function test_shared_package_actions_lock_siblings_reports_package_and_bills_before_any_read(): void
    {
        $token = $this->token('A', [1]);
        $log = [];
        DB::listen(function ($query) use (&$log) {
            $log[] = strtolower($query->sql);
        });
        foreach (['clear', 'backfill'] as $action) {
            [$lead, $sibling] = $this->legacyPackage(1);
            Invoice::create(['StudentID' => $lead->StudentID, 'StudentClassID' => $sibling->ID, 'IssueDate' => '2026-09-01',
                'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'unpaid']);
            $log = [];
            $action === 'clear' ? $this->clear($token, $sibling)->assertOk() : $this->backfill($token, $sibling)->assertOk();
            $find = fn (string $from) => collect($log)->search(fn ($sql) => str_contains($sql, $from) && str_contains($sql, ' for update'));
            $courses = $find('from `studentclass` where `id` in');
            $locks = [$courses, $find('from `payment_reports`'), $find('from `course_packages`'), $find('from `invoice`')];
            $this->assertSame($locks, collect($locks)->sort()->values()->all(), "$action: courses, reports, package, bills in that order");
            $this->assertStringContainsString('order by `id` asc for update', $log[$courses], "$action: sibling rows locked by id");
            $plainRead = collect($log)->skip($courses)->filter(fn ($sql) => !str_contains($sql, ' for update'))->keys()->first();
            $this->assertGreaterThan($locks[3], $plainRead, "$action: nothing is read before every lock is held");
        }
    }

    /** Codex P1: a stale PackageID pointing at another student's or campus's package must never have its paid mark changed. */
    public function test_a_stale_package_id_to_a_foreign_package_is_refused(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1);
        $foreign = $this->legacyCourse(2);
        $package = CoursePackage::create(['student_id' => $foreign->StudentID, 'campus_id' => 2, 'name' => 'F', 'billing_mode' => 'count',
            'total_sessions' => 16, 'remaining_sessions' => 16, 'used_sessions' => 0, 'rate' => 550, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'paid_at' => '2026-03-01', 'stop' => false, 'enabled' => true]);
        $course->update(['PackageID' => $package->id]);
        $this->clear($token, $course)->assertStatus(422)->assertJsonPath('code', 'legacy_flag_package_mismatch');
        $this->backfill($token, $course)->assertStatus(422)->assertJsonPath('code', 'legacy_flag_package_mismatch');
        $this->assertSame([true, 1, 1], [(bool) $package->fresh()->paid, (int) $course->fresh()->Paid, (int) $foreign->fresh()->Paid]);
        $this->assertSame([0, 0], [Invoice::count(), PaymentReport::count()]);
    }

    /** Codex P2: paying one member's bill must not keep the whole package marked paid while a sibling still owes. */
    public function test_backfill_drops_the_package_marks_while_a_sibling_still_owes(): void
    {
        $token = $this->token('A', [1]);
        [$lead, $sibling, $package] = $this->legacyPackage(1);
        Invoice::create(['StudentID' => $sibling->StudentID, 'StudentClassID' => $sibling->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->backfill($token, $lead, ['amount' => 8800])->assertOk();
        $this->assertSame([false, 0, 0], [(bool) $package->fresh()->paid, (int) $lead->fresh()->Paid, (int) $sibling->fresh()->Paid]);
    }

    /** Codex P2: the ledger tells the card about any package membership (monthly members have package_id null there). */
    public function test_ledger_flags_a_monthly_package_member_for_the_clear_warning(): void
    {
        $token = $this->token('A', [1]);
        [$lead, , $package] = $this->legacyPackage(1);
        $lead->update(['ScheduleMode' => 'date']);
        $course = collect($this->withHeaders($this->auth($token))->getJson("/api/v1/accounting/ledger?student_class_id={$lead->ID}")->assertOk()->json('courses'))->firstWhere('id', $lead->ID);
        $this->assertSame([null, true], [$course['package_id'], $course['in_package']]);
        $plain = $this->legacyCourse(1);
        $this->assertFalse(collect($this->withHeaders($this->auth($token))->getJson("/api/v1/accounting/ledger?student_class_id={$plain->ID}")->json('courses'))->firstWhere('id', $plain->ID)['in_package']);
    }

    /** Codex P1: confirm()'s package write must reach only the package owner's own courses, never a stale foreign PackageID. */
    public function test_backfill_leaves_a_foreign_course_with_a_stale_package_id_untouched(): void
    {
        $token = $this->token('A', [1]);
        [$lead, , $package] = $this->legacyPackage(1);
        $foreign = $this->legacyCourse(2, ['Paid' => 0, 'PayDate' => null, 'PackageID' => $package->id]);
        $this->backfill($token, $lead, ['amount' => 8800])->assertOk();
        $this->assertSame([true, 1], [(bool) $package->fresh()->paid, (int) $lead->fresh()->Paid]);
        $this->assertSame([0, null, $package->id], [(int) $foreign->fresh()->Paid, $foreign->fresh()->PayDate, (int) $foreign->fresh()->PackageID]);
    }

    /** Codex P2: a monthly backfill is capped by the month confirm() will bill (oldest unbilled), not the payment date's month. */
    public function test_monthly_backfill_cap_uses_the_month_confirmation_bills(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1, ['ScheduleMode' => 'date', 'Charge' => 1650, 'Rate' => 1650, 'rate_unit' => 'session',
            'StartDate' => '2026-08-01', 'EndDate' => '2026-10-31']);
        foreach (['2026-08-29' => 'attended', '2026-08-31' => 'attended', '2026-10-05' => 'attended'] as $date => $status) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }
        // August (2 lessons, NT$3300) is the oldest unbilled month, so an October payment date still backfills August in full.
        $this->backfill($token, $course, ['amount' => 3300, 'payment_date' => '2026-10-20'])->assertOk()->assertJsonPath('status', 'confirmed');
        $invoice = Invoice::where('StudentClassID', $course->ID)->sole();
        $this->assertSame(['2026-08', 3300], [(string) $invoice->billing_period, (int) $invoice->TotalAmount]);
    }

    /** Security review: a caller outside the campus is refused from the routing read, before any lock or write. */
    public function test_a_cross_campus_request_for_a_shared_package_is_refused_without_taking_locks(): void
    {
        [$lead, $sibling, $package] = $this->legacyPackage(1);
        $other = $this->token('A', [2]);
        $log = [];
        DB::listen(function ($query) use (&$log) {
            $log[] = strtolower($query->sql);
        });
        $this->clear($other, $sibling)->assertForbidden();
        $this->backfill($other, $sibling)->assertForbidden();
        $this->assertSame([], array_values(array_filter($log, fn ($sql) => str_contains($sql, ' for update') || str_starts_with($sql, 'update ') || str_starts_with($sql, 'insert into `payment'))));
        $this->assertSame([true, 1, 1], [(bool) $package->fresh()->paid, (int) $lead->fresh()->Paid, (int) $sibling->fresh()->Paid]);
    }

    /** @return array{0: StudentClass, 1: StudentClass, 2: CoursePackage} two members of one paid shared package, no payment record */
    private function legacyPackage(int $campusId): array
    {
        $lead = $this->legacyCourse($campusId);
        $package = CoursePackage::create(['student_id' => $lead->StudentID, 'campus_id' => $campusId, 'name' => 'P', 'billing_mode' => 'count',
            'total_sessions' => 16, 'remaining_sessions' => 16, 'used_sessions' => 0, 'rate' => 550, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'paid_at' => '2026-03-01', 'stop' => false, 'enabled' => true]);
        $lead->update(['PackageID' => $package->id]);
        $sibling = StudentClass::create(array_merge($lead->only(['StudentID', 'GradeID', 'TeacherID', 'by1', 'Period', 'TotalHours', 'Charge', 'PayDate', 'Paid',
            'Stop', 'ScheduleMode', 'SessionCount', 'SessionDuration', 'RemainingSessions', 'ClassType', 'UsedSessions']),
            ['SubjectID' => 2, 'PackageID' => $package->id, 'StartDate' => now(), 'MDate' => now(), 'Pay' => null]));

        return [$lead->fresh(), $sibling, $package];
    }

    /** @return array{0: ?string, 1: ?bool} */
    private function courseRow(string $token, StudentClass $course): array
    {
        $row = collect($this->withHeaders($this->auth($token))->getJson('/api/v1/student-classes?branch_id=1')->assertOk()->json('data'))
            ->firstWhere('ID', $course->ID);
        return [$row['payment_status'] ?? null, $row['legacy_paid_flag_only'] ?? null];
    }

    private function backfill(string $token, StudentClass $course, array $overrides = [])
    {
        return $this->withHeaders($this->auth($token))->postJson('/api/v1/payment-reports/director-record', array_merge([
            'student_class_id' => $course->ID, 'legacy_flag_backfill' => true, 'idempotency_key' => self::key(),
            'payment_date' => '2026-10-01', 'payment_method' => 'transfer', 'amount' => 8800,
        ], $overrides));
    }

    private function clear(string $token, StudentClass $course, string $reason = '其實沒收')
    {
        return $this->withHeaders($this->auth($token))->postJson('/api/v1/payment-reports/legacy-flag-clear', [
            'student_class_id' => $course->ID, 'reason' => $reason,
        ]);
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function report(StudentClass $course, string $status): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'reported_by_name' => 'S',
            'payment_date' => '2026-10-01', 'payment_method' => 'cash', 'reported_amount' => 8800, 'status' => $status,
            'report_token_hash' => hash('sha256', 'legacy-test-' . uniqid()), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    private function token(string $type, array $campusIds): string
    {
        $user = User::create(['LoginName' => 'u_' . uniqid() . '@test.com', 'Name' => 'U', 'PSW' => 'secret', 'type' => $type, 'phone' => 912345678]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);
        return $raw;
    }

    private function legacyCourse(int $campusId, array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 'S' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1,
            'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'EndDate' => null, 'TotalHours' => 20, 'Charge' => 8800, 'Pay' => null,
            'PayDate' => '2026-03-01', 'Paid' => 1, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 5, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }
}
