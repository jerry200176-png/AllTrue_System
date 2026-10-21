<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
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

    private const KEY = '7f0c2a4e-1b3d-4c5e-8f9a-0b1c2d3e4f50';

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
        $this->assertSame(['confirmed', (int) $payment->id, (int) $invoice->id], [$report->status, (int) $report->payment_id, (int) $report->InvoiceID]);
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
        // A new key is refused: the course now has a payment record, so it is no longer legacy-flag-only.
        $this->backfill($token, $course, ['idempotency_key' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'])
            ->assertStatus(422)->assertJsonPath('code', 'legacy_flag_has_payment');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentReport::count());
    }

    public function test_backfill_requires_idempotency_key_and_positive_amount(): void
    {
        $token = $this->token('A', [1]);
        $course = $this->legacyCourse(1);
        $this->backfill($token, $course, ['idempotency_key' => null])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->backfill($token, $course, ['amount' => 0])->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->assertSame(0, Payment::count());
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

        // Existing rules now list it as owed for the whole contract amount.
        $row = collect($this->withHeaders($this->auth($token))->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())
            ->firstWhere('id', $course->ID);
        $this->assertSame(['unpaid', 8800, false], [$row['payment_status'], $row['outstanding'], $row['legacy_paid_flag_only'] ?? false]);

        // Replay: already unpaid, nothing changes and no second audit event.
        $this->clear($token, $course, $reason)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.legacy_paid_flag_cleared')->count());
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
            'student_class_id' => $course->ID, 'legacy_flag_backfill' => true, 'idempotency_key' => self::KEY,
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
