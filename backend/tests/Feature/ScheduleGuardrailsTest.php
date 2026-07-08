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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_on_two_third_student_is_blocked_on_same_slot(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-a@example.com');

        $studentA = $this->createStudent(1, '學生A');
        $studentB = $this->createStudent(1, '學生B');
        $studentC = $this->createStudent(1, '學生C');

        $this->createCourseViaApi($token, $studentA->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '16:00',
            'first_class_date' => '2026-03-30',
            'days_of_week' => [1],
        ])->assertCreated();

        $this->createCourseViaApi($token, $studentB->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '16:00',
            'first_class_date' => '2026-03-30',
            'days_of_week' => [1],
        ])->assertCreated();

        $this->createCourseViaApi($token, $studentC->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '16:00',
            'first_class_date' => '2026-03-30',
            'days_of_week' => [1],
        ])->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity');
    }

    public function test_existing_one_on_one_blocks_new_one_on_two_on_same_slot(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-b@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-b@example.com');

        $studentA = $this->createStudent(1, '學生D');
        $studentB = $this->createStudent(1, '學生E');

        $this->createCourseViaApi($token, $studentA->id, $teacherId, [
            'class_type' => 'one_on_one',
            'start_time' => '17:00',
            'first_class_date' => '2026-03-31',
            'days_of_week' => [2],
        ])->assertCreated();

        $this->createCourseViaApi($token, $studentB->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '17:00',
            'first_class_date' => '2026-03-31',
            'days_of_week' => [2],
        ])->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity');
    }

    public function test_room_capacity_three_allows_only_two_students_same_slot(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-c@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-c@example.com');

        $roomId = DB::table('rooms')->insertGetId([
            'campus_id' => 1,
            'name' => '301教室',
            'capacity' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentA = $this->createStudent(1, '學生F');
        $studentB = $this->createStudent(1, '學生G');
        $studentC = $this->createStudent(1, '學生H');

        $this->createCourseViaApi($token, $studentA->id, $teacherId, [
            'class_type' => 'one_on_three',
            'room_id' => $roomId,
            'start_time' => '18:00',
            'first_class_date' => '2026-04-01',
            'days_of_week' => [3],
        ])->assertCreated();

        $this->createCourseViaApi($token, $studentB->id, $teacherId, [
            'class_type' => 'one_on_three',
            'room_id' => $roomId,
            'start_time' => '18:00',
            'first_class_date' => '2026-04-01',
            'days_of_week' => [3],
        ])->assertCreated();

        $this->createCourseViaApi($token, $studentC->id, $teacherId, [
            'class_type' => 'one_on_three',
            'room_id' => $roomId,
            'start_time' => '18:00',
            'first_class_date' => '2026-04-01',
            'days_of_week' => [3],
        ])->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'room_capacity');
    }

    public function test_schedule_reschedule_target_is_blocked_when_teacher_already_full(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-d@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-d@example.com');

        $studentA = $this->createStudent(1, '學生I');
        $studentB = $this->createStudent(1, '學生J');
        $studentC = $this->createStudent(1, '學生K');

        $this->createCourseViaApi($token, $studentA->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '16:00',
            'first_class_date' => '2026-03-30',
            'days_of_week' => [1],
        ])->assertCreated();

        $this->createCourseViaApi($token, $studentB->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '16:00',
            'first_class_date' => '2026-03-30',
            'days_of_week' => [1],
        ])->assertCreated();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentC->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 1,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-03-30',
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity')
            ->assertJsonStructure([
                'conflicts' => [
                    [
                        'overlap_summary',
                        'overlap_details',
                    ],
                ],
            ]);
    }

    /**
     * Bug #557: a student on leave must NOT occupy teacher capacity.
     * Two one_on_two ClassSessions sit on the same slot, but one is on leave.
     * Rescheduling a third student in must succeed (1 real occupant < capacity 2).
     * Revert-proof: before the fix the leave session is counted (2 occupants) → 409.
     */
    public function test_reschedule_allowed_when_a_slot_occupant_is_on_leave(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-leave@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-leave@example.com');

        $studentA = $this->createStudent(1, '在班學生A');
        $studentB = $this->createStudent(1, '請假學生B');
        $studentC = $this->createStudent(1, '欲調入學生C');

        $date = '2026-06-08'; // Monday, fixed future date
        $courseA = $this->createOneOnTwoCourse($studentA->id, $teacherId, '16:00');
        $courseB = $this->createOneOnTwoCourse($studentB->id, $teacherId, '16:00');

        // A attends normally; B is on leave that day.
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseA, 'SessionDate' => $date,
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled',
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseB, 'SessionDate' => $date,
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'leave',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentC->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 1,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => $date,
        ]);

        $res->assertStatus(201);
    }

    public function test_schedule_update_excludes_same_student_from_capacity_guard(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-update-self@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-update-self@example.com');
        $student = $this->createStudent(1, '更新同一筆學生');

        $courseId = $this->createOneOnTwoCourse($student->id, $teacherId, '20:00');
        $date = '2026-06-09';

        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '20:00',
            'EndTime' => '22:00',
            'Status' => 'scheduled',
        ]);

        $scheduleId = (int) DB::table('schedules')->insertGetId([
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 2,
            'start_time' => '20:00',
            'end_time' => '22:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => $date,
            'student_course_id' => $courseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/schedules/{$scheduleId}", [
            'schedule_date' => $date,
            'start_time' => '20:00',
            'end_time' => '22:00',
            'status' => 'scheduled',
            'class_type' => 'one_on_one',
        ]);

        $res->assertOk();
    }

    /**
     * 調課寫 schedules 時，若請求仍帶合約 TeacherID（正班）但鏈結上已有「代課 scheduled」，
     * 伺服器改用代課老師檢 capacity — 避免因正班同日已滿而誤擋『代課老師為空』的跨日調課。
     */
    public function test_reschedule_schedule_row_prefers_substitute_teacher_linked_to_anchor(): void
    {
        $token = $this->createDirectorToken([1], 'director-subst-anchor@example.com');
        $teacherA = $this->createTeacher(1, 'teacher-sub-a@example.com');
        $teacherB = $this->createTeacher(1, 'teacher-sub-b@example.com');

        $studentX = $this->createStudent(1, '學生調課來源');
        $studentY = $this->createStudent(1, '學生佔時段');

        // SC1 Thu  evening — will be postponed to Fri overlapping A's Fri night slot
        $course1Id = (int) StudentClass::query()->insertGetId([
            'StudentID' => $studentX->id,
            'TeacherID' => $teacherA,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-07',
            'TotalHours' => 16,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Charge' => 1600,
            'Pay' => 1600,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'week' => 4,
            'time' => '18:00',
            'ScheduleMode' => 'count',
        ]);

        // SC2 Fri 20–22 Teacher A consumes A at that instant (different student).
        StudentClass::query()->insert([
            'StudentID' => $studentY->id,
            'TeacherID' => $teacherA,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-08',
            'TotalHours' => 16,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Charge' => 1600,
            'Pay' => 1600,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'week' => 5,
            'time' => '20:00',
            'ScheduleMode' => 'count',
        ]);

        ClassSession::query()->insert([
            'StudentClassID' => $course1Id,
            'SessionDate' => '2026-05-07',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
        ]);

        $sc2 = StudentClass::query()->where('StudentID', $studentY->id)->where('TeacherID', $teacherA)->first();

        ClassSession::query()->insert([
            'StudentClassID' => $sc2->ID,
            'SessionDate' => '2026-05-08',
            'StartTime' => '20:00:00',
            'EndTime' => '22:00:00',
            'Status' => 'scheduled',
        ]);

        // Rescheduled anchor Thu + substitute scheduled Thu (teacher B) — mimic post-substitute state.
        $anchor = Schedule::create([
            'student_id' => $studentX->id,
            'teacher_id' => $teacherA,
            'subject' => 'Math',
            'day_of_week' => Carbon::parse('2026-05-07')->dayOfWeekIso,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-05-07',
            'student_course_id' => $course1Id,
        ]);

        Schedule::create([
            'student_id' => $studentX->id,
            'teacher_id' => $teacherB,
            'subject' => 'Math',
            'day_of_week' => Carbon::parse('2026-05-07')->dayOfWeekIso,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-05-07',
            'student_course_id' => $course1Id,
            'original_schedule_id' => $anchor->id,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentX->id,
            // Intentionally send contract TeacherID — server should honour substitute B linked to anchor.
            'teacher_id' => $teacherA,
            'subject' => 'Math',
            'day_of_week' => Carbon::parse('2026-05-08')->dayOfWeekIso,
            'start_time' => '20:00',
            'end_time' => '22:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-05-08',
            'student_course_id' => $course1Id,
            'original_schedule_id' => $anchor->id,
        ]);

        $res->assertCreated();
        $tid = Schedule::where('student_course_id', $course1Id)
            ->whereDate('schedule_date', '2026-05-08')
            ->where('status', 'scheduled')
            ->value('teacher_id');
        $this->assertSame($teacherB, (int) $tid, 'persisted schedules row uses substitute teacher capacity context');
    }

    public function test_stale_scheduled_overrides_do_not_double_count_when_class_session_exists(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-e@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-e@example.com');

        $studentA = $this->createStudent(1, '學生L');
        $studentB = $this->createStudent(1, '學生M');

        $courseRes = $this->createCourseViaApi($token, $studentA->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '18:00',
            'first_class_date' => '2026-04-07',
            'days_of_week' => [2],
        ])->assertCreated();

        $courseId = (int) ($courseRes->json('ID') ?? $courseRes->json('id') ?? 0);
        if ($courseId <= 0) {
            $courseId = (int) (DB::table('StudentClass')
                ->where('StudentID', $studentA->id)
                ->where('TeacherID', $teacherId)
                ->max('ID') ?? 0);
        }
        $this->assertTrue($courseId > 0, 'Course ID should be available for stale override test.');

        // Simulate stale scheduled overrides left from prior adjustments.
        DB::table('schedules')->insert([
            [
                'student_id' => $studentA->id,
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'day_of_week' => 2,
                'start_time' => '16:00',
                'end_time' => '18:00',
                'duration_hours' => 2,
                'class_type' => 'one_on_two',
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-04-07',
                'student_course_id' => $courseId,
                'original_schedule_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_id' => $studentA->id,
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'day_of_week' => 2,
                'start_time' => '16:30',
                'end_time' => '18:30',
                'duration_hours' => 2,
                'class_type' => 'one_on_two',
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-04-07',
                'student_course_id' => $courseId,
                'original_schedule_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentB->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 2,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-07',
        ]);

        $res->assertCreated();
    }

    public function test_duplicate_schedules_of_same_student_count_as_one_for_capacity(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-f@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-f@example.com');

        $studentA = $this->createStudent(1, '學生N');
        $studentB = $this->createStudent(1, '學生O');

        DB::table('schedules')->insert([
            [
                'student_id' => $studentA->id,
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'day_of_week' => 3,
                'start_time' => '16:00',
                'end_time' => '18:00',
                'duration_hours' => 2,
                'class_type' => 'one_on_two',
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-04-08',
                'student_course_id' => null,
                'original_schedule_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'student_id' => $studentA->id,
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'day_of_week' => 3,
                'start_time' => '16:30',
                'end_time' => '18:30',
                'duration_hours' => 2,
                'class_type' => 'one_on_two',
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-04-08',
                'student_course_id' => null,
                'original_schedule_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentB->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_two',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-08',
        ]);

        $res->assertCreated();
    }

    public function test_trial_can_join_slot_with_existing_one_on_one_student(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-trial-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-trial-a@example.com');

        $studentA = $this->createStudent(1, '學生余潔');
        $studentB = $this->createStudent(1, '學生彭宥勛');

        // Existing one-on-one student occupies the teacher slot on Monday 2026-04-13 18:00-20:00.
        // Insert directly into schedules to exercise validateScheduleOccurrence overlap logic
        // without depending on the retired POST /api/v1/student-classes endpoint.
        DB::table('schedules')->insert([
            'student_id' => $studentA->id,
            'teacher_id' => $teacherId,
            'subject' => 'Physics',
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-13',
            'student_course_id' => null,
            'original_schedule_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Trial student joins the same Monday 18:00-20:00 slot — should succeed with fix.
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentB->id,
            'teacher_id' => $teacherId,
            'subject' => 'Physics',
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'duration_hours' => 2,
            'class_type' => 'trial',
            'status' => 'scheduled',
            'type' => 'trial',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-13',
        ]);

        $res->assertCreated();
    }

    public function test_two_trial_students_same_slot_are_blocked(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-trial-b@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-trial-b@example.com');

        $studentA = $this->createStudent(1, '試聽生甲');
        $studentB = $this->createStudent(1, '試聽生乙');

        // First trial student occupies the slot.
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentA->id,
            'teacher_id' => $teacherId,
            'subject' => 'Physics',
            'day_of_week' => 2,
            'start_time' => '19:00',
            'end_time' => '21:00',
            'duration_hours' => 2,
            'class_type' => 'trial',
            'status' => 'scheduled',
            'type' => 'trial',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-14',
        ])->assertCreated();

        // Second trial student attempting the same slot should be blocked.
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentB->id,
            'teacher_id' => $teacherId,
            'subject' => 'Physics',
            'day_of_week' => 2,
            'start_time' => '19:00',
            'end_time' => '21:00',
            'duration_hours' => 2,
            'class_type' => 'trial',
            'status' => 'scheduled',
            'type' => 'trial',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-14',
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity');
    }

    /**
     * 調課時代課老師為有效老師：合約老師(A)有其他課不應阻擋以B代課的調課操作。
     *
     * 情境：
     * - A 是 X 學生（四）13:00 的合約老師，B 已被指定為代課
     * - A 在（五）20:00 另有一堂課（Y 學生）
     * - 用戶在 frontend 例外 stale 時，仍送出 teacher_id=A 的調課新排程列
     * - Bug 修前：ScheduleGuard 查 A → A 有（五）20:00 課 → 409 阻擋
     * - Bug 修後：ScheduleGuard 改查現有代課老師 B → B（五）20:00 沒課 → 201 通過
     */
    public function test_reschedule_scheduled_row_uses_existing_substitute_teacher_for_guard(): void
    {
        $token = $this->createDirectorToken([1], 'dir-resched-sub@example.com');
        $teacherAId = $this->createTeacher(1, 'teacher-a-contract@example.com');
        $teacherBId = $this->createTeacher(1, 'teacher-b-substitute@example.com');

        $studentX = $this->createStudent(1, '學生X');
        $studentY = $this->createStudent(1, '學生Y');

        // X 的 StudentClass（合約老師 = A）
        $scXId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentX->id, 'TeacherID' => $teacherAId,
            'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'one_on_one',
            'by1' => 0, 'TotalHours' => 16, 'MDate' => now(),
            'SessionDuration' => 120, 'Stop' => 0, 'RemainingSessions' => 8,
            'SessionCount' => 8, 'UsedSessions' => 0, 'Rate' => 500, 'Charge' => 0, 'Paid' => 0,
            'StartDate' => '2026-04-01', 'week' => 4, 'time' => '13:00',
        ]);
        // Y 的 StudentClass（合約老師 = A，讓 A 在（五）20:00 佔有容量）
        $scYId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentY->id, 'TeacherID' => $teacherAId,
            'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'one_on_one',
            'by1' => 0, 'TotalHours' => 16, 'MDate' => now(),
            'SessionDuration' => 120, 'Stop' => 0, 'RemainingSessions' => 8,
            'SessionCount' => 8, 'UsedSessions' => 0, 'Rate' => 500, 'Charge' => 0, 'Paid' => 0,
            'StartDate' => '2026-04-01', 'week' => 5, 'time' => '20:00',
        ]);
        // A 在（五）20:00-22:00 有一堂 Y 的課（ClassSession）
        DB::table('ClassSession')->insert([
            'StudentClassID' => $scYId, 'SessionDate' => '2026-04-24',
            'StartTime' => '20:00', 'EndTime' => '22:00', 'Status' => 'scheduled',
        ]);

        // B 已被設為 X 課（四）13:00 的代課老師（schedules anchor + substitute row）
        $rescheduledId = DB::table('schedules')->insertGetId([
            'student_id' => $studentX->id, 'teacher_id' => $teacherAId,
            'subject' => '數學', 'day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '15:00',
            'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => 'rescheduled', 'type' => 'normal', 'deduction' => 0,
            'branch_id' => 1, 'schedule_date' => '2026-04-23', 'student_course_id' => $scXId,
        ]);
        DB::table('schedules')->insert([
            'student_id' => $studentX->id, 'teacher_id' => $teacherBId,
            'subject' => '數學', 'day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '15:00',
            'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1,
            'branch_id' => 1, 'schedule_date' => '2026-04-23',
            'student_course_id' => $scXId, 'original_schedule_id' => $rescheduledId,
        ]);

        // Frontend（例外 stale 時）送出 teacher_id=A 的調課目標排程列到（五）20:00-22:00
        // Bug 修前：ScheduleGuard 查 A → A 有（五）課 → 409
        // Bug 修後：ScheduleGuard 改查現有代課老師 B → B（五）沒課 → 201
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/schedules', [
            'student_id'          => $studentX->id,
            'teacher_id'          => $teacherAId,  // contract teacher A（stale frontend data）
            'subject'             => '數學',
            'day_of_week'         => 5,
            'start_time'          => '20:00',
            'end_time'            => '22:00',
            'duration_hours'      => 2,
            'class_type'          => 'one_on_one',
            'status'              => 'scheduled',
            'type'                => 'normal',
            'deduction'           => 1,
            'branch_id'           => 1,
            'schedule_date'       => '2026-04-24',  // Friday
            'student_course_id'   => $scXId,
            'original_schedule_id' => $rescheduledId,
        ]);

        $res->assertStatus(201);

        // 寫入的排程列 teacher_id 應為代課老師 B（非合約老師 A）
        $this->assertDatabaseHas('schedules', [
            'student_course_id'   => $scXId,
            'schedule_date'       => '2026-04-24',
            'start_time'          => '20:00',
            'status'              => 'scheduled',
            'original_schedule_id' => $rescheduledId,
            'teacher_id'          => $teacherBId,
        ]);
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
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

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    /**
     * Insert a one_on_two StudentClass (used by capacity tests that drive
     * occupancy through ClassSession rows). Returns the StudentClass ID.
     */
    private function createOneOnTwoCourse(int $studentId, int $teacherId, string $startTime): int
    {
        return (int) StudentClass::query()->insertGetId([
            'StudentID' => $studentId,
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_two',
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-06-01',
            'TotalHours' => 16,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Charge' => 1600,
            'Pay' => 1600,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'week' => 1,
            'time' => $startTime,
            'ScheduleMode' => 'count',
        ]);
    }

    /**
     * POST /api/v1/student-classes was retired (410).
     * Redirect guard tests to POST /api/v1/schedules with a concrete schedule_date,
     * because ScheduleGuardService::validateScheduleOccurrence (the live code path)
     * reads the `schedules` table, not recurring StudentClass records.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createCourseViaApi(string $token, int $studentId, int $teacherId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $opts = array_merge([
            'class_type'       => 'one_on_one',
            'duration_hours'   => 2,
            'first_class_date' => '2026-03-30',
            'days_of_week'     => [1],
            'start_time'       => '16:00',
            'room_id'          => null,
        ], $overrides);

        $days        = array_values((array) ($opts['days_of_week'] ?? [1]));
        $dayOfWeek   = (int) ($days[0] ?? 1);
        $startTime   = (string) $opts['start_time'];
        $durationH   = (float) ($opts['duration_hours'] ?? 2);
        $endMinutes  = (int) ($durationH * 60);
        [$h, $m]     = array_map('intval', explode(':', $startTime));
        $totalMinutes = $h * 60 + $m + $endMinutes;
        $endTime      = sprintf('%02d:%02d', intdiv($totalMinutes, 60), $totalMinutes % 60);

        // When a room_id is provided, we must create a StudentClass so the guard
        // can resolve room_id via student_course_id (schedules table has no room_id column).
        $studentCourseId = null;
        if (!empty($opts['room_id'])) {
            $studentCourseId = DB::table('StudentClass')->insertGetId([
                'StudentID'       => $studentId,
                'TeacherID'       => $teacherId,
                'ClassType'       => $opts['class_type'],
                'GradeID'         => 1,
                'SubjectID'       => 1,
                'by1'             => 0,
                'TotalHours'      => 16,
                'week'            => $dayOfWeek,
                'time'            => $startTime,
                'StartDate'       => $opts['first_class_date'],
                'SessionDuration' => (int) ($durationH * 60),
                'room_id'         => $opts['room_id'],
                'Stop'            => 0,
                'RemainingSessions' => 8,
                'SessionCount'    => 8,
                'UsedSessions'    => 0,
                'Rate'            => 500,
                'Charge'          => 0,
                'Paid'            => 0,
                'Memo'            => '測試課程',
                'MDate'           => now(),
            ]);
        }

        $payload = [
            'student_id'    => $studentId,
            'teacher_id'    => $teacherId,
            'subject'       => 'Math',
            'day_of_week'   => $dayOfWeek,
            'start_time'    => $startTime,
            'end_time'      => $endTime,
            'duration_hours' => $durationH,
            'class_type'    => $opts['class_type'],
            'status'        => 'scheduled',
            'type'          => 'normal',
            'deduction'     => 1,
            'branch_id'     => 1,
            'schedule_date' => $opts['first_class_date'],
        ];
        if ($studentCourseId) {
            $payload['student_course_id'] = $studentCourseId;
        }
        if (!empty($opts['room_id'])) {
            $payload['room_id'] = $opts['room_id'];
        }

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules', $payload);
    }
}
