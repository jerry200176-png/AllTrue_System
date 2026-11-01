<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\ContractMoneyVerdict;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #4018 (Founder 2026-10-29): a fully discounted (NT$0) renewal is settled, and the approver stays on the record. */
class FullDiscountSettledTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
        config(['billing.transaction_discounts_enabled' => true]);
        $user = User::create(['LoginName' => 'director-fulldisc@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $this->userId = (int) $user->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function fullDiscounts(): array
    {
        return ['percent 100' => [['type' => 'PERCENTAGE', 'value' => '100', 'reason' => 'scholarship']],
            'fixed = price' => [['type' => 'FIXED_AMOUNT', 'value' => '2400', 'reason' => 'scholarship']]];
    }

    /** @dataProvider fullDiscounts */
    public function test_a_fully_discounted_renewal_is_settled_everywhere(array $discount): void
    {
        $new = $this->renew($discount);
        $invoice = Invoice::query()->where('StudentClassID', $new->ID)->firstOrFail();
        $this->assertSame(0, (int) $invoice->TotalAmount);
        $this->assertSame('paid', $invoice->Status);
        $this->assertSame(Invoice::ZERO_REASON_FULL_DISCOUNT, $invoice->zero_amount_reason);
        $this->assertSame($this->userId, (int) $invoice->reconciled_by, 'the approver stays on the bill');
        $this->assertSame($this->userId, (int) $invoice->billing_snapshot['actor_id']);
        $this->assertSame(2400, (int) $invoice->billing_snapshot['original_amount']);

        $verdict = app(ContractMoneyVerdict::class)->forCourse($new->fresh());
        $this->assertSame('paid', $verdict->status);
        $this->assertSame(0, $verdict->outstanding);

        $ledger = $this->withToken($this->token)->getJson('/api/v1/accounting/ledger?student_id=' . $new->StudentID)->assertOk();
        $this->assertTrue(collect($ledger->json('courses'))->firstWhere('id', $new->ID)['paid'], 'no 待登記');
        $this->assertFalse(collect($ledger->json('courses'))->firstWhere('id', $new->ID)['free_gift_settled']);
        $this->assertTrue(collect($ledger->json('courses'))->firstWhere('id', $new->ID)['full_discount_settled'], '#4656: 已收清, not 需核對');
        $this->assertSame(0, (int) $ledger->json('summary.outstanding_total'));
    }

    public function test_a_partial_discount_stays_unpaid(): void
    {
        $new = $this->renew(['type' => 'PERCENTAGE', 'value' => '50', 'reason' => 'half']);
        $invoice = Invoice::query()->where('StudentClassID', $new->ID)->firstOrFail();
        $this->assertSame(1200, (int) $invoice->TotalAmount);
        $this->assertSame('unpaid', $invoice->Status);
        $this->assertNull($invoice->zero_amount_reason);
        $this->assertNotSame('paid', app(ContractMoneyVerdict::class)->forCourse($new->fresh())->status);
    }

    public function test_a_fee_billed_later_on_a_full_discount_term_is_not_settled_by_the_mark(): void
    {
        $new = $this->renew(self::fullDiscounts()['percent 100'][0]);
        Invoice::query()->where('StudentClassID', $new->ID)->update(['TotalAmount' => 900]);
        $this->assertNotSame('paid', app(ContractMoneyVerdict::class)->forCourse($new->fresh())->status);
    }

    public function test_a_full_discount_bill_does_not_settle_beside_another_open_positive_bill(): void
    {
        $new = $this->renew(self::fullDiscounts()['percent 100'][0]);
        $zero = Invoice::query()->where('StudentClassID', $new->ID)->firstOrFail();
        Invoice::create(['StudentID' => $new->StudentID, 'StudentClassID' => $new->ID, 'IssueDate' => '2026-04-27', 'Note' => '', 'billing_period' => $zero->billing_period,
            'ScheduleModeAtIssue' => 'date', 'TotalAmount' => 900, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->assertFalse($zero->fresh()->freeGiftHolds($new->fresh()));
        $this->assertNotSame('paid', app(ContractMoneyVerdict::class)->forCourse($new->fresh())->status);
        $ledger = $this->withToken($this->token)->getJson('/api/v1/accounting/ledger?student_id=' . $new->StudentID)->assertOk();
        $this->assertFalse(collect($ledger->json('courses'))->firstWhere('id', $new->ID)['paid']);
    }

    public function test_a_charge_changed_after_the_discount_is_not_settled(): void
    {
        $new = $this->renew(self::fullDiscounts()['percent 100'][0]);
        $new->update(['Charge' => 2400]);
        $this->assertFalse(Invoice::query()->where('StudentClassID', $new->ID)->firstOrFail()->freeGiftHolds($new->fresh()));
        $this->assertNotSame('paid', app(ContractMoneyVerdict::class)->forCourse($new->fresh())->status);
    }

    public function test_a_full_discount_stays_settled_after_a_lesson_is_attended(): void
    {
        $new = $this->renew(self::fullDiscounts()['percent 100'][0]);
        \App\Models\ClassSession::create(['StudentClassID' => $new->ID, 'SessionDate' => substr((string) $new->StartDate, 0, 10), 'StartTime' => '18:00', 'EndTime' => '19:00', 'Status' => 'attended']);
        $this->assertSame('paid', app(ContractMoneyVerdict::class)->forCourse($new->fresh())->status);
    }

    public function test_free_gift_records_who_granted_it(): void
    {
        $student = Student::create(['name' => '免費', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create($this->course($student->id, ['Rate' => 0, 'Charge' => 0, 'Paid' => 0]));
        $res = $this->withToken($this->token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID, 'payment_date' => '2026-04-27', 'payment_method' => 'cash', 'amount' => 0, 'free_gift' => true, 'billing_period' => '2026-04',
        ])->assertOk();
        $invoice = Invoice::query()->findOrFail((int) $res->json('invoice_id'));
        $this->assertSame($this->userId, (int) $invoice->reconciled_by);
        $this->assertNotNull($invoice->reconciled_at);
        // relabel branch: an existing NT$0 bill gets the approver too
        $other = StudentClass::create($this->course($student->id, ['Rate' => 0, 'Charge' => 0, 'Paid' => 0]));
        $bill = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $other->ID, 'IssueDate' => '2026-04-01', 'Note' => '', 'billing_period' => '2026-04',
            'ScheduleModeAtIssue' => 'date', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->withToken($this->token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $other->ID, 'payment_date' => '2026-04-27', 'payment_method' => 'cash', 'amount' => 0, 'free_gift' => true, 'invoice_id' => $bill->id,
        ])->assertOk();
        $this->assertSame($this->userId, (int) $bill->fresh()->reconciled_by);
        // a repeat request by someone else keeps the first approver
        $bill->update(['reconciled_by' => 999]);
        $this->withToken($this->token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $other->ID, 'payment_date' => '2026-04-27', 'payment_method' => 'cash', 'amount' => 0, 'free_gift' => true, 'invoice_id' => $bill->id,
        ])->assertOk();
        $this->assertSame(999, (int) $bill->fresh()->reconciled_by);
    }

    private function renew(array $discount): StudentClass
    {
        $student = Student::create(['name' => '全額折扣', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = StudentClass::create($this->course($student->id, []));
        $res = $this->withToken($this->token)->postJson("/api/v1/student-classes/{$source->ID}/renew-monthly", ['end_date' => '2026-05-31', 'discount' => $discount])->assertCreated();

        return StudentClass::query()->findOrFail((int) $res->json('new_course.id'));
    }

    private function course(int $studentId, array $over): array
    {
        return array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30', 'TotalHours' => 8, 'Charge' => 4800, 'Paid' => 1, 'Rate' => 600, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0, 'SessionDuration' => 60, 'RemainingSessions' => 0, 'settlement_day' => 15, 'monthly_sessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'week' => 2, 'time' => '18:00:00',
        ], $over);
    }
}
