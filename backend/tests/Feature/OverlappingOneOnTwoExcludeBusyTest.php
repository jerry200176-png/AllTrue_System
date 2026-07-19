<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: in-app #205 — 1v2 重疊時段假衝堂
 *
 * 老師 T 已有學生 B 的 1v2（18:00–20:00），且學生 A 本堂（17:30–19:30）
 * 已有一筆 teacher=T 的 scheduled 例外。主任為學生 A 挑選 T 時，
 * 若不帶 exclude_student_id=A，重疊區間 student_count=2 → remaining=0 → 假已滿。
 * 帶 exclude 後只剩學生 B → remaining=1，應可選。
 */
class OverlappingOneOnTwoExcludeBusyTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overlapping_one_on_two_without_exclude_looks_full(): void
    {
        $s = $this->seedScenario();

        $res = $this->withAuth($s['dirToken'])
            ->getJson("/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE);

        $res->assertOk();
        $slots = collect($res->json('busy_slots'));
        $self = $slots->first(fn ($slot) => $slot['start_time'] === '17:30');
        $peer = $slots->first(fn ($slot) => $slot['start_time'] === '18:00');
        $this->assertNotNull($self);
        $this->assertNotNull($peer);
        $this->assertSame(0, (int) $self['remaining_capacity']);
        $this->assertSame(0, (int) $peer['remaining_capacity']);
    }

    public function test_overlapping_one_on_two_with_exclude_keeps_remaining_capacity(): void
    {
        $s = $this->seedScenario();

        $res = $this->withAuth($s['dirToken'])
            ->getJson(
                "/api/v1/teachers/{$s['subId']}/availability?date=" . self::DATE
                . '&exclude_student_id=' . $s['studentAId']
            );

        $res->assertOk();
        $slots = collect($res->json('busy_slots'));
        $this->assertNull(
            $slots->first(fn ($slot) => $slot['start_time'] === '17:30'),
            'Student A own occupancy must be excluded'
        );
        $peer = $slots->first(fn ($slot) => $slot['start_time'] === '18:00');
        $this->assertNotNull($peer);
        $this->assertSame('one_on_two', $peer['class_type']);
        $this->assertSame(
            1,
            (int) $peer['remaining_capacity'],
            'Peer 1v2 with one student must leave remaining_capacity=1 after exclude'
        );
    }

    /** @return array<string, mixed> */
    private function seedScenario(): array
    {
        $campusId = 1;
        $director = User::create([
            'LoginName' => 'dir-205@example.com', 'Name' => '主任205', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900000205', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'regular-205@example.com', 'Name' => '正班205', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900010205', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-205@example.com', 'Name' => '代課205', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900020205', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $studentA = Student::create([
            'name' => '學生A205', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $studentB = Student::create([
            'name' => '學生B205', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $scA = $this->createStudentClass($studentA->id, $regular->id, 'one_on_two');
        ClassSession::create([
            'StudentClassID' => $scA->ID, 'SessionDate' => self::DATE,
            'StartTime' => '17:30:00', 'EndTime' => '19:30:00', 'Status' => 'scheduled',
        ]);
        // Existing scheduled occupancy under substitute candidate (same student A).
        Schedule::create([
            'student_id' => $studentA->id,
            'teacher_id' => $sub->id,
            'subject' => '理化',
            'day_of_week' => Carbon::parse(self::DATE)->dayOfWeekIso,
            'start_time' => '17:30',
            'end_time' => '19:30',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'scheduled',
            'type' => 'regular',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => self::DATE,
            'student_course_id' => $scA->ID,
            'original_schedule_id' => null,
        ]);

        $scB = $this->createStudentClass($studentB->id, $sub->id, 'one_on_two');
        ClassSession::create([
            'StudentClassID' => $scB->ID, 'SessionDate' => self::DATE,
            'StartTime' => '18:00:00', 'EndTime' => '20:00:00', 'Status' => 'attended',
        ]);

        return [
            'dirToken' => $dirToken,
            'subId' => $sub->id,
            'studentAId' => $studentA->id,
        ];
    }

    private function createStudentClass(int $studentId, int $teacherId, string $classType): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'ClassType' => $classType,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 8,
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Charge' => 800,
            'Pay' => 3200,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
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
