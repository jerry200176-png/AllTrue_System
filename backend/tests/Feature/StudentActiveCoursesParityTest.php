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

    public function test_branch_defaults_to_the_student_campus(): void
    {
        $home = Campus::factory()->create();
        $other = Campus::factory()->create();
        $student = Student::create([
            'name' => '預設分校測試生', 'CampusID' => $home->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
        $local = $this->course($student, ['Charge' => 8000]);
        $otherRoom = Room::create(['name' => '他校教室', 'campus_id' => $other->id, 'capacity' => 4, 'is_active' => true]);
        $this->course($student, ['room_id' => $otherRoom->id, 'Charge' => 8000]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->directorToken([(int) $home->id, (int) $other->id]),
            'Accept' => 'application/json',
        ])->getJson("/api/v1/students/{$student->id}/active-courses")
            ->assertOk()
            ->assertJsonCount(1, 'courses')
            ->assertJsonPath('courses.0.id', $local->ID);
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
