<?php

namespace Tests\Feature;

use App\Models\{AuthToken, Campus, Student, StudentClass, User, UserCampus};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassCourseLinkLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_link_finds_an_older_roomless_course_beyond_the_first_student_page(): void
    {
        $campus = Campus::factory()->create();
        $student = $this->makeStudent((int) $campus->id);
        $old = $this->makeCourse($student);
        for ($i = 0; $i < 55; $i++) $this->makeCourse($student);

        $response = $this->getJson('/api/v1/student-classes?' . http_build_query([
            'campus_id' => $campus->id, 'student_id' => $student->id,
            'course_id' => $old->ID, 'page' => 1, 'per_page' => 50,
        ]), $this->headersForCampus((int) $campus->id))->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (int) $old->ID)
            ->assertJsonPath('data.0.branch_id', null)
            ->assertJsonPath('data.0.student.CampusID', (int) $campus->id);
    }

    public function test_exact_filter_intersects_student_and_authorized_campus_scope(): void
    {
        $campus = Campus::factory()->create();
        $otherCampus = Campus::factory()->create();
        $student = $this->makeStudent((int) $campus->id);
        $other = $this->makeStudent((int) $campus->id);
        $foreign = $this->makeStudent((int) $otherCampus->id);
        $otherCourse = $this->makeCourse($other);
        $foreignCourse = $this->makeCourse($foreign);
        $headers = $this->headersForCampus((int) $campus->id);

        $this->getJson("/api/v1/student-classes?student_id={$student->id}&course_id={$otherCourse->ID}", $headers)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/student-classes?campus_id={$otherCampus->id}&student_id={$foreign->id}&course_id={$foreignCourse->ID}", $headers)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/student-classes?course_id={$foreignCourse->ID}", $this->headersForCampus(null))
            ->assertForbidden();
    }

    public function test_exact_filter_rejects_invalid_course_identifiers(): void
    {
        $campus = Campus::factory()->create();
        $headers = $this->headersForCampus((int) $campus->id);
        foreach (['0', '-1', '1.5', 'junk'] as $id) {
            $this->getJson('/api/v1/student-classes?course_id=' . rawurlencode($id), $headers)->assertUnprocessable();
        }
    }

    private function makeStudent(int $campusId): Student
    {
        return Student::create(['name' => 'Synthetic course link student', 'CampusID' => $campusId,
            'ClassID' => 1, 'SchoolName' => 'Synthetic', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function makeCourse(Student $student): StudentClass
    {
        return StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 99, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-10-03', 'TotalHours' => 16, 'Charge' => 800, 'Rate' => 100,
            'Paid' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'ClassType' => 'one_on_one',
            'SessionCount' => 8, 'UsedSessions' => 0, 'RemainingSessions' => 8, 'SessionDuration' => 120, 'room_id' => null]);
    }

    private function headersForCampus(?int $campusId): array
    {
        $user = User::create(['LoginName' => 'course-link-' . uniqid() . '@example.test', 'Name' => 'Synthetic director',
            'PSW' => 'synthetic', 'type' => 'A', 'phone' => '0900000000']);
        if ($campusId !== null) UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['Authorization' => 'Bearer ' . $token];
    }
}
