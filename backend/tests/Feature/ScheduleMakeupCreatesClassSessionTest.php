<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-A regression: POST /api/v1/schedules with status=rescheduled or type=extra
 * must create a corresponding ClassSession for that date.
 *
 * Previously, only a schedules row was inserted, leaving attendance and evaluation
 * pages unable to find the makeup session (they depend on ClassSession exclusively).
 */
class ScheduleMakeupCreatesClassSessionTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS_ID = 1;
    private const DATE = '2026-05-10';

    private string $token;
    private int $teacherId;
    private StudentClass $sc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token    = $this->createDirectorToken(self::CAMPUS_ID);
        $this->teacherId = $this->createTeacher(self::CAMPUS_ID);
        $this->sc       = $this->createStudentClass(self::CAMPUS_ID);
    }

    // ── AC-001-a: type=extra (補課目標日) → ClassSession created ──────────────

    public function test_extra_type_schedule_with_rescheduled_status_creates_class_session(): void
    {
        // type=extra marks the *destination* makeup date — ClassSession must be created.
        $this->postSchedule([
            'type'              => 'extra',
            'status'            => 'rescheduled',
            'schedule_date'     => self::DATE,
            'student_course_id' => $this->sc->ID,
        ])->assertCreated();

        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $this->sc->ID,
            'SessionDate'    => self::DATE,
            'Status'         => 'scheduled',
        ]);
    }

    // ── AC-001-a (variant): type=extra → ClassSession created ──────────────────

    public function test_extra_type_schedule_creates_class_session(): void
    {
        $this->postSchedule([
            'type'              => 'extra',
            'status'            => 'scheduled',
            'schedule_date'     => self::DATE,
            'student_course_id' => $this->sc->ID,
        ])->assertCreated();

        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $this->sc->ID,
            'SessionDate'    => self::DATE,
            'Status'         => 'scheduled',
        ]);
    }

    // ── AC-001-b: type=normal → ClassSession NOT created ───────────────────────

    public function test_normal_schedule_does_not_create_class_session(): void
    {
        $this->postSchedule([
            'status'            => 'rescheduled',
            'type'              => 'normal',
            'schedule_date'     => self::DATE,
            'student_course_id' => $this->sc->ID,
        ])->assertCreated();

        // type=normal + status=rescheduled is the "original leave day" marker,
        // not a makeup day — no ClassSession should be created.
        $this->assertDatabaseCount('ClassSession', 0);
    }

    public function test_normal_scheduled_exception_creates_class_session(): void
    {
        $this->postSchedule([
            'status'            => 'scheduled',
            'type'              => 'normal',
            'schedule_date'     => self::DATE,
            'student_course_id' => $this->sc->ID,
        ])->assertCreated();

        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $this->sc->ID,
            'SessionDate'    => self::DATE,
            'Status'         => 'scheduled',
        ]);
    }

    // ── FR-002: idempotent — double POST does not duplicate ClassSession ────────

    public function test_duplicate_post_does_not_create_duplicate_class_session(): void
    {
        $payload = [
            'type'              => 'extra',
            'status'            => 'scheduled',
            'schedule_date'     => self::DATE,
            'student_course_id' => $this->sc->ID,
        ];

        $this->postSchedule($payload)->assertCreated();
        $this->postSchedule($payload)->assertCreated();

        $this->assertDatabaseCount('ClassSession', 1);
    }

    // ── AC-002-a: ClassSession created is visible via class-sessions API ────────

    public function test_created_class_session_visible_via_api(): void
    {
        $this->postSchedule([
            'status'            => 'rescheduled',
            'schedule_date'     => self::DATE,
            'student_course_id' => $this->sc->ID,
        ])->assertCreated();

        $resp = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson('/api/v1/class-sessions?start=' . self::DATE . '&end=' . self::DATE . '&branch_id=' . self::CAMPUS_ID);

        $resp->assertOk();
        $ids = collect($resp->json('data') ?? [])->pluck('id')->all();
        $sessionId = ClassSession::where('StudentClassID', $this->sc->ID)
            ->whereDate('SessionDate', self::DATE)
            ->value('id');
        $this->assertContains((int) $sessionId, array_map('intval', $ids));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function postSchedule(array $overrides = [])
    {
        $defaults = [
            'student_id'    => (int) $this->sc->StudentID,
            'teacher_id'    => $this->teacherId,
            'subject'       => 'Math',
            'day_of_week'   => 6,
            'start_time'    => '14:00',
            'end_time'      => '16:00',
            'duration_hours' => 2,
            'class_type'    => 'one_on_one',
            'status'        => 'rescheduled',
            'type'          => 'extra',
            'deduction'     => 0,
            'branch_id'     => self::CAMPUS_ID,
            'schedule_date' => self::DATE,
        ];

        return $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules', array_merge($defaults, $overrides));
    }

    private function createDirectorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName'          => 'dir-makeup@example.com',
            'Name'               => '主任補課測試',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0912000001',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID'   => $user->id,
            'Admin'    => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createTeacher(int $campusId): int
    {
        $teacher = User::create([
            'LoginName'          => 'teacher-makeup@example.com',
            'Name'               => '楊智超測試',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => '0922000002',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID'   => $teacher->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    private function createStudentClass(int $campusId): StudentClass
    {
        $student = Student::create([
            'name'          => '薛米亞測試',
            'CampusID'      => $campusId,
            'ClassID'       => 1,
            'enable'        => 1,
            'MDT'           => now(),
            'Notify_Token'  => '',
        ]);

        return StudentClass::create([
            'StudentID'         => $student->id,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => $this->teacherId,
            'ClassType'         => 'one_on_one',
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => '2026-04-01',
            'TotalHours'        => 8,
            'SessionCount'      => 4,
            'SessionDuration'   => 120,
            'RemainingSessions' => 4,
            'UsedSessions'      => 0,
            'Charge'            => 800,
            'Pay'               => 3200,
            'Paid'              => 0,
            'Rate'              => 800,
            'Stop'              => 0,
            'MDT'               => now(),
        ]);
    }
}
