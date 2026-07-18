<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleStoreContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_schedules_with_flat_object_body_succeeds(): void
    {
        $token = $this->createDirectorToken([1], 'dir-contract@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-contract@example.com');
        $student = $this->createStudent(1, '契約測試');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-15',
        ])->assertCreated();
    }

    public function test_post_schedules_with_array_body_unwraps_and_succeeds(): void
    {
        $token = $this->createDirectorToken([1], 'dir-contract-arr@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-contract-arr@example.com');
        $student = $this->createStudent(1, '陣列測試');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [[
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-15',
        ]])->assertCreated();
    }

    public function test_rescheduled_slot_retries_reuse_one_marker_and_preserve_other_same_day_slots(): void
    {
        $token = $this->createDirectorToken([1], 'dir-reschedule-idempotent@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-reschedule-idempotent@example.com');
        $student = $this->createStudent(1, '重試測試');
        $course = $this->createCourse($student, $teacherId);

        Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-15',
            'student_course_id' => $course->ID,
        ]);

        $payload = [
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-15',
            'student_course_id' => $course->ID,
        ];

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', $payload)->assertCreated();
        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', $payload)->assertCreated();

        $this->assertSame('created', $first->json('write_disposition'));
        $this->assertSame('reused', $second->json('write_disposition'));
        $this->assertSame((int) $first->json('id'), (int) $second->json('id'));
        $this->assertSame(1, Schedule::where('student_course_id', $course->ID)
            ->whereDate('schedule_date', '2026-04-15')
            ->where('status', 'rescheduled')
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', ['14:00'])
            ->count());
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $course->ID,
            'schedule_date' => '2026-04-15',
            'status' => 'scheduled',
            'start_time' => '16:00',
        ]);
    }

    public function test_duplicate_reschedule_cleanup_reanchors_existing_destination(): void
    {
        $token = $this->createDirectorToken([1], 'dir-reschedule-reanchor@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-reschedule-reanchor@example.com');
        $student = $this->createStudent(1, '錨點修復測試');
        $course = $this->createCourse($student, $teacherId);
        $base = [
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-15',
            'student_course_id' => $course->ID,
        ];
        $oldAnchor = Schedule::create($base);
        $keptAnchor = Schedule::create($base);
        $destination = Schedule::create(array_merge($base, [
            'day_of_week' => 4,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'status' => 'scheduled',
            'deduction' => 1,
            'schedule_date' => '2026-04-16',
            'original_schedule_id' => $oldAnchor->id,
        ]));

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', $base)->assertCreated();

        $this->assertSame('reused', $response->json('write_disposition'));
        $this->assertSame($keptAnchor->id, (int) $response->json('id'));
        $this->assertSame(1, Schedule::where('student_course_id', $course->ID)
            ->whereDate('schedule_date', '2026-04-15')
            ->where('status', 'rescheduled')
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', ['14:00'])
            ->count());
        $this->assertSame($keptAnchor->id, (int) $destination->fresh()->original_schedule_id);
    }

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

    private function createCourse(Student $student, int $teacherId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 16,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Charge' => 1600,
            'Pay' => 12800,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
        ]);
    }
}
