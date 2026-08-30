<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_index_has_pagination_and_normalized_course_row_contract(): void
    {
        [$token, $director] = $this->makeUserToken(1, 'student-class-director@test.com', 'A');
        [, $teacher] = $this->makeUserToken(1, 'student-class-teacher@test.com', 'T');
        $older = $this->makeCourse($teacher, 1, ['student_name' => '舊課程學生']);
        $newer = $this->makeCourse($teacher, 1, [
            'student_name' => '最新課程學生',
            'week' => 2,
            'time' => '15:30:00',
            'SessionDuration' => 90,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
        ]);

        $response = $this->authJson('GET', '/api/v1/student-classes?branch_id=1&per_page=1', $token);

        $response->assertOk()
            ->assertJsonStructure([
                'current_page', 'data', 'per_page', 'total', 'last_page',
                'data' => [[
                    'ID', 'id', 'student_id', 'teacher_id', 'student_name',
                    'subject', 'class_type', 'duration_hours', 'days_of_week',
                    'day_time_slots', 'start_time', 'end_time', 'payment_type',
                    'sessions_purchased', 'sessions_used', 'remaining_sessions',
                    'payment_status', 'status', 'first_class_date',
                ]],
            ])
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.ID', $newer->ID)
            ->assertJsonPath('data.0.id', $newer->ID)
            ->assertJsonPath('data.0.student_name', '最新課程學生')
            ->assertJsonPath('data.0.teacher_id', $teacher->id)
            ->assertJsonPath('data.0.class_type', 'one_on_one')
            ->assertJsonPath('data.0.start_time', '15:30')
            ->assertJsonPath('data.0.end_time', '17:00')
            ->assertJsonPath('data.0.payment_type', 'session')
            ->assertJsonPath('data.0.sessions_purchased', 8)
            ->assertJsonPath('data.0.remaining_sessions', 8)
            ->assertJsonPath('data.0.status', 'active');

        $row = $response->json('data.0');
        $this->assertIsInt($row['id']);
        $this->assertIsInt($row['student_id']);
        $this->assertIsInt($row['teacher_id']);
        $this->assertIsArray($row['days_of_week']);
        $this->assertSame([2], $row['days_of_week']);
        $this->assertSame(2, $row['day_time_slots'][0]['day']);
        $this->assertSame('15:30', $row['day_time_slots'][0]['start_time']);
        $this->assertIsNumeric($row['duration_hours']);
        $this->assertNotSame($older->ID, $row['id']);
    }

    public function test_teacher_read_masks_payment_account_suffix_and_excludes_other_teachers_courses(): void
    {
        [$teacherToken, $teacher] = $this->makeUserToken(1, 'student-class-own-teacher@test.com', 'T');
        [, $otherTeacher] = $this->makeUserToken(1, 'student-class-other-teacher@test.com', 'T');
        $ownCourse = $this->makeCourse($teacher, 1, ['student_name' => '自己的學生']);
        $this->makeCourse($otherTeacher, 1, ['student_name' => '別人的學生']);

        PaymentReport::create([
            'StudentID' => $ownCourse->StudentID,
            'StudentClassID' => $ownCourse->ID,
            'reported_by_name' => '家長',
            'payment_date' => '2026-08-30',
            'payment_method' => 'transfer',
            'reported_amount' => 1250,
            'account_last5' => '12345',
            'note' => '  已匯款  ',
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'student-class-contract'),
            'token_expires_at' => now()->addDay(),
        ]);

        $response = $this->authJson('GET', '/api/v1/student-classes?per_page=10', $teacherToken);

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ownCourse->ID)
            ->assertJsonPath('data.0.student_name', '自己的學生')
            ->assertJsonPath('data.0.payment_status', 'pending_report')
            ->assertJsonPath('data.0.latest_payment_summary.status', 'pending')
            ->assertJsonPath('data.0.latest_payment_summary.payment_date', '2026-08-30')
            ->assertJsonPath('data.0.latest_payment_summary.account_last5', null)
            ->assertJsonPath('data.0.latest_payment_summary.note', '已匯款');

        $summary = $response->json('data.0.latest_payment_summary');
        $this->assertIsInt($summary['report_id']);
        $this->assertIsNumeric($summary['amount']);
    }

    public function test_director_campus_scope_never_leaks_other_campus_courses(): void
    {
        [$token, $director] = $this->makeUserToken(1, 'student-class-campus-director@test.com', 'A');
        [, $teacher] = $this->makeUserToken(1, 'student-class-campus-teacher@test.com', 'T');
        $this->makeCourse($teacher, 1, ['student_name' => '校區一學生']);
        $this->makeCourse($teacher, 2, ['student_name' => '校區二學生']);

        $defaultScope = $this->authJson('GET', '/api/v1/student-classes?per_page=10', $token);
        $defaultScope->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.student_name', '校區一學生');

        $foreignScope = $this->authJson('GET', '/api/v1/student-classes?branch_id=2&per_page=10', $token);
        $foreignScope->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);
    }

    private function authJson(string $method, string $url, string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->json($method, $url);
    }

    private function makeUserToken(int $campusId, string $loginName, string $type): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'SC ' . substr($loginName, 0, 20),
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $user->id,
            'Admin' => $type === 'A' ? 1 : 0,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return [$token, $user];
    }

    private function makeCourse(User $teacher, int $campusId, array $overrides = []): StudentClass
    {
        $student = Student::create([
            'name' => $overrides['student_name'] ?? '測試學生',
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        unset($overrides['student_name']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-08-01',
            'TotalHours' => 12,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 6,
            'RemainingSessions' => 6,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'week' => 1,
            'time' => '14:00:00',
        ], $overrides));
    }
}
