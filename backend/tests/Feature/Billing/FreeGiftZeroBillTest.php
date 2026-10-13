<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertSame(0, Invoice::query()->where('StudentClassID', $course->ID)->count());
    }

    public function test_a_missing_fee_is_rejected_on_the_invoice_endpoint_without_the_marker(): void
    {
        $token = $this->directorToken();
        $student = $this->student();
        $course = $this->monthlyCourse($student);
        $body = ['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => 0, 'billing_period' => '2026-10'];

        $this->withToken($token)->postJson('/api/v1/invoices', $body)
            ->assertStatus(422)->assertJsonPath('code', 'zero_amount_unmarked');
        $this->assertSame(0, Invoice::query()->where('StudentClassID', $course->ID)->count());

        $created = $this->withToken($token)->postJson('/api/v1/invoices', $body + ['zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT])
            ->assertCreated();
        $this->assertSame(Invoice::ZERO_REASON_FREE_GIFT, Invoice::query()->findOrFail((int) $created->json('id'))->zero_amount_reason);
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

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function payload(StudentClass $course, array $over = []): array
    {
        return array_merge([
            'student_class_id' => $course->ID,
            'payment_date' => '2026-10-13',
            'payment_method' => 'cash',
            'amount' => 0,
        ], $over);
    }

    private function directorToken(): string
    {
        $user = User::create([
            'LoginName' => 'director_' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret',
            'type' => 'A', 'phone' => 912345678,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function student(): Student
    {
        return Student::create([
            'name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '',
        ]);
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
