<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Founder GO 2026-11-15: a read-only triage token can read the 正職薪資要件 report of one campus and month, IDs and numbers only. CI only. */
final class TriageFulltimePayrollTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/triage/fulltime-payroll?month=2026-08&branch_id=1';

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['LoginName' => 'payroll-triage-admin@test.com', 'Name' => 'Triage Admin', 'PSW' => 'secret', 'type' => 'S', 'phone' => '0910000901']);
        $this->token = (string) $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'ci'], ['X-User-Id' => (string) $this->admin->id, 'Accept' => 'application/json'])->assertCreated()->json('token');
    }

    public function test_report_is_the_pages_numbers_without_names_or_free_text(): void
    {
        $teacher = User::create(['LoginName' => 'payroll-triage-teacher@test.com', 'Name' => 'Amy', 'PSW' => 'secret', 'type' => 'T', 'phone' => '0922000901', 'MustChangePassword' => false, 'employment_type' => 'full_time']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $student = Student::create(['name' => '報表學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacher->id, 'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'one_on_three', 'by1' => 1,
            'ScheduleMode' => 'count', 'SessionCount' => 20, 'RemainingSessions' => 20, 'Rate' => 500, 'Charge' => 5000, 'Paid' => 0, 'Period' => 4,
            'SessionDuration' => 240, 'TotalHours' => 40, 'StartDate' => '2026-08-01', 'EndDate' => '2026-08-31', 'RoomID' => '', 'MDate' => now(),
        ]);
        $session = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-05', 'StartTime' => '10:00:00', 'EndTime' => '14:00:00', 'Status' => 'completed', 'Note' => '']);
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => $teacher->id, 'ClassSessionID' => $session->id, 'Status' => 'present', 'SignInDT' => '2026-08-05 10:00:00', 'SignOutDT' => '2026-08-05 14:00:00']);
        DB::table('teacher_payroll_cash_adjustments')->insert(['teacher_id' => $teacher->id, 'branch_id' => 1, 'amount' => 1500, 'reason' => 'bonus for Amy', 'status' => 'approved', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->getJson(self::URL, $this->bearer())->assertOk();

        $response->assertJsonPath('teachers.0.teacher_id', $teacher->id);
        $response->assertJsonPath('teachers.0.weekly.0.metrics.week_start', '2026-08-03');
        $response->assertJsonPath('teachers.0.weekly.0.metrics.daily_hours', ['2026-08-05' => 4]);
        $response->assertJsonPath('teachers.0.components.cash_adjustments.amount', 1500);
        $response->assertJsonPath('lock.status', 'draft');
        // No name and no typed text anywhere, whatever script it is written in. The teacher's name is one plain
        // word on purpose: only the drop-by-key rule can remove it.
        $body = (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        foreach (['Amy', 'teacher_name', 'label', 'reason', '報表', '老師'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
        $this->assertSame(1, SecurityAuditEvent::query()->where('event_type', 'triage_token.read')->where('outcome', 'success')->count());
    }

    public function test_needs_a_triage_token_a_campus_and_a_month_and_takes_no_writes(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson(self::URL, ['X-User-Id' => (string) $this->admin->id, 'Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson('/api/v1/triage/fulltime-payroll?month=2026-08', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/fulltime-payroll?month=2026-13&branch_id=1', $this->bearer())->assertStatus(422);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, self::URL, [], $this->bearer())->assertStatus(405);
        }
        // The token still reaches nothing else of the payroll page.
        $this->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1', $this->bearer())->assertForbidden();
        $this->postJson('/api/v1/finance/teacher-eligibility/lock', ['month' => '2026-08', 'branch_id' => 1], $this->bearer())->assertForbidden();
    }

    public function test_switch_off_answers_404(): void
    {
        config(['triage_tokens.fulltime_payroll_enabled' => false]);
        $this->getJson(self::URL, $this->bearer())->assertNotFound();
    }

    private function bearer(): array
    {
        return ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
    }
}
