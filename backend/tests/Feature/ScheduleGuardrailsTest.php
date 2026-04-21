<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
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
                'RoomID'          => '',
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
