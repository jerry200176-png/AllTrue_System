<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRD-E (2026-04-18, plan 8c1673b9) — missing session regression.
 *
 * Background: 主任 reported that 鄭翔祐 × 吳艾潼 的 4/19 15:00-17:00 堂次未顯示於
 * SmartCalendar。Root cause was purely frontend: SmartCalendar suppressed ALL base
 * weekly-pattern cells on a date whenever any schedules row with status='scheduled'
 * existed for that course on that date — losing the co-existing weekly slot for
 * multi-day courses (e.g. week=3/time=16:00 + week1=7/time1=15:00).
 *
 * This backend contract test guards the server side invariants that the frontend fix relies on:
 *   1. /api/v1/class-sessions returns every ClassSession on the queried date (including
 *      the "quiet" 15:00 slot) even when another slot on the same date is linked to a
 *      schedules exception.
 *   2. /api/v1/schedules returns the scheduled-to exception for the same date.
 *   3. StudentClass API exposes the multi-day pattern via day_time_slots so the
 *      frontend can reconstruct base cells per day.
 */
class MultiSlotCourseWithExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_sessions_api_returns_both_slots_when_one_slot_has_scheduled_exception(): void
    {
        $campusId = 16;
        $token = $this->createDirectorToken([$campusId], 'director-prd-e@example.com');
        $teacherId = $this->createTeacher($campusId, 'teacher-prd-e@example.com', '鄭翔祐測試');
        $student = $this->createStudent($campusId, '吳艾潼測試');

        // Multi-slot weekly pattern: Wed 16:00-18:00 (week/time) + Sun 15:00-17:00 (week1/time1)
        $course = StudentClass::create([
            'StudentID'      => $student->id,
            'TeacherID'      => $teacherId,
            'GradeID'        => 11,
            'SubjectID'      => 68,
            'ClassType'      => 'one_on_two',
            'by1'            => 2,
            'ScheduleMode'   => 'date',
            'SessionCount'   => 0,
            'RemainingSessions' => 0,
            'UsedSessions'   => 0,
            'Rate'           => 1650,
            'Charge'         => 13200,
            'Pay'            => 0,
            'Paid'           => 0,
            'Period'         => 4,
            'SessionDuration'=> 120,
            'TotalHours'     => 16,
            'StartDate'      => '2026-04-01',
            'EndDate'        => '2026-04-29',
            'week'           => 3,
            'time'           => '16:00:00',
            'week1'          => 7,
            'time1'          => '15:00:00',
            'duration1'      => 120,
            'RoomID'         => '1',
            'Stop'           => 0,
            'MDate'          => now(),
        ]);

        // CS#3070-equiv: Sun 15:00-17:00 (matches weekly pattern slot#1)
        $sundayQuiet = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate'    => '2026-04-19',
            'StartTime'      => '15:00:00',
            'EndTime'        => '17:00:00',
            'Status'         => 'scheduled',
            'Note'           => '',
        ]);

        // CS#3071-equiv: Sun 17:00-19:00 (rescheduled from Wed 4/22 16:00) + schedules exception pair
        $sundayExtra = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate'    => '2026-04-19',
            'StartTime'      => '17:00:00',
            'EndTime'        => '19:00:00',
            'Status'         => 'scheduled',
            'Note'           => '',
        ]);

        $rescheduledFromId = DB::table('schedules')->insertGetId([
            'student_id'           => $student->id,
            'teacher_id'           => $teacherId,
            'subject'              => 'Chemistry',
            'day_of_week'          => 3,
            'start_time'           => '16:00',
            'end_time'             => '18:00',
            'duration_hours'       => 2,
            'class_type'           => 'one_on_two',
            'status'               => 'rescheduled',
            'type'                 => 'normal',
            'deduction'            => 0,
            'branch_id'            => $campusId,
            'schedule_date'        => '2026-04-22',
            'student_course_id'    => $course->ID,
            'original_schedule_id' => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::table('schedules')->insert([
            'student_id'           => $student->id,
            'teacher_id'           => $teacherId,
            'subject'              => 'Chemistry',
            'day_of_week'          => 7,
            'start_time'           => '17:00',
            'end_time'             => '19:00',
            'duration_hours'       => 2,
            'class_type'           => 'one_on_two',
            'status'               => 'scheduled',
            'type'                 => 'normal',
            'deduction'            => 1,
            'branch_id'            => $campusId,
            'schedule_date'        => '2026-04-19',
            'student_course_id'    => $course->ID,
            'original_schedule_id' => $rescheduledFromId,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // 1. /api/v1/class-sessions must surface BOTH sessions on 4/19
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/class-sessions?branch_id=' . $campusId . '&start=2026-04-19&end=2026-04-19&per_page=200');
        $res->assertOk();
        $rows = collect($res->json('data') ?? []);
        $quietHit = $rows->firstWhere('id', $sundayQuiet->id);
        $extraHit = $rows->firstWhere('id', $sundayExtra->id);
        $this->assertNotNull($quietHit, 'CS 15:00-17:00 session must appear for SmartCalendar to render it');
        $this->assertNotNull($extraHit, 'CS 17:00-19:00 session must appear alongside the quiet slot');
        $this->assertSame('15:00', $quietHit['start_time']);
        $this->assertSame('17:00', $extraHit['start_time']);

        // 2. /api/v1/schedules exception for the 17:00 slot is loaded for exceptions.value
        $exRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/schedules?branch_id=' . $campusId . '&start=2026-04-19&end=2026-04-19&per_page=200');
        $exRes->assertOk();
        $exRows = $exRes->json();
        $exList = is_array($exRows) && isset($exRows['data']) ? $exRows['data'] : (is_array($exRows) ? $exRows : []);
        $scheduledExc = collect($exList)->first(static fn ($r) =>
            (int) ($r['student_course_id'] ?? 0) === (int) $course->ID
            && ($r['status'] ?? '') === 'scheduled'
            && substr((string) ($r['schedule_date'] ?? ''), 0, 10) === '2026-04-19'
            && substr((string) ($r['start_time'] ?? ''), 0, 5) === '17:00'
        );
        $this->assertNotNull($scheduledExc, 'Schedules exception at 17:00 must be returned by /schedules');

        // 3. /api/v1/student-classes exposes both slots so the frontend can compute base cells
        $scRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=' . $campusId . '&per_page=500');
        $scRes->assertOk();
        $scRows = collect($scRes->json('data') ?? []);
        $scHit = $scRows->firstWhere('id', $course->ID);
        $this->assertNotNull($scHit);
        $days = array_values(array_unique(array_map('intval', $scHit['days_of_week'] ?? [])));
        sort($days);
        $this->assertSame([3, 7], $days, 'days_of_week must include both weekdays');
        $slots = collect($scHit['day_time_slots'] ?? []);
        $this->assertTrue($slots->contains(static fn ($s) => (int) ($s['day'] ?? 0) === 7 && substr((string) ($s['start_time'] ?? ''), 0, 5) === '15:00'));
        $this->assertTrue($slots->contains(static fn ($s) => (int) ($s['day'] ?? 0) === 3 && substr((string) ($s['start_time'] ?? ''), 0, 5) === '16:00'));
    }

    // ───────────── Helpers ─────────────

    /**
     * @param array<int> $campusIds
     */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName'          => $loginName,
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0911000000',
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function createTeacher(int $campusId, string $loginName, string $name = '老師'): int
    {
        $t = User::create([
            'LoginName'          => $loginName,
            'Name'               => $name,
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => '0922111111',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID'   => $t->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);
        return (int) $t->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name'         => $name,
            'CampusID'     => $campusId,
            'ClassID'      => 11,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }
}
