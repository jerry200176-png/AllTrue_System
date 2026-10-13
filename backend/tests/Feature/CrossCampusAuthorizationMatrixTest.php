<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cross-campus authorization matrix (S1b, 2026-10-13 nine-area plan §8).
 *
 * For the three highest-risk controller families — billing, students, attendance — a director of
 * campus A asks for a resource that belongs to campus B by id and must be refused with the repo's
 * convention (403 Forbidden), without the resource changing. The same request by a director of the
 * resource's own campus must succeed, so each 403 proves the campus check, not a broken fixture.
 */
class CrossCampusAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campusA;

    private Campus $campusB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campusA = Campus::factory()->create();
        $this->campusB = Campus::factory()->create();
    }

    // ── Billing ────────────────────────────────────────────────────────────

    public function test_tuition_slip_is_campus_scoped(): void
    {
        $sc = $this->courseOn($this->campusB, ['Charge' => 3000, 'Paid' => 0]);
        $uri = "/api/v1/alerts/tuition-slip/{$sc->ID}";

        $this->asDirectorOf($this->campusA)->getJson($uri)->assertForbidden();
        $this->asDirectorOf($this->campusB)->getJson($uri)->assertOk();
    }

    public function test_waive_course_is_campus_scoped(): void
    {
        $sc = $this->courseOn($this->campusB, [
            'Charge' => 3000,
            'Paid' => 0,
            'Stop' => 1,
            'closed_reason' => 'settled_pending',
        ]);
        $uri = "/api/v1/accounting/courses/{$sc->ID}/waive";
        // No invoice yet, so the waivable amount the dialog confirms is the contract Charge.
        $payload = ['reason' => '跨分校授權測試', 'expected_amount' => 3000];

        $this->asDirectorOf($this->campusA)->postJson($uri, $payload)->assertForbidden();
        $this->assertSame('settled_pending', (string) $sc->fresh()->closed_reason);

        $this->asDirectorOf($this->campusB)->postJson($uri, $payload)->assertOk();
        $this->assertNotSame('settled_pending', (string) $sc->fresh()->closed_reason);
    }

    public function test_payment_report_receipt_is_campus_scoped(): void
    {
        $sc = $this->courseOn($this->campusB, ['Charge' => 3000, 'Paid' => 1]);
        $report = $this->paymentReportFor($sc, 'confirmed');
        $uri = "/api/v1/payment-reports/{$report->id}/receipt";

        $this->asDirectorOf($this->campusA)->getJson($uri)->assertForbidden();
        $this->asDirectorOf($this->campusB)->getJson($uri)->assertOk();
    }

    // ── Students ───────────────────────────────────────────────────────────

    public function test_student_show_is_campus_scoped(): void
    {
        $student = $this->studentOn($this->campusB);
        $uri = "/api/v1/students/{$student->id}";

        $this->asDirectorOf($this->campusA)->getJson($uri)->assertForbidden();
        $this->asDirectorOf($this->campusB)->getJson($uri)->assertOk();
    }

    public function test_student_class_show_is_campus_scoped(): void
    {
        $sc = $this->courseOn($this->campusB);
        $uri = "/api/v1/student-classes/{$sc->ID}";

        $this->asDirectorOf($this->campusA)->getJson($uri)->assertForbidden();
        $this->asDirectorOf($this->campusB)->getJson($uri)->assertOk();
    }

    public function test_student_class_invoices_is_campus_scoped(): void
    {
        $sc = $this->courseOn($this->campusB, ['Charge' => 3000]);
        $uri = "/api/v1/student-classes/{$sc->ID}/invoices";

        $this->asDirectorOf($this->campusA)->getJson($uri)->assertForbidden();
        $this->asDirectorOf($this->campusB)->getJson($uri)->assertOk();
    }

    // ── Attendance ─────────────────────────────────────────────────────────

    public function test_attendance_status_update_is_campus_scoped(): void
    {
        $student = $this->studentOn($this->campusB);
        $signin = StudentSignIn::create([
            'StudentID' => $student->id,
            'StudentClassID' => null,
            'ClassSessionID' => null,
            'Memo' => 'self_study',
            'SignInDT' => now()->toDateTimeString(),
            'Status' => 'present',
            'CampusID' => $this->campusB->id,
            'PersonType' => 'student',
            'SessionDeducted' => false,
            'MDT' => now(),
        ]);
        $uri = "/api/v1/attendance/{$signin->id}";

        $this->asDirectorOf($this->campusA)->patchJson($uri, ['status' => 'absent'])->assertForbidden();
        $this->assertSame('present', (string) $signin->fresh()->Status);

        $this->asDirectorOf($this->campusB)->patchJson($uri, ['status' => 'absent'])->assertOk();
        $this->assertSame('absent', (string) $signin->fresh()->Status);
    }

    public function test_class_session_recovery_is_campus_scoped(): void
    {
        $sessionId = $this->sessionOn($this->courseOn($this->campusB));
        $uri = "/api/v1/class-sessions/{$sessionId}/recovery";

        $this->asDirectorOf($this->campusA)->getJson($uri)->assertForbidden();
        $this->asDirectorOf($this->campusB)->getJson($uri)->assertOk();
    }

    public function test_class_session_reassign_contract_targets_is_campus_scoped(): void
    {
        $sessionId = $this->sessionOn($this->courseOn($this->campusB));
        $uri = "/api/v1/class-sessions/{$sessionId}/reassign-contract-targets";

        $this->asDirectorOf($this->campusA)->getJson($uri)->assertForbidden();
        $this->asDirectorOf($this->campusB)->getJson($uri)->assertOk();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function asDirectorOf(Campus $campus): static
    {
        $user = User::create([
            'LoginName' => 'dir-xcampus-' . uniqid() . '@test.com',
            'Name' => '主任跨分校測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function studentOn(Campus $campus): Student
    {
        return Student::create([
            'name' => '跨分校授權測試生-' . uniqid(),
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function courseOn(Campus $campus, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $this->studentOn($campus)->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function sessionOn(StudentClass $sc): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2099-01-05',
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
        ]);
    }

    private function paymentReportFor(StudentClass $sc, string $status): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $sc->StudentID,
            'StudentClassID' => $sc->ID,
            'reported_by_name' => '家長',
            'payment_date' => '2026-04-28',
            'payment_method' => 'cash',
            'reported_amount' => 3000,
            'status' => $status,
            'report_token_hash' => hash('sha256', 'xcampus-' . uniqid()),
            'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }
}
