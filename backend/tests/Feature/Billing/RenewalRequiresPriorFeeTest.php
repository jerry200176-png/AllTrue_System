<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\ContractRenewal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #2567 (Founder 2026-10-13): a renewal must not settle a prior term whose fee was never entered.
 * Prior fee missing = no per-session price, no charge and no non-void billable invoice on the source course.
 */
class RenewalRequiresPriorFeeTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
        $this->token = $this->directorToken();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_renewal_is_refused_when_the_prior_fee_was_never_entered(): void
    {
        $source = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);

        $this->postAuthenticatedJson("/api/v1/student-classes/{$source->ID}/renew-monthly", ['end_date' => '2026-05-31'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'prior_fee_missing')
            ->assertJsonPath('message', ContractRenewal::PRIOR_FEE_MISSING_MESSAGE);

        $this->assertSame(1, StudentClass::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, (int) $source->fresh()->Stop);
    }

    public function test_preview_reports_the_blocker_for_monthly_and_count_courses(): void
    {
        $monthly = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $this->postAuthenticatedJson("/api/v1/student-classes/{$monthly->ID}/renewal-preview", ['mode' => 'renew_monthly', 'end_date' => '2026-05-31'])
            ->assertStatus(422)
            ->assertJsonPath('severity', 'blocked')
            ->assertJsonPath('blockers.0.code', 'prior_fee_missing');

        $count = $this->countCourse(['Rate' => 0, 'Charge' => 0]);
        $this->postAuthenticatedJson("/api/v1/student-classes/{$count->ID}/renewal-preview", ['mode' => 'purchase_batch', 'sessions' => 4, 'start_date' => '2026-05-05'])
            ->assertStatus(422)
            ->assertJsonPath('severity', 'blocked')
            ->assertJsonPath('blockers.0.code', 'prior_fee_missing');
    }

    public function test_count_purchase_batch_is_refused_when_the_prior_fee_was_never_entered(): void
    {
        $source = $this->countCourse(['Rate' => 0, 'Charge' => 0]);

        $this->postAuthenticatedJson("/api/v1/student-classes/{$source->ID}/purchase-batch", ['sessions' => 4, 'start_date' => '2026-05-05', 'mode' => 'new_purchase'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'prior_fee_missing')
            ->assertJsonPath('errors.prior_fee.0', ContractRenewal::PRIOR_FEE_MISSING_MESSAGE);

        $this->assertSame(1, StudentClass::query()->count());
    }

    public function test_a_voided_invoice_does_not_count_as_an_entered_fee(): void
    {
        $source = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $this->invoice($source, 4800, 'void');

        $this->postAuthenticatedJson("/api/v1/student-classes/{$source->ID}/renew-monthly", ['end_date' => '2026-05-31'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'prior_fee_missing');
    }

    public function test_renewal_is_allowed_once_the_prior_fee_is_entered_on_an_invoice(): void
    {
        $source = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $this->invoice($source, 4800, 'unpaid');

        $this->postAuthenticatedJson("/api/v1/student-classes/{$source->ID}/renew-monthly", ['end_date' => '2026-05-31'])
            ->assertCreated();

        $this->assertSame(2, StudentClass::query()->count());
        $this->assertSame('unpaid', Invoice::query()->where('StudentClassID', $source->ID)->value('Status'));
    }

    /**
     * #3991 (Founder 2026-10-13): a free course may have a NT$0 bill only when it is marked 免費／贈送. An unmarked
     * NT$0 bill is not a fee on record, and a marked one stops counting once voided.
     */
    public function test_a_zero_amount_bill_counts_only_when_marked_free_gift_and_not_voided(): void
    {
        $unmarked = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $this->invoice($unmarked, 0, 'unpaid');
        $this->assertSame('prior_fee_missing', app(ContractRenewal::class)->priorFeeBlocker($unmarked)['code'] ?? null);

        $free = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $this->invoice($free, 0, 'paid', Invoice::ZERO_REASON_FREE_GIFT);
        $this->assertNull(app(ContractRenewal::class)->priorFeeBlocker($free));
        $this->postAuthenticatedJson("/api/v1/student-classes/{$free->ID}/renew-monthly", ['end_date' => '2026-05-31'])
            ->assertCreated();

        $voided = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $this->invoice($voided, 0, 'void', Invoice::ZERO_REASON_FREE_GIFT);
        $this->postAuthenticatedJson("/api/v1/student-classes/{$voided->ID}/renew-monthly", ['end_date' => '2026-05-31'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'prior_fee_missing');
    }

    /** #3991 item 2: a bill of another student is never fee evidence for this course. */
    public function test_a_bill_of_another_student_is_not_fee_evidence(): void
    {
        $source = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $otherStudentId = (int) $this->countCourse([])->StudentID;
        Invoice::create(['StudentID' => $otherStudentId, 'StudentClassID' => $source->ID, 'IssueDate' => '2026-04-01', 'Note' => '',
            'billing_period' => '2026-04', 'TotalAmount' => 4800, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $item = Invoice::create(['StudentID' => $otherStudentId, 'IssueDate' => '2026-04-01', 'Note' => '',
            'TotalAmount' => 4800, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $item->id, 'StudentClassID' => $source->ID, 'Description' => 'April', 'Amount' => 4800]);

        $this->assertSame('prior_fee_missing', app(ContractRenewal::class)->priorFeeBlocker($source)['code'] ?? null);
    }

    public function test_renewal_is_unchanged_when_the_course_has_a_price(): void
    {
        $monthly = $this->monthlyCourse(['Rate' => 600, 'Charge' => 0]);
        $this->postAuthenticatedJson("/api/v1/student-classes/{$monthly->ID}/renew-monthly", ['end_date' => '2026-05-31'])
            ->assertCreated();
        $full = $this->postAuthenticatedJson("/api/v1/student-classes/{$this->monthlyCourse(['Rate' => 600, 'Charge' => 0])->ID}/renew-monthly", ['end_date' => '2026-05-31',
            'discount' => ['type' => 'PERCENTAGE', 'value' => '100', 'reason' => 'full scholarship']])->assertCreated(); // #3991 review: a 100% discount keeps its NT$0 bill
        $this->assertSame(0, (int) Invoice::query()->findOrFail((int) $full->json('invoice.id'))->TotalAmount);

        $count = $this->countCourse(['Rate' => 500, 'Charge' => 4000]);
        $this->postAuthenticatedJson("/api/v1/student-classes/{$count->ID}/purchase-batch", ['sessions' => 4, 'start_date' => '2026-05-05', 'mode' => 'new_purchase'])
            ->assertCreated();
    }

    public static function invoiceItemAmounts(): array { return ['positive' => [4800], 'free' => [0]]; }

    /** @dataProvider invoiceItemAmounts */
    public function test_a_line_item_on_another_invoice_counts_until_that_invoice_is_voided(int $amount): void
    {
        $source = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        // #3991: a NT$0 line item is fee evidence only on a bill marked 免費／贈送.
        $bill = Invoice::create(['StudentID' => $source->StudentID, 'IssueDate' => '2026-04-01', 'DueDate' => '2026-04-15', 'Note' => '',
            'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid', 'zero_amount_reason' => $amount === 0 ? Invoice::ZERO_REASON_FREE_GIFT : null]);
        InvoiceItem::create(['InvoiceID' => $bill->id, 'StudentClassID' => $source->ID, 'Description' => '四月', 'Amount' => $amount]);
        $gate = app(ContractRenewal::class);

        $this->assertNull($gate->priorFeeBlocker($source));
        $bill->update(['Status' => 'void']);
        $this->assertSame('prior_fee_missing', $gate->priorFeeBlocker($source)['code'] ?? null);
    }

    /** #3947 review (P2): the evidence rows are read with a row lock, so a concurrent voidInvoice() serializes with renewal. */
    public function test_fee_evidence_is_read_with_a_row_lock(): void
    {
        $source = $this->monthlyCourse(['Rate' => 0, 'Charge' => 0]);
        $this->invoice($source, 4800, 'unpaid');
        $sql = [];
        DB::listen(function ($q) use (&$sql) { $sql[] = strtolower($q->sql); });

        $this->assertNull(app(ContractRenewal::class)->priorFeeBlocker($source));
        $this->assertNotEmpty(array_filter($sql, fn ($s) => str_contains($s, '`invoice`') && str_contains($s, 'for update')), implode("\n", $sql));
    }

    public function test_package_member_fee_lives_on_the_package_and_is_not_blocked(): void
    {
        $member = $this->countCourse(['Rate' => 0, 'Charge' => 0, 'PackageID' => 5]);

        $this->assertNull(app(ContractRenewal::class)->priorFeeBlocker($member));
        $this->assertNotNull(app(ContractRenewal::class)->priorFeeBlocker($this->countCourse(['Rate' => 0, 'Charge' => 0])));
    }

    public function test_trial_conversion_is_not_a_renewal_and_stays_allowed_without_a_fee(): void
    {
        $trial = $this->countCourse(['ClassType' => 'trial', 'Rate' => 0, 'Charge' => 0, 'SessionCount' => 1, 'RemainingSessions' => 1]);

        $r = app(ContractRenewal::class)->convertTrial($trial, 4, '2026-05-05', 'one_on_one', 1, 'director', fn () => [], fn () => null);

        $this->assertSame(201, $r['status']);
    }

    /** @param array<string, mixed> $body */
    private function postAuthenticatedJson(string $uri, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->postJson($uri, $body);
    }

    private function invoice(StudentClass $course, int $amount, string $status, ?string $zeroReason = null): void
    {
        Invoice::create([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01', 'DueDate' => '2026-04-15',
            'billing_period' => '2026-04', 'ScheduleModeAtIssue' => (string) $course->ScheduleMode, 'Note' => '', 'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => $status,
            'zero_amount_reason' => $zeroReason,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function monthlyCourse(array $over): StudentClass
    {
        return $this->countCourse(array_merge([
            'ScheduleMode' => 'date', 'SessionCount' => 0, 'RemainingSessions' => 0, 'settlement_day' => 31, 'monthly_sessions' => 4,
            'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30',
        ], $over));
    }

    /** @param array<string, mixed> $over */
    private function countCourse(array $over): StudentClass
    {
        $student = Student::create(['name' => '續約前期費用', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 8, 'Charge' => 4000, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'week' => 2, 'time' => '18:00:00',
        ], $over));
    }

    private function directorToken(): string
    {
        $user = User::create(['LoginName' => 'director-prior-fee@example.com', 'Name' => '主任測試', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}

