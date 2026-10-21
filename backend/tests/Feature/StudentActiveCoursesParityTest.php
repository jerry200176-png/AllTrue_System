<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * in-app #427: GET students/{id}/active-courses (the 「已有進行中課程」 prompt) lists only what the
 * 學生管理 course list shows as 進行中, with the same remaining number.
 */
class StudentActiveCoursesParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_lists_only_running_courses_with_the_corrected_remaining(): void
    {
        $home = Campus::factory()->create();
        $other = Campus::factory()->create();
        $student = Student::create([
            'name' => '進行中測試生', 'CampusID' => $home->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);

        // Running: 8 bought, one attended lesson, stored remaining stale at 8 -> card shows 7.
        $open = $this->course($student, ['SessionCount' => 8, 'RemainingSessions' => 8, 'Charge' => 8000, 'Paid' => 0]);
        $this->attended($open);

        // Finished trial: stored remaining still 1, but the one lesson was attended and it is free -> history.
        $finished = $this->course($student, ['ClassType' => 'trial', 'SessionCount' => 1, 'RemainingSessions' => 1]);
        $this->attended($finished);

        // Closed but Stop never set (legacy shape): never 進行中.
        $converted = $this->course($student, ['closed_reason' => 'converted_trial', 'RemainingSessions' => 1, 'SessionCount' => 1]);
        $settledPending = $this->course($student, ['closed_reason' => 'settled_pending', 'Charge' => 8000]);

        // Stopped course.
        $stopped = $this->course($student, ['Stop' => 1, 'Charge' => 8000]);

        // Course held in another campus's room: not on this campus's student page.
        $otherRoom = Room::create(['name' => '他校教室', 'campus_id' => $other->id, 'capacity' => 4, 'is_active' => true]);
        $otherCampus = $this->course($student, ['room_id' => $otherRoom->id, 'Charge' => 8000]);

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->directorToken([(int) $home->id, (int) $other->id]),
            'Accept' => 'application/json',
        ])->getJson("/api/v1/students/{$student->id}/active-courses?branch_id={$home->id}");

        // branch_id narrows the prompt to one campus, like the campus course list.
        $res->assertOk();
        $courses = collect($res->json('courses'))->keyBy('id');
        $this->assertSame([(int) $open->ID], $courses->keys()->map(fn ($id) => (int) $id)->all());
        $this->assertSame(7, $courses[$open->ID]['remaining_sessions']);
        foreach ([$finished, $converted, $settledPending, $stopped, $otherCampus] as $hidden) {
            $this->assertFalse($courses->has($hidden->ID), "course {$hidden->ID} must not be offered as 進行中");
        }

        // The student page's own list (same campus) agrees on the running course and its number.
        $page = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->directorToken([(int) $home->id, (int) $other->id]),
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes?branch_id={$home->id}&student_id={$student->id}&per_page=200");
        $pageOpen = collect($page->json('data'))->first(fn ($row) => (int) ($row['ID'] ?? $row['id']) === (int) $open->ID);
        $this->assertSame(7, (int) $pageOpen['remaining_sessions']);
    }

    public function test_without_branch_the_prompt_covers_every_visible_campus(): void
    {
        $home = Campus::factory()->create();
        $other = Campus::factory()->create();
        $student = Student::create([
            'name' => '跨校測試生', 'CampusID' => $home->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
        $local = $this->course($student, ['Charge' => 8000]);
        $otherRoom = Room::create(['name' => '他校教室', 'campus_id' => $other->id, 'capacity' => 4, 'is_active' => true]);
        $elsewhere = $this->course($student, ['room_id' => $otherRoom->id, 'Charge' => 8000]);

        // Same scope as the expanded student view (student_id only) and the create-time guard.
        $ids = collect($this->withHeaders([
            'Authorization' => 'Bearer '.$this->directorToken([(int) $home->id, (int) $other->id]),
            'Accept' => 'application/json',
        ])->getJson("/api/v1/students/{$student->id}/active-courses")->assertOk()->json('courses'))
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([(int) $local->ID, (int) $elsewhere->ID], $ids);
    }

    public function test_create_guard_does_not_block_on_a_finished_course(): void
    {
        $campus = Campus::factory()->create();
        $teacher = User::create([
            'LoginName' => 'active-courses-teacher-'.uniqid().'@example.com', 'Name' => '測試老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000',
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $student = Student::create([
            'name' => '建課守門測試生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->directorToken([(int) $campus->id]), 'Accept' => 'application/json'];
        $payload = fn (int $days, string $start) => [
            'branch_id' => $campus->id, 'student_id' => $student->id, 'teacher_id' => $teacher->id,
            'subject' => 'Math', 'class_type' => 'one_on_one', 'total_classes' => 1,
            'confirmed_dates' => [], 'future_dates' => [now()->addDays($days)->toDateString()],
            'days_of_week' => [(int) now()->addDays($days)->dayOfWeekIso], 'start_time' => $start,
            'duration_minutes' => 60, 'price_per_session' => 500, 'payment_type' => 'session',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/class-sessions/batch', $payload(7, '14:00'));
        $first->assertCreated();
        $courseId = (int) $first->json('student_class_id');

        // While it runs, a same-subject same-type course is still a duplicate.
        $this->withHeaders($headers)->postJson('/api/v1/class-sessions/batch', $payload(8, '16:00'))
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_active_course');

        // Finished: the one lesson was taken and it is paid (legacy flag, no open bill), Stop never set.
        \App\Models\Invoice::where('StudentClassID', $courseId)->update(['Status' => 'void']);
        StudentClass::where('ID', $courseId)->update(['Charge' => 500, 'Paid' => 1, 'SessionCount' => 1, 'RemainingSessions' => 1, 'Stop' => 0]);
        $this->attended(StudentClass::findOrFail($courseId));
        $this->withHeaders($headers)->getJson("/api/v1/students/{$student->id}/active-courses")
            ->assertOk()->assertJsonCount(0, 'courses');

        $second = $this->withHeaders($headers)->postJson('/api/v1/class-sessions/batch', $payload(8, '16:00'));
        $this->assertNotSame('duplicate_active_course', $second->json('code'), (string) $second->getContent());
        $second->assertCreated();
    }

    public function test_teacher_precheck_discloses_its_scope_but_not_hidden_course_existence(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '範圍測試生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
        $teacher = User::create([
            'LoginName' => 'scope-teacher-'.uniqid().'@example.com', 'Name' => '測試老師',
            'PSW' => 'secret', 'type' => 'T', 'phone' => '0922000000',
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $hidden = $this->course($student, ['TeacherID' => $teacher->id + 1000, 'Charge' => 8000]);
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $this->withHeaders($headers)->getJson("/api/v1/students/{$student->id}/active-courses")
            ->assertOk()->assertExactJson(['courses' => [], 'precheck_scope' => 'teacher_courses']);
        // Even existence is private: stopping the hidden row must not change the response.
        $hidden->update(['Stop' => 1]);
        $this->withHeaders($headers)->getJson("/api/v1/students/{$student->id}/active-courses")
            ->assertOk()->assertExactJson(['courses' => [], 'precheck_scope' => 'teacher_courses']);

        $outside = Campus::factory()->create();
        $student->update(['CampusID' => $outside->id]);
        $this->withHeaders($headers)->getJson("/api/v1/students/{$student->id}/active-courses")->assertForbidden();
    }

    private function course(Student $student, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1,
            'Period' => 4, 'StartDate' => now()->subMonth(), 'TotalHours' => 0, 'Charge' => 0, 'Paid' => 0,
            'Rate' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 60, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }

    private function attended(StudentClass $course): void
    {
        ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => now()->subDays(3)->toDateString(),
            'StartTime' => '16:00', 'EndTime' => '17:00', 'Status' => 'completed', 'Note' => '',
        ]);
    }

    private function directorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'active-courses-'.uniqid().'@example.com', 'Name' => '測試主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0900000000',
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
