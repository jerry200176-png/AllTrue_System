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
 * Regression: in-app #203 — 同學生續約雙軌假衝堂
 *
 * 學生甲課程 A 已取消、課程 B（續約）已有代課 scheduled 例外佔用老師 T。
 * 主任再為學生甲指派代課老師 T 時，availability／substitute guard 不得把
 * 「同一學生」既有佔用算成衝堂。
 */
class SameStudentExcludeBusyTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-18';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-17 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_availability_excludes_same_student_renewal_slot(): void
    {
        $s = $this->seedScenario();

        $without = $this->withAuth($s['dirToken'])
            ->getJson("/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE);
        $without->assertOk();
        $this->assertContains('13:00', array_column($without->json('busy_slots'), 'start_time'));

        $with = $this->withAuth($s['dirToken'])
            ->getJson(
                "/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE
                . '&exclude_student_id=' . $s['studentId']
            );
        $with->assertOk();
        foreach ($with->json('busy_slots') as $slot) {
            $this->assertFalse(
                $slot['start_time'] === '13:00',
                'Same-student renewal occupancy must not mark substitute candidate busy at 13:00'
            );
        }
    }

    public function test_substitute_succeeds_when_only_conflict_is_same_student_renewal(): void
    {
        $s = $this->seedScenario();

        $res = $this->withAuth($s['dirToken'])->postJson(
            "/api/v1/class-sessions/{$s['activeSession']->id}/substitute",
            ['substitute_teacher_id' => $s['subId'], 'reason' => '續約雙軌代課']
        );

        $res->assertOk()->assertJsonFragment(['substitute_teacher_id' => $s['subId']]);
    }

    public function test_other_student_occupancy_still_blocks(): void
    {
        $s = $this->seedScenario();

        $other = Student::create([
            'name' => '其他學生203', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $otherSc = $this->createStudentClass($other->id, $s['regularId']);
        ClassSession::create([
            'StudentClassID' => $otherSc->ID, 'SessionDate' => self::DATE,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        Schedule::create([
            'student_id' => $other->id,
            'teacher_id' => $s['subId'],
            'subject' => '數學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'regular',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => self::DATE,
            'student_course_id' => $otherSc->ID,
            'original_schedule_id' => 1,
        ]);

        $with = $this->withAuth($s['dirToken'])
            ->getJson(
                "/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE
                . '&exclude_student_id=' . $s['studentId']
            );
        $with->assertOk();
        $this->assertContains(
            '13:00',
            array_column($with->json('busy_slots'), 'start_time'),
            'Other student occupancy must remain busy even with exclude_student_id'
        );
    }

    /** @return array<string, mixed> */
    private function seedScenario(): array
    {
        $campusId = 1;
        $director = User::create([
            'LoginName' => 'dir-203@example.com', 'Name' => '主任203', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900000203', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'regular-203@example.com', 'Name' => '正班203', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900010203', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-203@example.com', 'Name' => '代課203', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900020203', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '沈宇璿203', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        // 舊約（已取消堂次）
        $oldSc = $this->createStudentClass($student->id, $regular->id);
        ClassSession::create([
            'StudentClassID' => $oldSc->ID, 'SessionDate' => self::DATE,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'cancelled',
        ]);
        $oldAnchor = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $regular->id, 'subject' => '化學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00', 'end_time' => '15:00', 'duration_hours' => 2,
            'class_type' => 'one_on_one', 'status' => 'rescheduled', 'type' => 'regular',
            'deduction' => 1, 'branch_id' => $campusId, 'schedule_date' => self::DATE,
            'student_course_id' => $oldSc->ID,
        ]);
        Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $sub->id, 'subject' => '化學',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00', 'end_time' => '15:00', 'duration_hours' => 2,
            'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'regular',
            'deduction' => 1, 'branch_id' => $campusId, 'schedule_date' => self::DATE,
            'student_course_id' => $oldSc->ID, 'original_schedule_id' => $oldAnchor->id,
        ]);

        // 續約（真實佔用代課老師）
        $renewSc = $this->createStudentClass($student->id, $regular->id);
        ClassSession::create([
            'StudentClassID' => $renewSc->ID, 'SessionDate' => self::DATE,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        $renewAnchor = Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $regular->id, 'subject' => 'Chemistry',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00', 'end_time' => '15:00', 'duration_hours' => 2,
            'class_type' => 'one_on_one', 'status' => 'rescheduled', 'type' => 'regular',
            'deduction' => 1, 'branch_id' => $campusId, 'schedule_date' => self::DATE,
            'student_course_id' => $renewSc->ID,
        ]);
        Schedule::create([
            'student_id' => $student->id, 'teacher_id' => $sub->id, 'subject' => 'Chemistry',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '13:00', 'end_time' => '15:00', 'duration_hours' => 2,
            'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'regular',
            'deduction' => 1, 'branch_id' => $campusId, 'schedule_date' => self::DATE,
            'student_course_id' => $renewSc->ID, 'original_schedule_id' => $renewAnchor->id,
        ]);

        // 待代課堂次（正班老師，同學生同日同時段 — 例如舊約仍顯示的操作入口）
        $activeSc = $this->createStudentClass($student->id, $regular->id);
        // This fixture intentionally models the legacy substitute/renewal
        // dual-track exception; normal future writes are overlap-guarded.
        $activeSession = ClassSession::withoutEvents(fn () => ClassSession::create([
            'StudentClassID' => $activeSc->ID, 'SessionDate' => self::DATE,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]));
        LearningRecord::create([
            'StudentClassID' => $activeSc->ID, 'ClassSessionID' => $activeSession->id,
            'TeacherID' => $regular->id, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => self::DATE, 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);

        return [
            'dirToken' => $dirToken,
            'regularId' => (int) $regular->id,
            'subId' => (int) $sub->id,
            'studentId' => (int) $student->id,
            'activeSession' => $activeSession,
        ];
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
