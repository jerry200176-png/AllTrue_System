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

    public function test_pure_teacher_capacity_decision_allows_targeted_trial_until_third_seat(): void
    {
        $guard = app(\App\Services\ScheduleGuardService::class);
        $slot = ['day_of_week' => 3, 'start_time' => '15:00', 'end_time' => '17:00'];
        $overlaps = [
            ['student_id' => 101, 'class_type' => 'trial', 'trial_target_class_type' => 'one_on_three', 'start_time' => '15:00', 'end_time' => '17:00'],
            ['student_id' => 102, 'class_type' => 'trial', 'trial_target_class_type' => 'one_on_three', 'start_time' => '15:00', 'end_time' => '17:00'],
        ];
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $allowed = $guard->teacherCapacityDecision('trial', $slot, $overlaps, 'one_on_three');
        $this->assertTrue($allowed['allowed']);
        $this->assertSame(2, $allowed['distinct_students']);
        $this->assertNull($allowed['conflict']);

        $blocked = $guard->teacherCapacityDecision('trial', $slot, [...$overlaps, [
            'student_id' => 103, 'class_type' => 'trial', 'trial_target_class_type' => 'one_on_three', 'start_time' => '15:00', 'end_time' => '17:00',
        ]], 'one_on_three');
        $this->assertFalse($blocked['allowed']);
        $this->assertSame(3, $blocked['distinct_students']);
        $this->assertSame(3, $blocked['conflict']['allowed_students']);
        $this->assertSame([], $queries);
    }

    public function test_teacher_capacity_context_batches_targets_across_dates_and_the_incoming_course(): void
    {
        $teacher = $this->createTeacher(1, 'teacher-capacity-batch@example.com');
        $ids = [];
        foreach (['one_on_three', 'one_on_two', 'one_on_three'] as $i => $target) {
            $ids[] = $this->insertTrialCourse($this->createStudent(1, '批次試聽' . $i)->id, $teacher, $target);
        }
        $entry = fn ($id) => ['class_type' => 'trial', 'course_id' => $id, 'student_id' => $id,
            'start_time' => '15:00', 'end_time' => '17:00'];
        $entries = ['2026-04-15' => [$entry($ids[0])], '2026-04-16' => [$entry($ids[1])]];
        $guard = app(\App\Services\ScheduleGuardService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $context = $guard->prepareTeacherCapacityContext($entries, $ids[2]);
            $queries = DB::getQueryLog();
            $this->assertCount(1, $queries);
            $this->assertStringContainsString('trial_target_class_type', $queries[0]['query']);
            $this->assertEqualsCanonicalizing($ids, $queries[0]['bindings']);
            $this->assertSame('one_on_three', $context['incoming_trial_target']);
            $this->assertSame('one_on_two', $context['entries_by_date']['2026-04-16'][0]['trial_target_class_type']);
            DB::flushQueryLog();
            $this->assertSame($context, $guard->prepareTeacherCapacityContext($entries, $ids[2]));
            foreach ($context['entries_by_date'] as $rows) {
                $this->assertTrue($guard->teacherCapacityDecision('trial', [
                    'start_time' => '15:00', 'end_time' => '17:00',
                ], $rows, $context['incoming_trial_target'])['allowed']);
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

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

    /**
     * #253 regression: a same-day substitute target must have a materialized
     * ClassSession at the target start time, not merely somewhere on that date.
     */
    public function test_same_day_substitute_rejects_session_at_different_start_time(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-exact-session@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-exact-session@example.com');
        $student = $this->createStudent(1, '精確課堂時間測試');
        $courseId = $this->createOneOnOneCourse($student->id, $teacherId, '18:00', '2026-06-01');
        $date = '2026-06-08';

        // A different occurrence on the same date must not satisfy the 18:00
        // substitute target check.
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '20:00',
            'EndTime' => '22:00',
            'Status' => 'scheduled',
        ]);
        $originalId = DB::table('schedules')->insertGetId([
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 1,
            'start_time' => '17:00',
            'end_time' => '19:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
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
        ])->postJson('/api/v1/schedules', [
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => $date,
            'student_course_id' => $courseId,
            'original_schedule_id' => $originalId,
        ]);

        $res->assertStatus(422)->assertJsonPath('code', 'no_class_session');
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $courseId,
            'original_schedule_id' => $originalId,
            'status' => 'scheduled',
            'start_time' => '18:00',
        ]);
    }

    /**
     * #1889: a full 1-on-2 occupant must not block adding a 1-on-3 third seat.
     * Revert-proof: the old existing-type cap loop 409s the 1-on-3 add.
     */
    public function test_mixed_one_on_two_full_still_allows_one_on_three_third_seat(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-mixed@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-mixed@example.com');

        $studentA = $this->createStudent(1, '混班一對二');
        $studentB = $this->createStudent(1, '混班一對三甲');
        $studentC = $this->createStudent(1, '混班一對三乙');
        $studentD = $this->createStudent(1, '混班一對二第三人');

        $this->createCourseViaApi($token, $studentA->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '15:00',
            'first_class_date' => '2026-08-20',
            'days_of_week' => [4],
        ])->assertCreated();

        $this->createCourseViaApi($token, $studentB->id, $teacherId, [
            'class_type' => 'one_on_three',
            'start_time' => '15:00',
            'first_class_date' => '2026-08-20',
            'days_of_week' => [4],
        ])->assertCreated();

        $blockedTwo = $this->createCourseViaApi($token, $studentD->id, $teacherId, [
            'class_type' => 'one_on_two',
            'start_time' => '15:00',
            'first_class_date' => '2026-08-20',
            'days_of_week' => [4],
        ]);
        $blockedTwo->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity');
        $message = (string) $blockedTwo->json('conflicts.0.message');
        $this->assertStringContainsString('本分校', $message);
        $this->assertStringContainsString('一對二', $message);

        $this->createCourseViaApi($token, $studentC->id, $teacherId, [
            'class_type' => 'one_on_three',
            'start_time' => '15:00',
            'first_class_date' => '2026-08-20',
            'days_of_week' => [4],
        ])->assertCreated();
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

    /**
     * #2006: 續約時舊 StudentClass 沒被關閉，跟新 StudentClass 同時段並存。
     * 學生對「新期」請假後，衝突訊息應該點名是「哪一筆」課程（科目/期別），
     * 而不是只顯示學生姓名——避免主任誤以為系統把請假的那堂算成佔用。
     */
    public function test_conflict_details_include_subject_when_student_has_overlapping_course(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-dup@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-dup@example.com');

        DB::table('Subject')->insertOrIgnore(['id' => 1, 'Subject_Name' => '理化']);

        $studentB = $this->createStudent(1, '王品方');
        $studentC = $this->createStudent(1, '欲補課學生');

        $date = '2026-08-29'; // Saturday

        // Old (unclosed after renewal) and new course, same subject/teacher/slot.
        $oldCourse = $this->createOneOnOneCourse($studentB->id, $teacherId, '13:00', '2026-05-09');
        $newCourse = $this->createOneOnOneCourse($studentB->id, $teacherId, '13:00', '2026-07-11');

        DB::table('ClassSession')->insert([
            'StudentClassID' => $oldCourse, 'SessionDate' => $date,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $newCourse, 'SessionDate' => $date,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'leave',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentC->id,
            'teacher_id' => $teacherId,
            'subject' => 'Physics',
            'day_of_week' => 6,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => $date,
        ]);

        // Still correctly blocked — the "old" course is a genuine, still-active occupant.
        $res->assertStatus(409)
            ->assertJsonPath('conflicts.0.overlap_details.0.student_name', '王品方')
            ->assertJsonPath('conflicts.0.overlap_details.0.subject_name', '理化');
        $message = (string) $res->json('message');
        $this->assertStringContainsString('王品方', $message);
        $this->assertStringContainsString('理化', $message);
        $this->assertNotEmpty($res->json('suggested_actions'));
        $this->assertNotEmpty($res->json('conflicts.0.suggested_actions'));
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
     * in-app #311: editing a course’s recurring slots must not treat the same
     * student’s own ClassSession/schedule rows (or a dual contract) as an
     * external one-on-one occupant. Other students still block.
     */
    public function test_validate_recurring_course_excludes_same_student_occupancy(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-guard-recurring-self@example.com');
        $selfStudent = $this->createStudent(1, '王品方');
        $otherStudent = $this->createStudent(1, '其他學生');

        $selfCourseId = (int) StudentClass::query()->insertGetId([
            'StudentID' => $selfStudent->id,
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-01',
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

        // Dual contract for the same student on the same recurring slot.
        $dualCourseId = (int) StudentClass::query()->insertGetId([
            'StudentID' => $selfStudent->id,
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'SubjectID' => 2,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-01',
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

        $date = '2026-09-17'; // Thursday
        foreach ([$selfCourseId, $dualCourseId] as $courseId) {
            DB::table('ClassSession')->insert([
                'StudentClassID' => $courseId,
                'SessionDate' => $date,
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => 'scheduled',
            ]);
        }

        $guard = app(\App\Services\ScheduleGuardService::class);
        $slot = [['day_of_week' => 4, 'start_time' => '18:00', 'end_time' => '20:00']];

        $selfOnly = $guard->validateRecurringCourse([
            'teacher_id' => $teacherId,
            'class_type' => 'one_on_one',
            'branch_id' => 1,
            'slots' => $slot,
            'exclude_student_class_id' => $selfCourseId,
            'exclude_student_id' => (int) $selfStudent->id,
            'start_date' => '2026-09-01',
        ]);
        $this->assertSame([], $selfOnly, 'Same-student dual-contract occupancy must not block course edit');

        $otherCourseId = (int) StudentClass::query()->insertGetId([
            'StudentID' => $otherStudent->id,
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-09-01',
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
        DB::table('ClassSession')->insert([
            'StudentClassID' => $otherCourseId,
            'SessionDate' => $date,
            'StartTime' => '18:00',
            'EndTime' => '20:00',
            'Status' => 'scheduled',
        ]);
        $withOther = $guard->validateRecurringCourse([
            'teacher_id' => $teacherId,
            'class_type' => 'one_on_one',
            'branch_id' => 1,
            'slots' => $slot,
            'exclude_student_class_id' => $selfCourseId,
            'exclude_student_id' => (int) $selfStudent->id,
            'start_date' => '2026-09-01',
        ]);
        $this->assertNotEmpty($withOther);
        $this->assertSame('teacher_capacity', $withOther[0]['type'] ?? null);
    }

    /**
     * In-app #373: a used-up count course (RemainingSessions=0, no future lesson)
     * still had Stop=0 and kept its weekly 1:3 seat forever.
     * Revert-proof: before the fix the three used-up courses fill the slot → conflict.
     */
    public function test_validate_course_occurrence_excludes_own_course_and_student(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-guard-course-occ@example.com');
        $make = function (string $name) use ($teacherId): StudentClass {
            $student = $this->createStudent(1, $name);
            $id = DB::table('StudentClass')->insertGetId([
                'StudentID' => $student->id, 'TeacherID' => $teacherId, 'ClassType' => 'one_on_one',
                'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01',
                'TotalHours' => 2, 'SessionCount' => 1, 'SessionDuration' => 120, 'RemainingSessions' => 1,
                'UsedSessions' => 0, 'Charge' => 500, 'Pay' => 0, 'Paid' => 0, 'Rate' => 500, 'Stop' => 0,
                'MDate' => now(), 'ScheduleMode' => 'count',
            ]);
            ClassSession::create([
                'StudentClassID' => $id, 'SessionDate' => '2026-04-13',
                'StartTime' => '18:00:00', 'EndTime' => '20:00:00', 'Status' => 'scheduled',
            ]);

            return StudentClass::findOrFail($id);
        };
        $mine = $make('本人');
        $other = $make('他人');
        $guard = app(\App\Services\ScheduleGuardService::class);

        // other's session fills the one_on_one seat; mine is excluded as self, so only other's counts
        $conflicts = $guard->validateCourseOccurrence($mine, 1, '2026-04-13', '18:00', '20:00');
        $this->assertSame('teacher_capacity', $conflicts[0]['type'] ?? null);
        // a free slot is clean
        $this->assertSame([], $guard->validateCourseOccurrence($mine, 1, '2026-04-13', '20:00', '21:00'));
        // delete the other course's seat: own course/student alone never conflicts
        DB::table('ClassSession')->where('StudentClassID', $other->ID)->delete();
        $this->assertSame([], $guard->validateCourseOccurrence($mine, 1, '2026-04-13', '18:00', '20:00'));
    }

    public function test_validate_occurrences_tags_conflicts_and_optionally_dedupes(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-guard-occ@example.com');
        $occupant = $this->createStudent(1, '佔位生');
        $courseId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $occupant->id, 'TeacherID' => $teacherId, 'ClassType' => 'one_on_one',
            'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01',
            'TotalHours' => 2, 'SessionCount' => 1, 'SessionDuration' => 120, 'RemainingSessions' => 1,
            'UsedSessions' => 0, 'Charge' => 500, 'Pay' => 0, 'Paid' => 0, 'Rate' => 500, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-04-13',
            'StartTime' => '18:00:00', 'EndTime' => '20:00:00', 'Status' => 'scheduled',
        ]);
        $guard = app(\App\Services\ScheduleGuardService::class);
        $base = ['teacher_id' => $teacherId, 'class_type' => 'one_on_one', 'branch_id' => 1];
        $slot = ['date' => '2026-04-13', 'start_time' => '18:00:00', 'end_time' => '20:00:00'];

        $all = $guard->validateOccurrences($base, [$slot, $slot]);
        $this->assertCount(2, $all);
        $this->assertSame('2026-04-13', $all[0]['date']);
        $this->assertSame('18:00-20:00', $all[0]['proposed_time']);
        $this->assertSame('teacher_capacity', $all[0]['type']);

        $this->assertCount(1, $guard->validateOccurrences($base, [$slot, $slot], true));
        // Same-student exclusion still applies (self occupancy never conflicts).
        $this->assertSame([], $guard->validateOccurrences($base + ['exclude_student_id' => $occupant->id], [$slot]));
    }

    public function test_used_up_count_course_does_not_hold_recurring_seat(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-guard-used-up@example.com');
        $addCourse = function (string $name, int $remaining) use ($teacherId): void {
            $student = $this->createStudent(1, $name);
            StudentClass::query()->insert([
                'StudentID' => $student->id,
                'TeacherID' => $teacherId,
                'ClassType' => 'one_on_three',
                'GradeID' => 1,
                'SubjectID' => 1,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-01-01',
                'TotalHours' => 16,
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => $remaining,
                'UsedSessions' => 8 - $remaining,
                'Charge' => 1600,
                'Pay' => 1600,
                'Paid' => 0,
                'Rate' => 800,
                'Stop' => 0,
                'MDate' => now(),
                'week' => 7,
                'time' => '10:00',
                'ScheduleMode' => 'count',
            ]);
        };
        $guard = app(\App\Services\ScheduleGuardService::class);
        $payload = [
            'teacher_id' => $teacherId,
            'class_type' => 'one_on_three',
            'branch_id' => 1,
            'slots' => [['day_of_week' => 7, 'start_time' => '10:00', 'end_time' => '12:00']],
        ];

        foreach (['用完甲', '用完乙', '用完丙'] as $name) {
            $addCourse($name, 0);
        }
        $this->assertSame([], $guard->validateRecurringCourse($payload), 'Used-up courses must not occupy the slot');

        foreach (['在讀甲', '在讀乙', '在讀丙'] as $name) {
            $addCourse($name, 8);
        }
        $blocked = $guard->validateRecurringCourse($payload);
        $this->assertNotEmpty($blocked);
        $this->assertSame('teacher_capacity', $blocked[0]['type'] ?? null);
    }

    /**
     * In-app #401/#402: a date-mode contract that already ended (the student moved
     * to another teacher on a new contract) must not hold the old teacher's seat.
     */
    public function test_ended_date_mode_contract_does_not_hold_recurring_seat(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-guard-ended@example.com');
        $addCourse = function (string $name, ?string $endDate, ?string $mode = 'date') use ($teacherId): void {
            $student = $this->createStudent(1, $name);
            StudentClass::query()->insert([
                'StudentID' => $student->id, 'TeacherID' => $teacherId, 'ClassType' => 'one_on_two',
                'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4,
                'StartDate' => Carbon::today()->subMonths(6)->toDateString(), 'EndDate' => $endDate,
                'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120,
                'RemainingSessions' => 8, 'UsedSessions' => 0,
                'Charge' => 1600, 'Pay' => 1600, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
                'MDate' => now(), 'week' => 5, 'time' => '19:00', 'ScheduleMode' => $mode,
            ]);
        };
        $guard = app(\App\Services\ScheduleGuardService::class);
        $payload = [
            'teacher_id' => $teacherId,
            'class_type' => 'one_on_two',
            'branch_id' => 1,
            'slots' => [['day_of_week' => 5, 'start_time' => '19:00', 'end_time' => '21:00']],
        ];

        $addCourse('已結束甲', Carbon::today()->subMonth()->toDateString());
        $addCourse('在讀甲', null);
        $this->assertSame([], $guard->validateRecurringCourse($payload), 'Ended contract must not occupy the slot');

        $addCourse('在讀乙', Carbon::today()->addMonth()->toDateString());
        $blocked = $guard->validateRecurringCourse($payload);
        $this->assertNotEmpty($blocked);
        $this->assertSame('teacher_capacity', $blocked[0]['type'] ?? null);
        $this->assertSame(2, $blocked[0]['current_students'] ?? null);
    }

    /** A legacy blank/null-mode contract is count mode: an estimated past EndDate must not free its seat. */
    public function test_legacy_null_mode_contract_keeps_recurring_seat_past_end_date(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-guard-null-mode@example.com');
        foreach (['舊制甲', '舊制乙'] as $name) {
            $student = $this->createStudent(1, $name);
            StudentClass::query()->insert([
                'StudentID' => $student->id, 'TeacherID' => $teacherId, 'ClassType' => 'one_on_two',
                'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4,
                'StartDate' => Carbon::today()->subMonths(6)->toDateString(),
                'EndDate' => Carbon::today()->subMonth()->toDateString(),
                'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120,
                'RemainingSessions' => 4, 'UsedSessions' => 4,
                'Charge' => 1600, 'Pay' => 1600, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
                'MDate' => now(), 'week' => 5, 'time' => '19:00', 'ScheduleMode' => '',
            ]);
        }

        $blocked = app(\App\Services\ScheduleGuardService::class)->validateRecurringCourse([
            'teacher_id' => $teacherId,
            'class_type' => 'one_on_two',
            'branch_id' => 1,
            'slots' => [['day_of_week' => 5, 'start_time' => '19:00', 'end_time' => '21:00']],
        ]);
        $this->assertNotEmpty($blocked);
        $this->assertSame('teacher_capacity', $blocked[0]['type'] ?? null);
    }

    /**
     * F8: the model scope and the guard's recurring-seat loader share one rule.
     * One used-up count 1:3 + two active 1:3 on the same weekly slot -> 2 occupants.
     * (Substitute/ExceptionWorkflow occupancy is ClassSession-based, so not template-governed.)
     */
    public function test_template_seat_rule_parity_scope_and_guard(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-f8-parity@example.com');
        foreach ([['用完', 0], ['在讀甲', 8], ['在讀乙', 8]] as [$name, $remaining]) {
            $student = $this->createStudent(1, $name);
            StudentClass::query()->insert([
                'StudentID' => $student->id, 'TeacherID' => $teacherId, 'ClassType' => 'one_on_three',
                'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-01-01',
                'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120,
                'RemainingSessions' => $remaining, 'UsedSessions' => 8 - $remaining,
                'Charge' => 1600, 'Pay' => 1600, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
                'MDate' => now(), 'week' => 7, 'time' => '10:00', 'ScheduleMode' => 'count',
            ]);
        }
        $this->assertSame(2, StudentClass::query()->where('TeacherID', $teacherId)->holdsTemplateSeat()->count());

        $m = new \ReflectionMethod(\App\Services\ScheduleGuardService::class, 'loadTeacherRecurringCourses');
        $m->setAccessible(true);
        $rows = $m->invoke(app(\App\Services\ScheduleGuardService::class), $teacherId, 1);
        $this->assertCount(2, $rows);
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

    public function test_two_trial_students_same_slot_without_one_on_three_are_blocked(): void
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

    private function insertGuardSlotRow(int $studentId, int $teacherId, string $classType, string $date, ?int $courseId = null): void
    {
        DB::table('schedules')->insert([
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Physics',
            'day_of_week' => 3,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'duration_hours' => 2,
            'class_type' => $classType,
            'status' => 'scheduled',
            'type' => $classType === 'trial' ? 'trial' : 'normal',
            'deduction' => $classType === 'trial' ? 0 : 1,
            'branch_id' => 1,
            'schedule_date' => $date,
            'student_course_id' => $courseId,
            'original_schedule_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seeds the Wednesday 2026-04-15 15:00-17:00 slot with the given class types
     * (one new student each), then posts one more row of $incomingType.
     *
     * @param  array<int, string>  $existingTypes
     * @return \Illuminate\Testing\TestResponse
     */
    private function postIntoSeededSlot(string $tag, array $existingTypes, string $incomingType)
    {
        $token = $this->createDirectorToken([1], "director-guard-{$tag}@example.com");
        $teacherId = $this->createTeacher(1, "teacher-guard-{$tag}@example.com");
        $date = '2026-04-15';

        foreach ($existingTypes as $i => $type) {
            $this->insertGuardSlotRow($this->createStudent(1, "既有生{$i}")->id, $teacherId, $type, $date);
        }

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $this->createStudent(1, '新加入生')->id,
            'teacher_id' => $teacherId,
            'subject' => 'Physics',
            'day_of_week' => 3,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'duration_hours' => 2,
            'class_type' => $incomingType,
            'status' => 'scheduled',
            'type' => $incomingType === 'trial' ? 'trial' : 'normal',
            'deduction' => $incomingType === 'trial' ? 0 : 1,
            'branch_id' => 1,
            'schedule_date' => $date,
        ]);
    }

    /** in-app #426 / Founder 1a: a 1v3 slot accepts more than one trial while total <= 3. */
    public function test_one_on_three_slot_allows_second_trial(): void
    {
        $this->postIntoSeededSlot('1v3-t2', ['one_on_three', 'trial'], 'trial')->assertCreated();
    }

    /** Founder 1a: 2 regular + 1 trial is allowed in a 1v3 slot. */
    public function test_one_on_three_slot_two_regular_plus_trial_is_allowed(): void
    {
        $this->postIntoSeededSlot('1v3-r2t1', ['one_on_three', 'one_on_three'], 'trial')->assertCreated();
    }

    /** Founder 1a: trials take real seats, so 2 regular + 1 trial is full for a third regular. */
    public function test_one_on_three_slot_trial_takes_a_seat_for_regular(): void
    {
        $this->postIntoSeededSlot('1v3-r2t1-r', ['one_on_three', 'one_on_three', 'trial'], 'one_on_three')
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity')
            ->assertJsonPath('conflicts.0.current_students', 3);
    }

    /** Founder 1a: a 4th trial in a full 1v3 slot (1 regular + 2 trials) is blocked. */
    public function test_one_on_three_slot_fourth_trial_is_blocked(): void
    {
        $this->postIntoSeededSlot('1v3-r1t2-t', ['one_on_three', 'trial', 'trial'], 'trial')
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity')
            ->assertJsonPath('conflicts.0.current_students', 3);
    }

    /** Founder 1a: a trial no longer audits on top of a full 1v3 slot (3 regular). */
    public function test_one_on_three_slot_full_of_regulars_blocks_trial(): void
    {
        $this->postIntoSeededSlot('1v3-r3-t', ['one_on_three', 'one_on_three', 'one_on_three'], 'trial')
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity')
            ->assertJsonPath('conflicts.0.current_students', 3);
    }

    /** Blast radius: a 1v2 slot keeps the one-trial-per-slot rule. */
    public function test_one_on_two_slot_still_blocks_second_trial(): void
    {
        $this->postIntoSeededSlot('1v2-t2', ['one_on_two', 'trial'], 'trial')
            ->assertStatus(409)
            ->assertJsonPath('conflicts.0.type', 'teacher_capacity');
    }

    /** Trial course (no weekly template) with an optional trial target class type. */
    private function insertTrialCourse(int $studentId, int $teacherId, ?string $target): int
    {
        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'TeacherID' => $teacherId,
            'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'trial', 'trial_target_class_type' => $target,
            'by1' => 0, 'TotalHours' => 2, 'MDate' => now(),
            'SessionDuration' => 120, 'Stop' => 0, 'RemainingSessions' => 1,
            'SessionCount' => 1, 'UsedSessions' => 0, 'Rate' => 0, 'Charge' => 0, 'Paid' => 0,
            'StartDate' => '2026-04-15',
        ]);
    }

    /**
     * Seeds trial bookings (each with its own trial course and target) plus regular rows
     * in the Wednesday 2026-04-15 15:00-17:00 slot, then asks the guard about one more.
     *
     * @param  array<int, string|null>  $trialTargets
     * @param  array<int, string>  $regularTypes
     * @return array<int, array<string, mixed>>
     */
    private function guardWithTrials(string $tag, array $trialTargets, array $regularTypes, string $incomingType, ?string $incomingTarget = null): array
    {
        $teacherId = $this->createTeacher(1, "teacher-guard-{$tag}@example.com");
        $date = '2026-04-15';
        foreach ($trialTargets as $i => $target) {
            $studentId = $this->createStudent(1, "試聽生{$i}")->id;
            $this->insertGuardSlotRow($studentId, $teacherId, 'trial', $date, $this->insertTrialCourse($studentId, $teacherId, $target));
        }
        foreach ($regularTypes as $i => $type) {
            $this->insertGuardSlotRow($this->createStudent(1, "正式生{$i}")->id, $teacherId, $type, $date);
        }

        return app(\App\Services\ScheduleGuardService::class)->validateScheduleOccurrence([
            'teacher_id' => $teacherId,
            'class_type' => $incomingType,
            'trial_target_class_type' => $incomingTarget,
            'branch_id' => 1,
            'schedule_date' => $date,
            'start_time' => '15:00',
            'end_time' => '17:00',
            'exclude_student_id' => $this->createStudent(1, '新加入生')->id,
        ]);
    }

    /** in-app #426: three trials for a 1v3 class with no regular student yet are allowed. */
    public function test_three_one_on_three_target_trials_without_regular_are_allowed(): void
    {
        $this->assertSame([], $this->guardWithTrials('tt-3', ['one_on_three', 'one_on_three'], [], 'trial', 'one_on_three'));
    }

    public function test_fourth_booking_after_three_one_on_three_target_trials_is_blocked(): void
    {
        $conflicts = $this->guardWithTrials('tt-4', ['one_on_three', 'one_on_three', 'one_on_three'], [], 'trial', 'one_on_three');
        $this->assertSame('teacher_capacity', $conflicts[0]['type'] ?? null);
        $this->assertSame(3, $conflicts[0]['current_students'] ?? null);
    }

    public function test_two_one_on_three_target_trials_plus_regular_is_allowed(): void
    {
        $this->assertSame([], $this->guardWithTrials('tt-2r', ['one_on_three', 'one_on_three'], [], 'one_on_three'));
    }

    /** The existing trials' stored target alone marks the slot (incoming trial has none). */
    public function test_stored_one_on_three_target_marks_slot_for_untargeted_incoming_trial(): void
    {
        $this->assertSame([], $this->guardWithTrials('tt-stored', ['one_on_three'], [], 'trial'));
    }

    /** Trials without a target keep the one-trial-per-slot rule. */
    public function test_untargeted_trials_still_one_per_slot(): void
    {
        $conflicts = $this->guardWithTrials('tt-null', [null], [], 'trial');
        $this->assertSame('teacher_capacity', $conflicts[0]['type'] ?? null);
        $this->assertSame(1, $conflicts[0]['allowed_students'] ?? null);
    }

    public function test_one_on_two_target_trials_still_one_per_slot(): void
    {
        $conflicts = $this->guardWithTrials('tt-1v2', ['one_on_two'], [], 'trial', 'one_on_two');
        $this->assertSame('teacher_capacity', $conflicts[0]['type'] ?? null);
    }

    /** Codex #4342: a 1v3 class covering only part of the request does not lift the one-trial rule where it is absent. */
    public function test_partial_one_on_three_overlap_keeps_one_trial_rule(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-guard-tt-partial@example.com');
        $regular = $this->createStudent(1, '正式生P')->id;
        $this->insertGuardSlotRow($regular, $teacherId, 'one_on_three', '2026-04-15');
        $trial = $this->createStudent(1, '試聽生P')->id;
        $this->insertGuardSlotRow($trial, $teacherId, 'trial', '2026-04-15', $this->insertTrialCourse($trial, $teacherId, null));
        DB::table('schedules')->where('student_id', $regular)->update(['end_time' => '16:00', 'duration_hours' => 1]);
        DB::table('schedules')->where('student_id', $trial)->update(['start_time' => '16:00', 'duration_hours' => 1]);

        $conflicts = app(\App\Services\ScheduleGuardService::class)->validateScheduleOccurrence([
            'teacher_id' => $teacherId, 'class_type' => 'trial', 'branch_id' => 1, 'schedule_date' => '2026-04-15',
            'start_time' => '15:30', 'end_time' => '16:30', 'exclude_student_id' => $this->createStudent(1, '新加入生P')->id,
        ]);
        $this->assertSame('teacher_capacity', $conflicts[0]['type'] ?? null);
        $this->assertSame(1, $conflicts[0]['allowed_students'] ?? null);
    }

    public function test_trial_target_class_type_requires_trial_course_on_create(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-tt-validate@example.com');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson('/api/v1/class-sessions/batch', [
            'class_type' => 'one_on_one', 'trial_target_class_type' => 'one_on_three',
        ])->assertStatus(422)->assertJsonValidationErrors(['trial_target_class_type']);

        $this->withHeaders($headers)->postJson('/api/v1/class-sessions/batch', [
            'class_type' => 'trial', 'trial_target_class_type' => 'one_on_three',
        ])->assertStatus(422)->assertJsonMissingValidationErrors(['trial_target_class_type']);
    }

    public function test_trial_target_class_type_rejected_on_non_trial_course_edit(): void
    {
        $token = $this->createDirectorToken([1], 'director-guard-tt-edit@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-guard-tt-edit@example.com');
        $studentId = $this->createStudent(1, '編輯生')->id;
        $courseId = $this->insertTrialCourse($studentId, $teacherId, null);
        DB::table('StudentClass')->where('ID', $courseId)->update(['ClassType' => 'one_on_one']);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson('/api/v1/student-classes/' . $courseId, ['trial_target_class_type' => 'one_on_three'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_trial_target_class_type');
        $this->assertNull(DB::table('StudentClass')->where('ID', $courseId)->value('trial_target_class_type'));
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
        DB::table('ClassSession')->insert([
            'StudentClassID' => $scXId, 'SessionDate' => '2026-04-23',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
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
     * Insert a one_on_one StudentClass with a caller-supplied StartDate — used to
     * reproduce two overlapping courses left active after a renewal (#2006).
     */
    private function createOneOnOneCourse(int $studentId, int $teacherId, string $startTime, string $startDate): int
    {
        return (int) StudentClass::query()->insertGetId([
            'StudentID' => $studentId,
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one',
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $startDate,
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
            'week' => 6,
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
