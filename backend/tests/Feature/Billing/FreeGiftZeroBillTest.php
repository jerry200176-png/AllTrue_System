<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentClassPricingAmendment;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingPayableResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #3991 (Founder 2026-10-13): 免費月繳課程可以有零元帳單，但必須明確標記為免費／贈送；一般課程沒填費用不能自動當免費。
 * The marker is read from Invoice::ZERO_REASON_FREE_GIFT, never repeated here as a literal.
 */
class FreeGiftZeroBillTest extends TestCase
{
    use RefreshDatabase;

    private const RECORD = '/api/v1/payment-reports/director-record';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_explicit_free_gift_nt0_bill_is_recorded_and_marked(): void
    {
        $token = $this->directorToken();
        $course = $this->monthlyCourse($this->student());
        $res = $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]));
        $res->assertOk()->assertJsonPath('zero_amount_reason', Invoice::ZERO_REASON_FREE_GIFT);
        $invoice = Invoice::query()->findOrFail((int) $res->json('invoice_id'));
        $this->assertSame(Invoice::ZERO_REASON_FREE_GIFT, $invoice->zero_amount_reason);
        $this->assertTrue($invoice->isMarkedFreeGift());
        $this->assertSame(0, (int) $invoice->TotalAmount);
        $this->assertSame((int) $course->StudentID, (int) $invoice->StudentID);
        $this->assertNotNull($invoice->billing_period);
        // A bill, not a payment: nothing waits for confirmation.
        $this->assertSame(0, PaymentReport::query()->where('StudentClassID', $course->ID)->count());
        // The ledger exposes the mark so the UI can show the 免費／贈送 badge.
        $ledger = $this->withToken($token)->getJson('/api/v1/accounting/ledger?student_id=' . $course->StudentID)->assertOk();
        $row = collect($ledger->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertSame(Invoice::ZERO_REASON_FREE_GIFT, $row['zero_amount_reason'] ?? null);
        // The contract row is settled too, so the panel stops offering 登記這筆 (#3991 review).
        $this->assertTrue(collect($ledger->json('courses'))->firstWhere('id', $course->ID)['paid']);
        $this->withToken($token)->postJson("/api/v1/invoices/{$invoice->id}/payments", ['Amount' => 100, 'PaidAt' => '2026-10-13', 'Method' => 'cash'])->assertStatus(422)->assertJsonPath('message', '免費／贈送帳單不能登記收款；這門課要收費的話，請先作廢這張帳單再開立新帳單');
        $course->update(['Rate' => 750]); // #3991 review: a fee added later by any path, so the mark no longer settles the term
        $this->assertFalse(collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_id=' . $course->StudentID)->json('courses'))->firstWhere('id', $course->ID)['paid']);
    }

    /** #3991 review: a zero-fee count course the resolver already calls `free` can still record its marked bill. */
    public function test_a_zero_fee_count_course_reaches_the_free_gift_path(): void
    {
        $token = $this->directorToken();
        $course = $this->monthlyCourse($this->student(), ['ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4]);
        $this->withToken($token)->postJson(self::RECORD, $this->payload($course))
            ->assertStatus(422)->assertJsonPath('code', 'zero_fee_no_payment_obligation');
        $res = $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]))->assertOk();
        $this->assertSame(Invoice::ZERO_REASON_FREE_GIFT, Invoice::query()->findOrFail((int) $res->json('invoice_id'))->zero_amount_reason);
        Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-20', 'Note' => '', 'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'unpaid']); // a later positive bill ends the mark
        $this->assertFalse(collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_id=' . $course->StudentID)->json('courses'))->firstWhere('id', $course->ID)['paid']);
    }

    /** #4012: a course with a pending payment report cannot be marked 免費／贈送 until that report is resolved. */
    public function test_a_pending_payment_report_blocks_the_free_gift_mark(): void
    {
        $token = $this->directorToken();
        $course = $this->monthlyCourse($this->student());
        $report = PaymentReport::create([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'InvoiceID' => null,
            'reported_by_name' => 'x', 'payment_date' => '2026-10-13', 'payment_method' => 'cash',
            'reported_amount' => 3000, 'status' => 'pending', 'report_token_hash' => hash('sha256', 'free-gift-4012'),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);

        $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]))
            ->assertStatus(422)->assertJsonPath('code', 'free_gift_pending_report');
        $this->assertSame(0, Invoice::query()->where('StudentClassID', $course->ID)->count());

        // Once the report is rejected, the mark goes through as before.
        $report->update(['status' => 'rejected']);
        $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]))->assertOk();
    }

    /** #3991 review: a director of another campus cannot write a free/gift bill. */
    public function test_a_foreign_campus_director_cannot_record_a_free_gift_bill(): void
    {
        $course = $this->monthlyCourse($this->student());
        $this->withToken($this->directorToken(2))->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]))
            ->assertForbidden();
        $this->assertSame(0, Invoice::query()->where('StudentClassID', $course->ID)->count());
        $admin = \Database\Factories\UserFactory::new()->superAdmin()->create(); // the super_admin exemption stays executable (round 4)
        AuthToken::create(['user_id' => $admin->id, 'token' => $raw = bin2hex(random_bytes(16)), 'expires_at' => Carbon::now()->addDay()]);
        $this->withToken($raw)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]))->assertOk();
    }

    /** #3991 review round 4: a marked earlier term does not settle the course's current term in the ledger. */
    public function test_a_marked_earlier_term_does_not_settle_the_current_one(): void
    {
        $course = $this->monthlyCourse($this->student());
        Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01', 'Note' => '', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid', 'billing_period' => '2026-09', 'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
        $courses = $this->withToken($this->directorToken())->getJson('/api/v1/accounting/ledger?student_id=' . $course->StudentID)->assertOk()->json('courses');
        $this->assertFalse(collect($courses)->firstWhere('id', $course->ID)['paid']);
    }

    /** #3991 review: an existing bill of another student is never relabeled. */
    public function test_an_existing_bill_of_another_student_is_not_relabeled(): void
    {
        $course = $this->monthlyCourse($this->student());
        $foreign = Invoice::create(['StudentID' => $this->student()->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01',
            'Note' => '', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'unpaid']);

        $this->withToken($this->directorToken())->postJson(self::RECORD, $this->payload($course, ['free_gift' => true, 'invoice_id' => $foreign->id]))
            ->assertStatus(422)->assertJsonPath('code', 'free_gift_invoice_owner_mismatch');
        $this->assertNull($foreign->fresh()->zero_amount_reason);
    }

    public function test_zero_without_the_free_gift_marker_is_rejected(): void
    {
        $token = $this->directorToken();
        $course = $this->monthlyCourse($this->student());

        $this->withToken($token)->postJson(self::RECORD, $this->payload($course))
            ->assertStatus(422)->assertJsonPath('code', 'monthly_fee_unset');
        $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => false]))
            ->assertStatus(422)->assertJsonPath('code', 'monthly_fee_unset');

        $this->assertSame(0, Invoice::query()->where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, PaymentReport::query()->where('StudentClassID', $course->ID)->count());
    }

    public function test_a_course_with_a_fee_cannot_be_marked_free_gift(): void
    {
        $token = $this->directorToken();
        $course = $this->monthlyCourse($this->student(), ['Rate' => 750]);

        $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]))
            ->assertStatus(422)->assertJsonPath('code', 'free_gift_course_has_fee');
        $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true, 'amount' => 500]))
            ->assertStatus(422)->assertJsonPath('code', 'free_gift_requires_zero');
        foreach ([['Paid' => 1], ['Pay' => 500], ['PayDate' => '2026-08-01']] as $legacy) { // #3991 review: legacy money on record
            $this->withToken($token)->postJson(self::RECORD, $this->payload($this->monthlyCourse($this->student(), $legacy), ['free_gift' => true]))
                ->assertStatus(422)->assertJsonPath('code', 'free_gift_course_has_fee');
        }

        $this->assertSame(0, Invoice::query()->where('StudentClassID', $course->ID)->count());
    }

    /** #3991 review: POST /invoices never creates a NT$0 bill, marked or not; only director-record records a 免費／贈送 term. */
    public function test_the_invoice_endpoint_refuses_any_nt0_bill(): void
    {
        $student = $this->student();
        $body = ['StudentID' => $student->id, 'StudentClassID' => $this->monthlyCourse($student)->ID, 'IssueDate' => '2026-10-01', 'TotalAmount' => 0, 'billing_period' => '2026-10'];
        foreach ([[], ['zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]] as $extra) {
            $this->withToken($this->directorToken())->postJson('/api/v1/invoices', $body + $extra)->assertStatus(422)->assertJsonPath('code', 'zero_amount_unmarked');
        }
        $this->assertSame(0, Invoice::query()->where('StudentID', $student->id)->count());
    }

    public function test_an_invoice_for_another_students_course_is_rejected(): void
    {
        $token = $this->directorToken();
        $owner = $this->student();
        $other = $this->student();
        $course = $this->monthlyCourse($owner, ['Rate' => 750]);

        foreach ([
            ['StudentClassID' => $course->ID],
            ['Items' => [['StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => 3000]]],
        ] as $courseRef) {
            $this->withToken($token)->postJson('/api/v1/invoices', [
                'StudentID' => $other->id, 'IssueDate' => '2026-10-01', 'TotalAmount' => 3000,
            ] + $courseRef)->assertStatus(422)->assertJsonPath('code', 'invoice_course_student_mismatch');
        }

        $this->assertSame(0, Invoice::query()->where('StudentID', $other->id)->count());
    }

    /** #3991 review round 3: amended fee, ambiguous or paid-into terms are refused; the note survives; a wrong mark can be voided. */
    public function test_free_gift_refusals_note_and_void(): void
    {
        $token = $this->directorToken();
        $amended = $this->monthlyCourse($this->student());
        StudentClassPricingAmendment::create(['student_class_id' => $amended->ID, 'effective_from' => '2026-09-01', 'rate' => 800, 'source_reference' => 't', 'reason' => 't']);
        $this->withToken($token)->postJson(self::RECORD, $this->payload($amended, ['free_gift' => true]))->assertStatus(422)->assertJsonPath('code', 'free_gift_course_has_fee');
        $itemOnly = $this->monthlyCourse($this->student()); // a positive line on another course's live bill is a fee on record
        InvoiceItem::create(['InvoiceID' => Invoice::create(['StudentID' => $itemOnly->StudentID, 'StudentClassID' => $amended->ID, 'IssueDate' => '2026-10-01', 'Note' => '', 'TotalAmount' => 900, 'PaidAmount' => 0, 'Status' => 'unpaid'])->id, 'StudentClassID' => $itemOnly->ID, 'Description' => 't', 'Amount' => 900]);
        $this->withToken($token)->postJson(self::RECORD, $this->payload($itemOnly, ['free_gift' => true]))->assertStatus(422)->assertJsonPath('code', 'free_gift_course_has_fee');
        $this->withToken($token)->postJson(self::RECORD, $this->payload($this->monthlyCourse($this->student(), ['ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4, 'Rate' => 750])))->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $legacy = $this->monthlyCourse($this->student()); // #3991 review: a monthly bill without a month is never relabeled
        $legacyBill = Invoice::create(['StudentID' => $legacy->StudentID, 'StudentClassID' => $legacy->ID, 'IssueDate' => '2026-10-01', 'Note' => '', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->withToken($token)->postJson(self::RECORD, $this->payload($legacy, ['free_gift' => true, 'invoice_id' => $legacyBill->id]))->assertStatus(422)->assertJsonPath('code', 'free_gift_invoice_unperiodized');
        $course = $this->monthlyCourse($this->student());
        $bill = fn () => Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01', 'Note' => 'auto', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-10']);
        [$first, $second] = [$bill(), $bill()];
        $record = fn () => $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true, 'note' => str_repeat('備', 300)]));
        $record()->assertStatus(409)->assertJsonPath('code', 'free_gift_invoice_ambiguous');
        $second->fill(['Status' => 'void'])->save();
        $report = PaymentReport::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'InvoiceID' => $first->id, 'reported_by_name' => 't', 'report_token_hash' => hash('sha256', uniqid()), 'token_expires_at' => now(), 'reported_amount' => 0, 'status' => 'rejected', 'payment_date' => '2026-10-01', 'payment_method' => 'cash']);
        $record()->assertStatus(422)->assertJsonPath('code', 'free_gift_invoice_has_amount');
        $report->delete();
        $record()->assertOk()->assertJsonPath('invoice_id', $first->id);
        $this->assertSame(['auto ' . Invoice::ZERO_REASON_FREE_GIFT_LABEL, 255], [mb_substr((string) $first->fresh()->Note, 0, 10), mb_strlen((string) $first->fresh()->Note)]);
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/charge-correction", ['new_charge' => 3000, 'reason' => 't'])
            ->assertStatus(409)->assertJsonPath('code', 'charge_correction_free_gift_locked'); // #3991 review: void the mark first
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", ['new_session_count' => 4, 'new_charge' => 3000, 'reason' => 't'])
            ->assertStatus(409)->assertJsonPath('code', 'billing_correction_free_gift_locked');
        $this->assertSame([0, 0], [(int) $course->fresh()->Charge, (int) $first->fresh()->TotalAmount]);
        $this->withToken($token)->postJson("/api/v1/invoices/{$first->id}/void", ['reason' => 'wrong term'])->assertOk();
        $this->assertSame('void', (string) $first->fresh()->Status);
    }

    /**
     * #4150 (Codex P2 on #4204): a monthly (date-mode) free/gift course rates its NT$0 period `paid`; the course list
     * must still say `free` (the resolver verdict), with the monthly summary kept as-is for the period rows.
     */
    public function test_a_monthly_free_gift_course_lists_as_free_not_the_period_status(): void
    {
        $token = $this->directorToken();
        $course = $this->monthlyCourse($this->student());
        $this->withToken($token)->postJson(self::RECORD, $this->payload($course, ['free_gift' => true]))->assertOk();
        $row = collect($this->withToken($token)->getJson('/api/v1/student-classes?student_id=' . $course->StudentID . '&per_page=100')
            ->assertOk()->json('data'))->firstWhere('ID', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('free', $row['payment_status']);
        $this->assertIsArray($row['monthly_payment']);
        $this->assertFalse($row['legacy_paid_flag_only']);
    }

    /** #4017 item 1: the resolver reads a course's fee evidence once, however many marked terms it has. */
    public function test_fee_evidence_is_read_once_per_course_across_marked_terms(): void
    {
        $course = $this->monthlyCourse($this->student());
        foreach (['2026-08', '2026-09', '2026-10'] as $period) {
            Invoice::create([
                'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => "{$period}-01",
                'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid', 'billing_period' => $period,
                'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT,
            ]);
        }
        $amendmentChecks = 0;
        DB::listen(function ($query) use (&$amendmentChecks): void {
            if (preg_match('/^select exists\s*\(select \* from [`"]?student_class_pricing_amendments/i', $query->sql)) {
                $amendmentChecks++;
            }
        });

        $statuses = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $course->ID]);

        $this->assertSame('free', $statuses[(int) $course->ID]['status']);
        $this->assertSame(1, $amendmentChecks, 'one fee-evidence check for the course, not one per marked term');
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function payload(StudentClass $course, array $over = []): array
    {
        return array_merge(['student_class_id' => $course->ID, 'payment_date' => '2026-10-13', 'payment_method' => 'cash', 'amount' => 0], $over);
    }

    private function directorToken(int $campusId = 1): string
    {
        $user = User::create(['LoginName' => 'director_' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @param array<string, mixed> $over */
    private function monthlyCourse(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 0, 'Paid' => 0,
            'Rate' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $over));
    }
}
