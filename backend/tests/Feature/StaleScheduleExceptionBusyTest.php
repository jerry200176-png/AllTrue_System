<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: GitHub #1296 / in-app #203
 *
 * schedules.status='scheduled' 例外 row（代課／調課目標）在對應 ClassSession
 * 已全部取消後，仍被 SubstituteService / ScheduleGuardService 計入老師佔用，
 * 造成代課挑選出現假「衝堂／已滿」、substitute endpoint 假 409/422。
 *
 * 不變式：ClassSession 是堂次 truth；同課程＋同日＋同起始時間的 ClassSession
 * 全數為 cancelled/leave 類時，該 scheduled 例外 row 不得佔用老師時段。
 * 反向保護：沒有 ClassSession 證據的 schedules row（如補課，R13）仍為真實佔用。
 */
class StaleScheduleExceptionBusyTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-04-25';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-20 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_stale_substitute_exception_row_is_not_busy_in_availability(): void
    {
        $s = $this->seedBase();
        $this->seedStaleExceptionRow($s, campusId: 1);

        $res = $this->withAuth($s['dirToken'])
            ->getJson("/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE);

        $res->assertOk();
        $slots = $res->json('busy_slots');
        foreach ($slots as $slot) {
            $this->assertFalse(
                $slot['start_time'] === '13:00',
                'Stale scheduled exception row (all ClassSessions cancelled) must not occupy 13:00'
            );
        }
    }

    public function test_stale_exception_row_does_not_block_substitute_endpoint(): void
    {
        $s = $this->seedBase();
        $this->seedStaleExceptionRow($s, campusId: 1);

        $res = $this->withAuth($s['dirToken'])->postJson(
            "/api/v1/class-sessions/{$s['activeSession']->id}/substitute",
            ['substitute_teacher_id' => $s['subId'], 'reason' => '正班老師請假']
        );

        $res->assertOk()->assertJsonFragment(['substitute_teacher_id' => $s['subId']]);
    }

    public function test_stale_exception_row_in_other_campus_does_not_trigger_cross_campus_422(): void
    {
        $s = $this->seedBase();
        $otherCampusId = 2;
        UserCampus::create([
            'CampusID' => $otherCampusId, 'UserID' => $s['subId'], 'Admin' => 0, 'Approved' => 1,
        ]);
        $this->seedStaleExceptionRow($s, campusId: $otherCampusId);

        $res = $this->withAuth($s['dirToken'])->postJson(
            "/api/v1/class-sessions/{$s['activeSession']->id}/substitute",
            ['substitute_teacher_id' => $s['subId'], 'reason' => '正班老師請假']
        );

        $res->assertOk()->assertJsonFragment(['cross_campus' => false]);
    }

    public function test_schedule_row_without_class_session_evidence_still_busy(): void
    {
        $s = $this->seedBase();

        // 補課（R13：可只寫 schedules 不建 ClassSession）→ 仍為真實佔用
        Schedule::create([
            'student_id' => $s['staleStudent']->id,
            'teacher_id' => $s['subId'],
            'subject' => '數學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'extra',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => self::DATE,
            'student_course_id' => $s['staleSc']->ID,
        ]);

        $res = $this->withAuth($s['dirToken'])
            ->getJson("/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE);

        $res->assertOk();
        $starts = array_column($res->json('busy_slots'), 'start_time');
        $this->assertContains('13:00', $starts, 'Makeup schedule without ClassSession must stay busy');
    }

    public function test_same_day_second_reschedule_leftover_scheduled_is_not_busy(): void
    {
        $s = $this->seedBase();
        $this->seedSecondRescheduleLeftover($s, campusId: 1);

        $res = $this->withAuth($s['dirToken'])
            ->getJson("/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE);

        $res->assertOk();
        $starts = array_column($res->json('busy_slots'), 'start_time');
        $this->assertNotContains(
            '17:00',
            $starts,
            'Leftover scheduled at the intermediate 17:00 slot must not occupy after ClassSession moved to 19:00'
        );
        $this->assertContains('19:00', $starts, 'Live ClassSession at 19:00 must remain busy');
    }

    public function test_same_day_leave_plus_makeup_schedule_still_busy(): void
    {
        $s = $this->seedBase();
        $s['staleSc']->TeacherID = $s['subId'];
        $s['staleSc']->save();
        ClassSession::create([
            'StudentClassID' => $s['staleSc']->ID,
            'SessionDate' => self::DATE,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'leave',
        ]);
        Schedule::create([
            'student_id' => $s['staleStudent']->id,
            'teacher_id' => $s['subId'],
            'subject' => '數學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'extra',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => self::DATE,
            'student_course_id' => $s['staleSc']->ID,
        ]);

        $res = $this->withAuth($s['dirToken'])
            ->getJson("/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE);

        $res->assertOk();
        $starts = array_column($res->json('busy_slots'), 'start_time');
        $this->assertNotContains('15:00', $starts, 'Leave ClassSession must free the original slot');
        $this->assertContains('17:00', $starts, 'Same-date makeup schedule without ClassSession must stay busy');
    }

    public function test_exception_row_with_active_class_session_still_busy(): void
    {
        $s = $this->seedBase();
        $this->seedStaleExceptionRow($s, campusId: 1, sessionStatus: 'scheduled');

        $res = $this->withAuth($s['dirToken'])
            ->getJson("/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE);

        $res->assertOk();
        $starts = array_column($res->json('busy_slots'), 'start_time');
        $this->assertContains('13:00', $starts, 'Exception row backed by an active ClassSession must stay busy');
    }

    // ───────────────────────────────────────────────────────────

    /**
     * 基本場景：主任、正班老師 A、代課候選 B、
     * 學生甲的 active 課程（老師 A，DATE 13:00–15:00，等待被代課）。
     *
     * @return array<string, mixed>
     */
    private function seedBase(): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-1296@example.com', 'Name' => '主任1296', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900001296', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'regular-1296@example.com', 'Name' => '正班1296', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900011296', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-1296@example.com', 'Name' => '代課1296', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900021296', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $activeStudent = Student::create([
            'name' => '學生甲1296', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $activeSc = $this->createStudentClass($activeStudent->id, $regular->id);
        $activeSession = ClassSession::create([
            'StudentClassID' => $activeSc->ID, 'SessionDate' => self::DATE,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $activeSc->ID, 'ClassSessionID' => $activeSession->id,
            'TeacherID' => $regular->id, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => self::DATE, 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);

        // 學生乙：stale 場景的載體（課程屬老師 A，之後由 B 代課、再被取消）
        $staleStudent = Student::create([
            'name' => '學生乙1296', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $staleSc = $this->createStudentClass($staleStudent->id, $regular->id);

        return [
            'dirToken' => $dirToken,
            'regularId' => (int) $regular->id,
            'subId' => (int) $sub->id,
            'activeSession' => $activeSession,
            'staleStudent' => $staleStudent,
            'staleSc' => $staleSc,
        ];
    }

    /**
     * 重演 production stale 形狀（schedules #6033/#6034 × ClassSession #24654）：
     * anchor rescheduled row（老師 A）＋ scheduled 例外 row（老師 B），
     * 而同 key 的 ClassSession 為 $sessionStatus（預設 cancelled）。
     */
    private function seedStaleExceptionRow(array $s, int $campusId, string $sessionStatus = 'cancelled'): void
    {
        $anchor = Schedule::create([
            'student_id' => $s['staleStudent']->id,
            'teacher_id' => $s['regularId'],
            'subject' => '化學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'regular',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => self::DATE,
            'student_course_id' => $s['staleSc']->ID,
        ]);
        Schedule::create([
            'student_id' => $s['staleStudent']->id,
            'teacher_id' => $s['subId'],
            'subject' => '化學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'regular',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => self::DATE,
            'student_course_id' => $s['staleSc']->ID,
            'original_schedule_id' => $anchor->id,
        ]);
        ClassSession::create([
            'StudentClassID' => $s['staleSc']->ID,
            'SessionDate' => self::DATE,
            'StartTime' => '13:00',
            'EndTime' => '15:00',
            'Status' => $sessionStatus,
        ]);
    }

    /**
     * Production in-app #238 shape (teacher 71 / 2026-08-20):
     * first move wrote scheduled@17:00, second move wrote rescheduled@17:00 + scheduled@19:00
     * and moved ClassSession to 19:00, leaving the intermediate scheduled row occupying 17:00.
     */
    private function seedSecondRescheduleLeftover(array $s, int $campusId): void
    {
        $s['staleSc']->TeacherID = $s['subId'];
        $s['staleSc']->save();

        Schedule::create([
            'student_id' => $s['staleStudent']->id,
            'teacher_id' => $s['subId'],
            'subject' => '數學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => self::DATE,
            'student_course_id' => $s['staleSc']->ID,
            'original_schedule_id' => null,
        ]);
        $secondFrom = Schedule::create([
            'student_id' => $s['staleStudent']->id,
            'teacher_id' => $s['subId'],
            'subject' => '數學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => self::DATE,
            'student_course_id' => $s['staleSc']->ID,
        ]);
        Schedule::create([
            'student_id' => $s['staleStudent']->id,
            'teacher_id' => $s['subId'],
            'subject' => '數學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '19:00',
            'end_time' => '21:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => self::DATE,
            'student_course_id' => $s['staleSc']->ID,
            'original_schedule_id' => $secondFrom->id,
        ]);
        ClassSession::create([
            'StudentClassID' => $s['staleSc']->ID,
            'SessionDate' => self::DATE,
            'StartTime' => '19:00',
            'EndTime' => '21:00',
            'Status' => 'scheduled',
        ]);
    }

    private function createStudentClass(int $studentId, int $teacherId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacherId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }
}
