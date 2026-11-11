<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Receipt self-correction PR 1 (issue 5113, in-app 522): baseline telemetry and write-time invariants.
 * Changes no behaviour and writes no money: the void guard still refuses exactly the same shapes.
 */
class ReceiptMismatchTelemetryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function mismatchReasons(): array
    {
        return [
            'course_other_student' => ['course_other_student'],
            'course_not_found' => ['course_not_found'],
            'invoice_not_found' => ['invoice_not_found'],
            'invoice_other_owner' => ['invoice_other_owner'],
            'invoice_void_holds_money' => ['invoice_void_holds_money'],
        ];
    }

    /** @dataProvider mismatchReasons */
    public function test_each_void_mismatch_refusal_logs_reason_ids_and_age_without_writing(string $reason): void
    {
        Carbon::setTestNow('2026-06-30 10:00:00');
        $h = $this->headers($this->createDirectorToken([1]));
        $student = $this->createStudent(1);
        $other = $this->createStudent(1);
        $mine = $this->createCountModeClass($student->id, ['Paid' => 1]);
        $theirs = $this->createCountModeClass($other->id, ['Paid' => 1]);
        $otherBill = Invoice::create(['StudentID' => $other->id, 'StudentClassID' => $theirs->ID, 'IssueDate' => '2026-04-01', 'TotalAmount' => 500, 'PaidAmount' => 500, 'Status' => 'paid']);
        $voidBill = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $mine->ID, 'IssueDate' => '2026-04-01', 'TotalAmount' => 500, 'PaidAmount' => 500, 'Status' => 'void']);
        Payment::create(['InvoiceID' => $voidBill->id, 'Amount' => 500, 'PaidAt' => '2026-04-10', 'Method' => 'cash']);

        $shape = [
            'course_other_student' => [$theirs->ID, null],
            'course_not_found' => [999999011, null],
            'invoice_not_found' => [$mine->ID, 999999012],
            'invoice_other_owner' => [$mine->ID, $otherBill->id],
            'invoice_void_holds_money' => [$mine->ID, $voidBill->id],
        ][$reason];
        $report = $this->confirmedReport($student, (int) $shape[0], $shape[1]);
        $report->forceFill(['created_at' => Carbon::parse('2026-06-20 09:00:00')])->saveQuietly();
        $payments = Payment::count();

        Log::spy();
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '測試'])
            ->assertStatus(422)->assertJsonPath('code', 'void_invoice_mismatch')->assertJsonPath('reason', $reason);

        Log::shouldHaveReceived('warning')->once()->with('[ReceiptMismatch]', \Mockery::on(
            fn (array $ctx) => $ctx['reason'] === $reason
                && $ctx['report_id'] === $report->id
                && $ctx['student_id'] === $student->id
                && $ctx['age_days'] === 10
                && array_keys($ctx) === ['reason', 'report_id', 'student_id', 'invoice_id', 'age_days']
        ));
        $this->assertSame('confirmed', $report->fresh()->status);
        $this->assertSame($payments, Payment::count(), 'refusal and telemetry write nothing');
    }

    public function test_successful_void_and_cross_campus_refusal_log_no_mismatch(): void
    {
        $student = $this->createStudent(1);
        $course = $this->createCountModeClass($student->id, ['Paid' => 1]);
        $bill = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-01', 'TotalAmount' => 500, 'PaidAmount' => 500, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 500, 'PaidAt' => '2026-04-10', 'Method' => 'cash']);
        $report = $this->confirmedReport($student, (int) $course->ID, $bill->id);

        Log::spy();
        $this->withHeaders($this->headers($this->createDirectorToken([2])))
            ->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '測試'])->assertForbidden();
        $this->withHeaders($this->headers($this->createDirectorToken([1])))
            ->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '測試'])->assertOk();
        Log::shouldNotHaveReceived('warning', ['[ReceiptMismatch]', \Mockery::any()]);
    }

    /** Invariant: an entry path ties report, bill and course to the one student, even with other open courses around. */
    public function test_director_record_and_confirm_keep_report_bill_and_course_on_one_student(): void
    {
        Carbon::setTestNow('2026-04-28 10:00:00');
        $h = $this->headers($this->createDirectorToken([1]));
        $student = $this->createStudent(1);
        $sibling = $this->createStudent(1);
        $chosen = $this->createCountModeClass($student->id, ['Charge' => 3000]);
        $notChosen = $this->createCountModeClass($student->id, ['Charge' => 3000]);
        $siblingCourse = $this->createCountModeClass($sibling->id, ['Charge' => 3000]);

        $reportId = (int) $this->withHeaders($h)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $chosen->ID, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 3000,
        ])->assertOk()->json('report_id');
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reportId}/confirm")->assertOk();

        $report = PaymentReport::findOrFail($reportId);
        $invoice = Invoice::findOrFail($report->InvoiceID);
        $this->assertSame('confirmed', $report->status);
        $this->assertSame($student->id, (int) $report->StudentID);
        $this->assertSame($student->id, (int) $invoice->StudentID);
        $this->assertSame((int) $chosen->ID, (int) $invoice->StudentClassID);
        $this->assertSame((int) $chosen->ID, (int) $report->StudentClassID);
        $this->assertSame(1, Payment::where('payment_report_id', $reportId)->where('InvoiceID', $invoice->id)->count());
        $this->assertSame(0, Payment::whereIn('InvoiceID', Invoice::whereIn('StudentClassID', [$notChosen->ID, $siblingCourse->ID])->pluck('id'))->count());
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function confirmedReport(Student $student, int $courseId, ?int $invoiceId): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $courseId, 'InvoiceID' => $invoiceId,
            'reported_by_name' => 'x', 'payment_date' => '2026-04-10', 'payment_method' => 'cash',
            'reported_amount' => 500, 'status' => 'confirmed', 'confirmed_by' => 1, 'confirmed_at' => Carbon::now(),
            'report_token_hash' => hash('sha256', uniqid('rm-', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director_' . uniqid() . '@test.com',
            'Name' => 'TestDirector',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '測試學生' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'Phone' => '0912345678',
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createCountModeClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 8800,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 5,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
