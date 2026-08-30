<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_index_has_stable_rows_and_pagination_contract(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff($campus);
        $student = Student::create([
            'name' => '契約學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(),
        ]);
        $course = $this->makeClass($student->id, $director['user']->id, $campus);

        Assessment::create([
            'campus_id' => $campus, 'student_class_id' => $course->ID, 'title' => '較早檢測',
            'assessment_type' => 'baseline', 'status' => 'draft', 'scheduled_for' => '2026-08-10',
            'max_score' => 50, 'passing_score' => 30, 'created_by_user_id' => $director['user']->id,
        ]);
        Assessment::create([
            'campus_id' => $campus, 'student_class_id' => $course->ID, 'title' => '較新檢測',
            'assessment_type' => 'checkpoint', 'status' => 'published', 'scheduled_for' => '2026-08-20',
            'max_score' => 100, 'passing_score' => 60, 'created_by_user_id' => $director['user']->id,
        ]);

        $response = $this->withAuth($director['token'])
            ->getJson('/api/v1/assessments?campus_id=' . $campus . '&per_page=1');

        $response->assertOk()->assertJsonStructure([
            'data' => [['id', 'campus_id', 'subject_id', 'student_class_id', 'title', 'description',
                'assessment_type', 'status', 'scheduled_for', 'max_score', 'passing_score',
                'result_count', 'student_name', 'created_at']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.title', '較新檢測')
            ->assertJsonPath('data.0.assessment_type', 'checkpoint')
            ->assertJsonPath('data.0.scheduled_for', '2026-08-20')
            ->assertJsonPath('data.0.max_score', 100)
            ->assertJsonPath('data.0.passing_score', 60)
            ->assertJsonPath('data.0.result_count', 0)
            ->assertJsonPath('data.0.student_name', '契約學生');

        $row = $response->json('data.0');
        $this->assertIsInt($row['id']);
        $this->assertIsInt($row['campus_id']);
        $this->assertIsInt($row['student_class_id']);
        $this->assertIsNumeric($row['max_score']);
        $this->assertIsNumeric($row['passing_score']);
        $this->assertIsInt($row['result_count']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $row['created_at']);
    }

    public function test_assessment_show_keeps_result_count_excluding_voided_results(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff($campus);
        $student = Student::create([
            'name' => '結果學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(),
        ]);
        $course = $this->makeClass($student->id, $director['user']->id, $campus);
        $assessment = Assessment::create([
            'campus_id' => $campus, 'student_class_id' => $course->ID, 'title' => '結果檢測',
            'assessment_type' => 'checkpoint', 'status' => 'published', 'scheduled_for' => '2026-08-21',
            'max_score' => 100, 'passing_score' => 60, 'created_by_user_id' => $director['user']->id,
        ]);
        $this->makeResult($assessment, $student, $course, 'submitted');
        $this->makeResult($assessment, $student, $course, 'voided', 2);

        $response = $this->withAuth($director['token'])
            ->getJson('/api/v1/assessments/' . $assessment->id);

        $response->assertOk()->assertJsonStructure([
            'data' => ['id', 'campus_id', 'subject_id', 'student_class_id', 'title', 'description',
                'assessment_type', 'status', 'scheduled_for', 'max_score', 'passing_score',
                'result_count', 'student_name', 'created_at'],
        ])->assertJsonPath('data.id', $assessment->id)
            ->assertJsonPath('data.title', '結果檢測')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.result_count', 1)
            ->assertJsonPath('data.student_name', '結果學生');
    }

    private function makeCampus(): int
    {
        return (int) CampusFactory::new()->create(['name' => '檢測契約分校 ' . Str::random(5)])->id;
    }

    /** @return array{token:string,user:User} */
    private function makeStaff(int $campus): array
    {
        $user = User::create([
            'LoginName' => 'assessment-contract-' . Str::random(8) . '@example.com',
            'Name' => '檢測契約主任', 'PSW' => 'secret', 'type' => 'A',
            'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => ($token = bin2hex(random_bytes(16))), 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user' => $user];
    }

    private function makeClass(int $studentId, int $teacherId, int $campus): StudentClass
    {
        $class = new StudentClass();
        $class->StudentID = $studentId; $class->TeacherID = $teacherId; $class->GradeID = 1;
        $class->SubjectID = 1; $class->by1 = $campus; $class->StartDate = '2026-08-01';
        $class->TotalHours = 20; $class->Charge = 1000; $class->Pay = 0; $class->Paid = 0;
        $class->Rate = 1000; $class->Stop = 0; $class->SessionCount = 10;
        $class->RemainingSessions = 10; $class->UsedSessions = 0; $class->SessionDuration = 60;
        $class->ClassType = 'one_on_one'; $class->save();
        return $class->fresh();
    }

    private function makeResult(Assessment $assessment, Student $student, StudentClass $course, string $status, int $attemptNo = 1): void
    {
        AssessmentResult::create([
            'assessment_id' => $assessment->id, 'student_id' => $student->id, 'student_class_id' => $course->ID,
            'attempt_no' => $attemptNo, 'score' => 80, 'max_score_snapshot' => 100, 'percent' => 80,
            'status' => $status, 'recorded_by_user_id' => $assessment->created_by_user_id, 'recorded_at' => now(),
        ]);
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
    }
}
