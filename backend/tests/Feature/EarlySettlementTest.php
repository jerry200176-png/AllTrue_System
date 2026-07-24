<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EarlySettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_settle_unpaid_count_mode_course(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken([1]);
            $student = $this->createStudent();
            $course = $this->createStudentClass($student->id, [
                'Paid' => 0,
                'Charge' => 8000,
                'Rate' => 1000,
                'SessionCount' => 8,
                'RemainingSessions' => 5,
                'UsedSessions' => 3,
                'ScheduleMode' => 'count',
            ]);
            $this->createClassSession((int) $course->ID, '2026-07-01', 'attended');
            $this->createClassSession((int) $course->ID, '2026-07-03', 'late');
            $this->createClassSession((int) $course->ID, '2026-07-05', 'absent');
            $this->createClassSession((int) $course->ID, '2026-07-08', 'leave');
            $futureId = $this->createClassSession((int) $course->ID, '2026-07-20', 'scheduled');

            $invoice = Invoice::create([
                'StudentID' => $student->id,
                'StudentClassID' => $course->ID,
                'IssueDate' => '2026-07-01',
                'TotalAmount' => 8000,
                'PaidAmount' => 0,
                'Status' => 'unpaid',
            ]);

            $this->getJson(
                "/api/v1/student-classes/{$course->ID}/early-settle/preview",
                ['Authorization' => "Bearer {$token}"]
            )->assertOk()
                ->assertJsonPath('billable_sessions', 2)
                ->assertJsonPath('rate', 1000)
                ->assertJsonPath('computed_amount', 2000)
                ->assertJsonPath('outstanding', 2000);

            $res = $this->postJson(
                "/api/v1/student-classes/{$course->ID}/early-settle",
                [],
                ['Authorization' => "Bearer {$token}"]
            )->assertOk();

            $this->assertSame(2000, (int) $res->json('final_amount'));
            $this->assertNotNull($res->json('settlement_invoice_id'));

            $course->refresh();
            $this->assertSame(1, (int) $course->Stop);
            $this->assertSame('usage_settled', $course->closed_reason);
            $this->assertNotNull($course->settlement_locked_at);
            $this->assertSame(2000, (int) $course->Charge);
            $this->assertSame(0, (int) $course->RemainingSessions);
            $this->assertSame(2, (int) $course->UsedSessions);
            $this->assertSame(0, (int) $course->Paid);

            $this->assertSame('void', Invoice::find($invoice->id)->Status);
            $settlement = Invoice::find($res->json('settlement_invoice_id'));
            $this->assertSame(2000, (int) $settlement->TotalAmount);
            $this->assertSame('unpaid', $settlement->Status);

            $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $futureId)->value('Status'));

            // Attendance lock
            $attendedId = DB::table('ClassSession')
                ->where('StudentClassID', $course->ID)
                ->where('Status', 'attended')
                ->value('id');
            $this->patchJson(
                "/api/v1/class-sessions/{$attendedId}",
                ['status' => 'leave', 'reason' => 'test'],
                ['Authorization' => "Bearer {$token}"]
            )->assertStatus(409);

            // Resume blocked
            $this->postJson(
                "/api/v1/student-classes/{$course->ID}/pause",
                ['action' => 'resume'],
                ['Authorization' => "Bearer {$token}"]
            )->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_settle_with_amount_adjustment_requires_reason(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'Charge' => 5000,
            'Rate' => 1000,
            'SessionCount' => 5,
            'RemainingSessions' => 3,
            'UsedSessions' => 2,
        ]);
        $this->createClassSession((int) $course->ID, '2026-07-01', 'attended');
        $this->createClassSession((int) $course->ID, '2026-07-03', 'attended');

        $this->postJson(
            "/api/v1/student-classes/{$course->ID}/early-settle",
            ['amount' => 1500],
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(422);

        $this->postJson(
            "/api/v1/student-classes/{$course->ID}/early-settle",
            ['amount' => 1500, 'adjustment_reason' => '家長協商減免'],
            ['Authorization' => "Bearer {$token}"]
        )->assertOk()
            ->assertJsonPath('final_amount', 1500);
    }

    public function test_fully_paid_course_not_eligible(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'Charge' => 2000,
            'Rate' => 1000,
            'PayDate' => '2026-07-01',
        ]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-07-01',
            'TotalAmount' => 2000,
            'PaidAmount' => 2000,
            'Status' => 'paid',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 2000,
            'Method' => 'cash',
            'PaidAt' => '2026-07-01',
        ]);

        $this->getJson(
            "/api/v1/student-classes/{$course->ID}/early-settle/preview",
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(422);
    }

    public function test_monthly_mode_uses_billable_times_rate(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'Charge' => 0,
            'Rate' => 1200,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'UsedSessions' => 0,
        ]);
        $this->createClassSession((int) $course->ID, '2026-07-01', 'attended');
        $this->createClassSession((int) $course->ID, '2026-07-08', 'attended');
        $this->createClassSession((int) $course->ID, '2026-07-15', 'attended');

        $this->postJson(
            "/api/v1/student-classes/{$course->ID}/early-settle",
            [],
            ['Authorization' => "Bearer {$token}"]
        )->assertOk()
            ->assertJsonPath('final_amount', 3600)
            ->assertJsonPath('preview.billable_sessions', 3);
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'early-settle-' . uniqid() . '@example.com',
            'Name' => '提前結清測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '提前結清測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test School',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createClassSession(int $courseId, string $date, string $status): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '10:00',
            'EndTime' => '11:00',
            'Status' => $status,
        ]);
    }
}
