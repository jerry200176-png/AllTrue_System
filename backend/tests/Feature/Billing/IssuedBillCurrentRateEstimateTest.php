<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-app #408 (#4115) / #3980 root cause B. Founder Q2 = A: an issued bill does not silently follow a rate change.
 * The ledger only adds a read-only Rate × SessionCount estimate per count-mode contract; the bill amount is unchanged.
 */
class IssuedBillCurrentRateEstimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_mode_contract_carries_the_estimate_and_the_issued_bill_keeps_its_amount(): void
    {
        $token = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student, ['Rate' => 900, 'SessionCount' => 8, 'Charge' => 7200]);
        $invoiceId = $this->unpaidInvoice($course, 8800);

        $ledger = $this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)->assertOk();

        $row = collect($ledger->json('courses'))->firstWhere('id', (int) $course->ID);
        $this->assertSame(['rate' => 900, 'session_count' => 8, 'amount' => 7200], $row['current_rate_estimate']);
        $bill = collect($ledger->json('invoices'))->firstWhere('id', $invoiceId);
        $this->assertSame(8800, $bill['total_amount']);
        $this->assertSame(8800, (int) DB::table('Invoice')->where('id', $invoiceId)->value('TotalAmount'));
    }

    public function test_the_estimate_follows_the_course_rate_and_count(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student(), ['Rate' => 1250, 'SessionCount' => 10, 'Charge' => 12500]);

        $row = collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)
            ->assertOk()->json('courses'))->firstWhere('id', (int) $course->ID);

        $this->assertSame(['rate' => 1250, 'session_count' => 10, 'amount' => 12500], $row['current_rate_estimate']);
    }

    public function test_monthly_and_non_per_lesson_contracts_have_no_estimate(): void
    {
        $token = $this->directorToken();
        $student = $this->student();
        $monthly = $this->course($student, ['ScheduleMode' => 'date', 'Rate' => 900, 'SessionCount' => 8]);
        $hourly = $this->course($student, ['rate_unit' => 'hour', 'Rate' => 900, 'SessionCount' => 8]);

        $rows = collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_id=' . $student->id)
            ->assertOk()->json('courses'))->keyBy('id');

        $this->assertNull($rows[(int) $monthly->ID]['current_rate_estimate']);
        $this->assertNull($rows[(int) $hourly->ID]['current_rate_estimate']);
    }

    private function directorToken(): string
    {
        $user = User::create(['LoginName' => 'director_' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @param array<string, mixed> $over */
    private function course(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 7200, 'Paid' => 0,
            'Rate' => 900, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $over));
    }

    private function unpaidInvoice(StudentClass $course, int $amount): int
    {
        $id = (int) DB::table('Invoice')->insertGetId([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'billing_period' => '2026-09',
            'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-10', 'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid',
        ]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $id, 'StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => $amount]);

        return $id;
    }
}
