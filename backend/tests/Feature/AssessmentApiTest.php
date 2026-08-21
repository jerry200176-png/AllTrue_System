<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentRemediationAction;
use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Database\Factories\CampusFactory;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_publish_result_and_review_without_touching_learning_records(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'assessment-director@example.com', $campus);
        $teacher = $this->makeStaff('T', 'assessment-teacher@example.com', $campus);
        $student = Student::create([
            'name' => '檢測學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(),
        ]);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);

        $created = $this->withAuth($director['token'])->postJson('/api/v1/assessments', [
            'campus_id' => $campus,
            'student_class_id' => $course->ID,
            'title' => '英文基礎檢測',
            'assessment_type' => 'baseline',
            'max_score' => 50,
            'passing_score' => 30,
        ])->assertCreated();
        $assessmentId = (int) $created->json('data.id');

        $this->withAuth($director['token'])
            ->postJson("/api/v1/assessments/{$assessmentId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $result = $this->withAuth($teacher['token'])->postJson("/api/v1/assessments/{$assessmentId}/results", [
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'score' => 42,
            'notes' => '字彙表現穩定',
        ])->assertCreated();
        $resultId = (int) $result->json('data.id');

        $result->assertJsonPath('data.attempt_no', 1)
            ->assertJsonPath('data.max_score', 50)
            ->assertJsonPath('data.percent', 84);
        $this->assertDatabaseCount('LearningRecord', 0);
        $this->assertDatabaseHas('assessment_audit_logs', [
            'assessment_id' => $assessmentId,
            'assessment_result_id' => $resultId,
            'action' => 'result_created',
        ]);

        $this->withAuth($director['token'])
            ->postJson("/api/v1/assessment-results/{$resultId}/review")
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewed');

        $this->withAuth($director['token'])
            ->getJson('/api/v1/assessment-reports/summary?campus_id=' . $campus)
            ->assertOk()
            ->assertJsonPath('data.assessment_count', 1)
            ->assertJsonPath('data.result_count', 1)
            ->assertJsonPath('data.average_percent', 84);
    }

    public function test_teacher_cannot_cross_campus_or_create_unscoped_assessment(): void
    {
        $campusA = $this->makeCampus();
        $campusB = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'assessment-teacher-scope@example.com', $campusA);

        $this->withAuth($teacher['token'])->postJson('/api/v1/assessments', [
            'campus_id' => $campusB,
            'title' => '不應建立',
            'max_score' => 100,
        ])->assertForbidden();

        $this->withAuth($teacher['token'])->postJson('/api/v1/assessments', [
            'campus_id' => $campusA,
            'title' => '需要課程',
            'max_score' => 100,
        ])->assertStatus(422);
    }

    public function test_result_attempts_are_unique_and_scores_are_bounded(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'assessment-director-attempt@example.com', $campus);
        $student = Student::create([
            'name' => '重測學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(),
        ]);
        $course = $this->makeClass($student->id, $director['user']->id, $campus);
        $assessment = Assessment::create([
            'campus_id' => $campus,
            'student_class_id' => $course->ID,
            'title' => '數學檢測',
            'assessment_type' => 'checkpoint',
            'status' => 'published',
            'max_score' => 100,
            'created_by_user_id' => $director['user']->id,
        ]);

        $this->withAuth($director['token'])->postJson("/api/v1/assessments/{$assessment->id}/results", [
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'score' => 101,
        ])->assertStatus(422);

        $this->withAuth($director['token'])->postJson("/api/v1/assessments/{$assessment->id}/results", [
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'score' => 88,
            'attempt_no' => 1,
        ])->assertCreated();

        $this->withAuth($director['token'])->postJson("/api/v1/assessments/{$assessment->id}/results", [
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'score' => 90,
            'attempt_no' => 1,
        ])->assertStatus(409);

        $this->withAuth($director['token'])->postJson("/api/v1/assessments/{$assessment->id}/results", [
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'score' => 90,
        ])->assertCreated()->assertJsonPath('data.attempt_no', 2);
    }

    public function test_remediation_actions_track_knowledge_gap_and_completion_without_learning_record_side_effects(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'assessment-remediation@example.com', $campus);
        $student = Student::create([
            'name' => '需要補強學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(),
        ]);
        $course = $this->makeClass($student->id, $director['user']->id, $campus);
        $assessment = Assessment::create([
            'campus_id' => $campus,
            'student_class_id' => $course->ID,
            'title' => '英文診斷',
            'assessment_type' => 'baseline',
            'status' => 'published',
            'max_score' => 100,
            'created_by_user_id' => $director['user']->id,
        ]);

        $result = $this->withAuth($director['token'])->postJson("/api/v1/assessments/{$assessment->id}/results", [
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'score' => 48,
        ])->assertCreated();
        $resultId = (int) $result->json('data.id');

        $action = $this->withAuth($director['token'])->postJson("/api/v1/assessment-results/{$resultId}/remediation-actions", [
            'knowledge_tag' => '英文／過去式',
            'action_type' => 'practice',
            'plan' => '完成兩回過去式句型練習並由老師口頭抽問',
            'due_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.status', AssessmentRemediationAction::STATUS_OPEN)
            ->assertJsonPath('data.knowledge_tag', '英文／過去式');
        $actionId = (int) $action->json('data.id');

        $this->withAuth($director['token'])
            ->getJson("/api/v1/assessment-results/{$resultId}/remediation-actions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $actionId);

        $this->withAuth($director['token'])
            ->patchJson("/api/v1/assessment-remediation-actions/{$actionId}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', AssessmentRemediationAction::STATUS_IN_PROGRESS);

        $this->withAuth($director['token'])
            ->patchJson("/api/v1/assessment-remediation-actions/{$actionId}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', AssessmentRemediationAction::STATUS_COMPLETED);

        $this->withAuth($director['token'])
            ->patchJson("/api/v1/assessment-remediation-actions/{$actionId}", ['status' => 'open'])
            ->assertStatus(409);

        $this->assertDatabaseHas('assessment_audit_logs', [
            'assessment_result_id' => $resultId,
            'action' => 'remediation_created',
        ]);
        $this->assertSame(1, AssessmentRemediationAction::query()->count());
        $this->assertDatabaseCount('LearningRecord', 0);

        $this->withAuth($director['token'])
            ->getJson('/api/v1/assessment-reports/summary?campus_id=' . $campus)
            ->assertOk()
            ->assertJsonPath('data.remediation_open_count', 0)
            ->assertJsonPath('data.remediation_completed_count', 1);
    }

    private function makeCampus(): int
    {
        return (int) CampusFactory::new()->create([
            'name' => '檢測分校 ' . Str::random(5),
        ])->id;
    }

    /** @return array{token:string,user:User} */
    private function makeStaff(string $type, string $login, int $campus): array
    {
        $user = User::create([
            'LoginName' => $login,
            'Name' => $type === 'A' ? '檢測主任' : '檢測老師',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user' => $user];
    }

    private function makeClass(int $studentId, int $teacherId, int $campus): StudentClass
    {
        $class = new StudentClass();
        $class->StudentID = $studentId;
        $class->TeacherID = $teacherId;
        $class->GradeID = 1;
        $class->SubjectID = 1;
        $class->by1 = $campus;
        $class->StartDate = '2026-08-01';
        $class->TotalHours = 20;
        $class->Charge = 1000;
        $class->Pay = 0;
        $class->Paid = 0;
        $class->Rate = 1000;
        $class->Stop = 0;
        $class->SessionCount = 10;
        $class->RemainingSessions = 10;
        $class->UsedSessions = 0;
        $class->SessionDuration = 60;
        $class->ClassType = 'one_on_one';
        $class->save();
        return $class->fresh();
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }
}
