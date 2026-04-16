<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseStartDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_sessions_respect_course_start_date(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-csd-1@example.com');
        $teacherId = $this->createTeacher(1, 'teach-csd-1@example.com');
        $student = $this->createStudent(1, '開課日測試');

        $courseStart = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY);
        $courseStartDate = $courseStart->toDateString();
        $dow = (int) $courseStart->dayOfWeekIso; // 3 = Wednesday

        $futureDates = [];
        $cursor = $courseStart->copy();
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $cursor->toDateString();
            $cursor->addWeek();
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => array_map(fn ($d) => [
                'session_date' => $d,
                'start_time' => '16:00',
                'kind' => 'future',
                'subject' => 'Math',
            ], $futureDates),
            'days_of_week' => [$dow],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'course_start_date' => $courseStartDate,
        ]);

        $res->assertCreated();
        $scId = (int) $res->json('student_class_id');
        $this->assertGreaterThan(0, $scId);

        $sessions = ClassSession::where('StudentClassID', $scId)
            ->orderBy('SessionDate')
            ->get();
        $this->assertCount(4, $sessions);

        foreach ($sessions as $session) {
            $sessionDate = substr((string) $session->SessionDate, 0, 10);
            $this->assertGreaterThanOrEqual(
                $courseStartDate,
                $sessionDate,
                "Session {$sessionDate} must be on or after course_start_date {$courseStartDate}"
            );
            $this->assertEquals('scheduled', (string) $session->Status);
        }
    }

    public function test_rejects_future_session_before_course_start_date(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-csd-2@example.com');
        $teacherId = $this->createTeacher(1, 'teach-csd-2@example.com');
        $student = $this->createStudent(1, '開課日驗證');

        $courseStart = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY);
        $courseStartDate = $courseStart->toDateString();

        $earlyDate = Carbon::now()->addDays(3);
        while ((int) $earlyDate->dayOfWeekIso !== 3) {
            $earlyDate->addDay();
        }
        $earlyDateStr = $earlyDate->toDateString();

        $this->assertTrue($earlyDateStr < $courseStartDate, 'Early date must be before course start');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 2,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => [
                [
                    'session_date' => $earlyDateStr,
                    'start_time' => '16:00',
                    'kind' => 'future',
                    'subject' => 'Math',
                ],
                [
                    'session_date' => $courseStartDate,
                    'start_time' => '16:00',
                    'kind' => 'future',
                    'subject' => 'Math',
                ],
            ],
            'days_of_week' => [3],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'course_start_date' => $courseStartDate,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('開課日', $res->json('message'));
    }

    public function test_no_course_start_date_preserves_existing_behavior(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-csd-3@example.com');
        $teacherId = $this->createTeacher(1, 'teach-csd-3@example.com');
        $student = $this->createStudent(1, '無開課日');

        $futureDate = Carbon::now()->addDays(7);
        while ((int) $futureDate->dayOfWeekIso !== 3) {
            $futureDate->addDay();
        }
        $futureDateStr = $futureDate->toDateString();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => [
                [
                    'session_date' => $futureDateStr,
                    'start_time' => '16:00',
                    'kind' => 'future',
                    'subject' => 'Math',
                ],
            ],
            'days_of_week' => [3],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertCreated();
        $this->assertEquals(1, $res->json('created_future_sessions'));
    }

    private function createUserToken(string $type, array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => $type === 'T' ? '老師測試' : '主任測試',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::firstOrCreate([
                'CampusID' => $campusId,
                'UserID' => $user->id,
            ], [
                'Admin' => $type === 'A' ? 1 : 0,
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
            'phone' => '0922111111',
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
}
