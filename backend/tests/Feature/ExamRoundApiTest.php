<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** School midterm exam rounds (段考) API. */
class ExamRoundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_score_pending_and_scope_guards(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'exam-director@example.com', $campus);
        $teacher = $this->makeStaff('T', 'exam-teacher@example.com', $campus);
        $other = $this->makeStaff('T', 'exam-other@example.com', $campus);
        $student = Student::create([
            'name' => '段考學生', 'CampusID' => $campus, 'ClassID' => 3, 'enable' => 1, 'MDT' => now(),
        ]);
        $this->makeClass($student->id, $teacher['user']->id, $campus);

        $this->withAuth($teacher['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus, 'title' => '不可', 'grade_ids' => [3],
        ])->assertForbidden();

        $created = $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus,
            'title' => '113-1 第一次段考',
            'entry_deadline' => '2026-10-31',
            'grade_ids' => [3],
            'subjects' => [['name' => '國文'], ['name' => '英文']],
        ])->assertCreated();
        $roundId = (int) $created->json('data.id');
        $subjectIds = collect($created->json('data.subjects'))->pluck('id')->all();

        $this->withAuth($director['token'])->postJson("/api/v1/exam-rounds/{$roundId}/publish")
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->withAuth($teacher['token'])->getJson('/api/v1/me/exam-pending-summary')
            ->assertOk()->assertJsonPath('data.count', 1);

        $this->withAuth($other['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [['student_id' => $student->id, 'exam_round_subject_id' => $subjectIds[0], 'score' => 70]],
        ])->assertForbidden();

        $this->withAuth($teacher['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectIds[0], 'score' => 88],
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectIds[1], 'score' => 92],
            ],
        ])->assertOk();
        $this->assertDatabaseCount('LearningRecord', 0);
        $this->assertDatabaseHas('exam_score_audit_logs', ['exam_round_id' => $roundId, 'action' => 'score_created']);
        $this->withAuth($teacher['token'])->getJson('/api/v1/me/exam-pending-summary')
            ->assertOk()->assertJsonPath('data.count', 0);
        $this->withAuth($director['token'])->getJson("/api/v1/exam-rounds/{$roundId}/progress")
            ->assertOk()->assertJsonPath('data.complete_students', 1);

        $this->withAuth($director['token'])->postJson("/api/v1/exam-rounds/{$roundId}/close")->assertOk();
        $this->withAuth($teacher['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [['student_id' => $student->id, 'exam_round_subject_id' => $subjectIds[0], 'score' => 99]],
        ])->assertStatus(409);
        $this->withAuth($director['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [['student_id' => $student->id, 'exam_round_subject_id' => $subjectIds[0], 'score' => 75]],
            'reason' => '成績更正',
        ])->assertOk();
        $this->assertDatabaseHas('exam_score_audit_logs', ['exam_round_id' => $roundId, 'action' => 'score_corrected']);
    }

    private function makeCampus(): int
    {
        return (int) CampusFactory::new()->create(['name' => '段考分校 ' . Str::random(5)])->id;
    }

    /** @return array{token:string,user:User} */
    private function makeStaff(string $type, string $login, int $campus): array
    {
        $user = User::create([
            'LoginName' => $login,
            'Name' => $type === 'A' ? '段考主任' : '段考老師',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campus, 'UserID' => $user->id,
            'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1,
        ]);
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
