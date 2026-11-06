<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** in-app #506: an attended lesson the old month wrongly holds past its end moves into the next month's contract. */
class MonthlyRenewAdoptTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 08:00:00', 'Asia/Taipei'));
        $user = User::create(['LoginName' => 'adopt@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addHour()]);
        $this->headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Shaped like course 4316: Oct contract, 5 priced lessons, one billed 9000, the 11-01 lesson attended. */
    private function fixture(array $course = []): array
    {
        $student = Student::create(['name' => '學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create($course + ['StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31', 'TotalHours' => 10, 'Charge' => 9000, 'Paid' => 0, 'Rate' => 1800, 'rate_unit' => 'session',
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 5, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'week' => 7, 'time' => '17:00:00', 'settlement_day' => 31]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-10-01', 'DueDate' => '2026-10-31',
            'TotalAmount' => 9000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-10']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $sc->ID, 'Description' => '月結費用', 'Amount' => 9000]);
        foreach (['2026-10-04', '2026-10-11', '2026-10-18', '2026-10-25'] as $d) {
            ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $d, 'StartTime' => '17:00', 'EndTime' => '19:00', 'Status' => 'attended', 'TeacherID' => 99]);
        }
        $session = ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-11-01', 'StartTime' => '17:00', 'EndTime' => '19:00', 'Status' => 'attended', 'TeacherID' => 99]);
        $signIn = StudentSignIn::create(['StudentClassID' => $sc->ID, 'StudentID' => $student->id, 'ClassSessionID' => $session->id, 'SessionDeducted' => true, 'SignInDT' => '2026-11-01 17:00:00']);
        SessionDeductionLedger::create(['student_class_id' => $sc->ID, 'class_session_id' => $session->id, 'event_type' => 'deduct', 'source' => 'attendance']);
        return [$sc, $invoice, $session, $signIn];
    }

    private function renew(StudentClass $sc, int $status): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$sc->ID}/renew-monthly", ['end_date' => '2026-11-30'])->assertStatus($status);
    }

    public function test_attended_lesson_past_old_end_moves_to_new_contract(): void
    {
        [$sc, $invoice, $session, $signIn] = $this->fixture();
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$sc->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertOk()->assertJsonPath('warnings.0.code', 'adopt_outside_lesson')->assertJsonPath('warnings.0.source_new_charge', 7200);
        $before = collect($session->fresh()->getAttributes())->except(['StudentClassID', 'updated_at'])->all();
        $res = $this->renew($sc, 201)->assertJsonPath('adopted_session_ids', [$session->id]);
        $new = (int) $res->json('new_course.id');

        $this->assertSame(1, ClassSession::whereDate('SessionDate', '2026-11-01')->count());
        $this->assertSame($new, (int) $session->fresh()->StudentClassID);
        $this->assertSame('attended', $session->fresh()->Status);
        $this->assertSame($before, collect($session->fresh()->getAttributes())->except(['StudentClassID', 'updated_at'])->all());
        $this->assertSame($new, (int) $signIn->fresh()->StudentClassID);
        $this->assertSame(1, SessionDeductionLedger::netCount($new));
        $this->assertSame(0, SessionDeductionLedger::netCount($sc->ID));
        $this->assertSame([7200, 4], [(int) $sc->fresh()->Charge, (int) $sc->fresh()->SessionCount]);
        $this->assertSame(7200, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame(7200, (int) InvoiceItem::where('InvoiceID', $invoice->id)->sole()->Amount);
        $this->assertSame(5, ClassSession::where('StudentClassID', $new)->count());
        $this->assertSame(9000, (int) StudentClass::find($new)->Charge);
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'billing.monthly_renewal_adopt']);
    }

    public function test_still_blocked_when_source_cannot_give_the_lesson_away(): void
    {
        [$sc, $invoice] = $this->fixture();
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash']);
        $this->renew($sc, 422)->assertJsonPath('code', 'monthly_completed_sessions_outside_contract');
        $this->assertSame(1, StudentClass::count());

        [$discounted] = $this->fixture(['Charge' => 8000]);
        $this->renew($discounted, 422)->assertJsonPath('code', 'monthly_completed_sessions_outside_contract');
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$discounted->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_source_discounted');

        [$hourly] = $this->fixture(['rate_unit' => 'hour']);
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$hourly->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_requires_session_rate');

        [$twice, $inv2] = $this->fixture();
        Invoice::create(['StudentID' => $twice->StudentID, 'StudentClassID' => $twice->ID, 'IssueDate' => '2026-10-02', 'TotalAmount' => 1, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$twice->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_source_invoice_ambiguous');

        [$twoItems, $inv3] = $this->fixture();
        InvoiceItem::create(['InvoiceID' => $inv3->id, 'StudentClassID' => $twoItems->ID, 'Description' => 'x', 'Amount' => 0]);
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$twoItems->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_source_invoice_ambiguous');
    }

    public function test_lesson_after_the_new_period_is_not_adopted(): void
    {
        [$sc, , $session] = $this->fixture();
        $session->update(['SessionDate' => '2026-12-06']);
        $this->renew($sc, 422)->assertJsonPath('code', 'monthly_completed_sessions_outside_contract');
    }

    public function test_adoption_must_leave_the_source_a_lesson(): void
    {
        [$sc, $invoice] = $this->fixture(['SessionCount' => 1, 'Charge' => 1800]);
        $invoice->update(['TotalAmount' => 1800]);
        InvoiceItem::where('InvoiceID', $invoice->id)->update(['Amount' => 1800]);
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$sc->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_exceeds_source');
        $this->renew($sc, 422);
        $this->assertSame(1, StudentClass::count());
        $this->assertSame([1800, 1], [(int) $sc->fresh()->Charge, (int) $sc->fresh()->SessionCount]);
        $this->assertSame(1800, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame($sc->ID, (int) ClassSession::whereDate('SessionDate', '2026-11-01')->sole()->StudentClassID);
    }

    public function test_blocked_off_timetable_pending_report_or_pricing_amendment(): void
    {
        $preview = fn ($c) => $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$c->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30']);

        [$sat] = $this->fixture(['week' => 6]);
        $preview($sat)->assertJsonPath('blockers.1.code', 'adopt_off_timetable');

        [$reported, $inv] = $this->fixture();
        \Illuminate\Support\Facades\DB::table('payment_reports')->insert(['StudentID' => $reported->StudentID, 'StudentClassID' => $reported->ID, 'InvoiceID' => $inv->id, 'reported_by_name' => 'x',
            'payment_date' => '2026-10-20', 'reported_amount' => 9000, 'status' => 'pending', 'report_token_hash' => 'h', 'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->renew($reported, 422)->assertJsonPath('code', 'monthly_completed_sessions_outside_contract');
        $this->assertSame(1, StudentClass::where('StudentID', $reported->StudentID)->count());

        [$amended] = $this->fixture();
        \App\Models\StudentClassPricingAmendment::create(['student_class_id' => $amended->ID, 'effective_from' => '2026-11-01', 'rate' => 2000, 'rate_unit' => 'session', 'source_reference' => 'x', 'reason' => 'x', 'created_at' => now()]);
        $preview($amended)->assertJsonPath('blockers.1.code', 'adopt_source_discounted');
    }

    public function test_blocked_when_source_never_priced_the_stray_lesson_or_is_a_package(): void
    {
        // Probe shape: 4 October Sundays billed 4 x 1800 plus an unpriced attended 11-01.
        [$sc, $inv, $stray] = $this->fixture(['Charge' => 7200, 'SessionCount' => 4]);
        $inv->update(['TotalAmount' => 7200]);
        InvoiceItem::where('InvoiceID', $inv->id)->update(['Amount' => 7200]);
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$sc->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_not_priced_in_source');
        $this->renew($sc, 422);
        $this->assertSame(1, StudentClass::count());
        $this->assertSame([7200, 4], [(int) $sc->fresh()->Charge, (int) $sc->fresh()->SessionCount]);
        $this->assertSame(7200, (int) $inv->fresh()->TotalAmount);
        $this->assertSame($sc->ID, (int) $stray->fresh()->StudentClassID);

        // Reviewer variant: billed 5 for 4 Sundays plus an extra Wednesday, and a stray 11-01.
        [$extra] = $this->fixture();
        ClassSession::create(['StudentClassID' => $extra->ID, 'SessionDate' => '2026-10-14', 'StartTime' => '17:00', 'EndTime' => '19:00', 'Status' => 'attended', 'TeacherID' => 99]);
        $this->renew($extra, 422);
        $this->assertSame([9000, 5], [(int) $extra->fresh()->Charge, (int) $extra->fresh()->SessionCount]);

        [$pkg] = $this->fixture(['PackageID' => 5]);
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$pkg->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_package_course');

        [$other, $inv3] = $this->fixture();
        InvoiceItem::where('InvoiceID', $inv3->id)->update(['StudentClassID' => 999999]);
        $this->withHeaders($this->headers)->postJson("/api/v1/student-classes/{$other->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-11-30'])
            ->assertJsonPath('blockers.1.code', 'adopt_source_invoice_ambiguous');
    }
}
