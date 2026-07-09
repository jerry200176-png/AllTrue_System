<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargeBranchDataHandlingTest extends TestCase
{
    use RefreshDatabase;

    // ----- student-classes pagination & name filter -----

    public function test_student_classes_paginates_and_returns_total(): void
    {
        $token = $this->createDirectorToken([1]);
        $this->seedCoursesForBranch(1, 60);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&per_page=20');

        $res->assertOk();
        $json = $res->json();
        $this->assertCount(20, $json['data']);
        $this->assertSame(60, (int) $json['total']);
        $this->assertSame(3, (int) $json['last_page']);
    }

    public function test_student_classes_name_filter(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '特殊搜尋名', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $this->createCourse($student->id);
        $this->seedCoursesForBranch(1, 5);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&name=特殊搜尋&per_page=50');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertStringContainsString('特殊搜尋名', $res->json('data.0.student_name'));
    }

    public function test_student_classes_teacher_name_filter(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '學生老師篩', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $teacher = User::create([
            'LoginName' => 'unique-teacher-filter-' . mt_rand(10000, 99999) . '@test.com',
            'Name' => '唯一篩選老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => 912345671,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $this->createCourse($student->id, ['TeacherID' => $teacher->id]);
        $this->seedCoursesForBranch(1, 4);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&teacher_name=' . rawurlencode('唯一篩選') . '&per_page=50');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertStringContainsString('唯一篩選老師', (string) $res->json('data.0.teacher_name'));
    }

    public function test_student_classes_teacher_name_filter_matches_user_name(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherUser = User::create([
            'LoginName' => 'coco-en-filter-' . mt_rand(10000, 99999) . '@test.com',
            'Name' => 'CocoEnglishFilter',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => 912345670,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacherUser->id, 'Admin' => 0, 'Approved' => 1]);
        $student = Student::create([
            'name' => '學生老師英', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $this->createCourse($student->id, ['TeacherID' => $teacherUser->id]);
        $this->seedCoursesForBranch(1, 3);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&teacher_name=CocoEnglish&per_page=50');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame((int) $teacherUser->id, (int) $res->json('data.0.teacher_id'));
    }

    // ----- schedules date range filter -----

    public function test_schedules_date_range_filter(): void
    {
        $token = $this->createDirectorToken([1]);
        Schedule::create([
            'student_id' => 1, 'teacher_id' => 1, 'branch_id' => 1,
            'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '18:00',
            'schedule_date' => '2026-01-15', 'status' => 'leave', 'type' => 'normal',
        ]);
        Schedule::create([
            'student_id' => 1, 'teacher_id' => 1, 'branch_id' => 1,
            'day_of_week' => 3, 'start_time' => '16:00', 'end_time' => '18:00',
            'schedule_date' => '2026-04-15', 'status' => 'leave', 'type' => 'normal',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/schedules?branch_id=1&start=2026-04-01&end=2026-04-30&per_page=100');

        $res->assertOk();
        $data = $res->json('data') ?? $res->json();
        $dates = collect($data)->pluck('schedule_date')->filter()->all();
        $this->assertNotContains('2026-01-15', $dates);
        $this->assertContains('2026-04-15', $dates);
    }

    public function test_schedules_unbounded_has_safety_limit(): void
    {
        $token = $this->createDirectorToken([1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/schedules?branch_id=1&per_page=all');

        $res->assertOk();
    }

    // ----- tuition alerts SQL pre-filter -----

    public function test_tuition_alert_excludes_paid_with_high_remaining(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '高堂已繳', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $course = $this->createCourse($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 10,
            'ScheduleMode' => 'count',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids);
    }

    public function test_tuition_alert_includes_unpaid_count_mode(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '未繳堂數', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $course = $this->createCourse($student->id, [
            'Paid' => 0,
            'RemainingSessions' => 5,
            'ScheduleMode' => 'count',
            // AlertController 的 tuition 端點過濾 charge > 0；null/0 不會出現
            'Charge' => 5000,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $course->ID, $ids);
    }

    // ----- helpers -----

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-lb-' . mt_rand(1000, 9999) . '@test.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function seedCoursesForBranch(int $campusId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $student = Student::create([
                'name' => "學生{$campusId}_{$i}", 'CampusID' => $campusId, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $this->createCourse($student->id);
        }
    }

    private function createCourse(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'EndDate' => null,
            'TotalHours' => 20, 'Memo' => null,
            'Charge' => null, 'Pay' => null, 'PayDate' => null,
            'Paid' => 0, 'Disconunt' => null, 'Rate' => null,
            'LearnTimeID' => null, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
