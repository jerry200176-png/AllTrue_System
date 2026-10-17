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

/**
 * P0 school midterm exam rounds (段考) — director window + teacher score entry + pending summary.
 * Does not touch LearningRecord / Assessment / billing.
 */
class ExamRoundApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_publishes_round_teacher_enters_scores_and_pending_clears(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'exam-director@example.com', $campus);
        $teacher = $this->makeStaff('T', 'exam-teacher@example.com', $campus);
        $student = Student::create([
            'name' => '段考學生', 'CampusID' => $campus, 'ClassID' => 3, 'enable' => 1,
            'MDT' => now(),
        ]);
        $this->makeClass($student->id, $teacher['user']->id, $campus);

        $created = $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus,
            'title' => '113-1 第一次段考',
            'exam_date' => '2026-10-15',
            'entry_deadline' => '2026-10-31',
            'grade_ids' => [3],
            'subjects' => [
                ['name' => '國文', 'sort_order' => 0],
                ['name' => '英文', 'sort_order' => 1],
            ],
        ])->assertCreated();

        $roundId = (int) $created->json('data.id');
        $this->assertSame('draft', $created->json('data.status'));
        $this->assertCount(2, $created->json('data.subjects'));
        $subjectIds = collect($created->json('data.subjects'))->pluck('id')->all();

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/exam-rounds?campus_id=' . $campus)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withAuth($director['token'])
            ->postJson("/api/v1/exam-rounds/{$roundId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $pending = $this->withAuth($teacher['token'])
            ->getJson('/api/v1/me/exam-pending-summary')
            ->assertOk();
        $this->assertSame(1, (int) $pending->json('data.count'));
        $this->assertSame($roundId, (int) $pending->json('data.rounds.0.exam_round_id'));

        $students = $this->withAuth($teacher['token'])
            ->getJson("/api/v1/exam-rounds/{$roundId}/students")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $students);
        $this->assertFalse($students[0]['is_complete']);

        $this->withAuth($teacher['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectIds[0], 'score' => 88],
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectIds[1], 'score' => 92],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('LearningRecord', 0);
        $this->assertDatabaseHas('exam_score_audit_logs', [
            'exam_round_id' => $roundId,
            'action' => 'score_created',
        ]);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/me/exam-pending-summary')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->withAuth($director['token'])
            ->getJson("/api/v1/exam-rounds/{$roundId}/progress")
            ->assertOk()
            ->assertJsonPath('data.expected_students', 1)
            ->assertJsonPath('data.complete_students', 1)
            ->assertJsonPath('data.completion_percent', 100);
    }

    public function test_teacher_cannot_create_round_or_score_other_teachers_student(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'exam-director-scope@example.com', $campus);
        $teacherA = $this->makeStaff('T', 'exam-teacher-a@example.com', $campus);
        $teacherB = $this->makeStaff('T', 'exam-teacher-b@example.com', $campus);
        $student = Student::create([
            'name' => '他班學生', 'CampusID' => $campus, 'ClassID' => 2, 'enable' => 1,
            'MDT' => now(),
        ]);
        $this->makeClass($student->id, $teacherA['user']->id, $campus);

        $this->withAuth($teacherA['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus,
            'title' => '不應建立',
            'grade_ids' => [2],
        ])->assertForbidden();

        $round = $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus,
            'title' => '範圍測試段考',
            'grade_ids' => [2],
            'subjects' => [['name' => '數學']],
        ])->assertCreated()->json('data');

        $this->withAuth($director['token'])
            ->postJson('/api/v1/exam-rounds/' . $round['id'] . '/publish')
            ->assertOk();

        $subjectId = (int) $round['subjects'][0]['id'];
        $this->withAuth($teacherB['token'])->postJson('/api/v1/exam-rounds/' . $round['id'] . '/scores', [
            'scores' => [
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectId, 'score' => 70],
            ],
        ])->assertForbidden();

        $campusB = $this->makeCampus();
        $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campusB,
            'title' => '跨校',
            'grade_ids' => [1],
        ])->assertForbidden();
    }

    public function test_teacher_cannot_write_after_close_director_can_correct(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'exam-director-close@example.com', $campus);
        $teacher = $this->makeStaff('T', 'exam-teacher-close@example.com', $campus);
        $student = Student::create([
            'name' => '關閉後學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(),
        ]);
        $this->makeClass($student->id, $teacher['user']->id, $campus);

        $round = $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus,
            'title' => '關閉測試',
            'grade_ids' => [1],
            'subjects' => [['name' => '國文']],
        ])->assertCreated()->json('data');
        $roundId = (int) $round['id'];
        $subjectId = (int) $round['subjects'][0]['id'];

        $this->withAuth($director['token'])->postJson("/api/v1/exam-rounds/{$roundId}/publish")->assertOk();
        $this->withAuth($teacher['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectId, 'score' => 60],
            ],
        ])->assertOk();

        $this->withAuth($director['token'])->postJson("/api/v1/exam-rounds/{$roundId}/close")->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->withAuth($teacher['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectId, 'score' => 99],
            ],
        ])->assertStatus(409);

        $this->withAuth($director['token'])->postJson("/api/v1/exam-rounds/{$roundId}/scores", [
            'scores' => [
                ['student_id' => $student->id, 'exam_round_subject_id' => $subjectId, 'score' => 75],
            ],
            'reason' => '成績更正',
        ])->assertOk();

        $this->assertDatabaseHas('exam_score_audit_logs', [
            'exam_round_id' => $roundId,
            'action' => 'score_corrected',
        ]);
        $this->assertDatabaseHas('exam_scores', [
            'exam_round_id' => $roundId,
            'student_id' => $student->id,
            'score' => 75,
        ]);
    }

    public function test_score_out_of_range_is_rejected(): void
    {
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'exam-director-bound@example.com', $campus);
        $teacher = $this->makeStaff('T', 'exam-teacher-bound@example.com', $campus);
        $student = Student::create([
            'name' => '分數邊界', 'CampusID' => $campus, 'ClassID' => 4, 'enable' => 1,
            'MDT' => now(),
        ]);
        $this->makeClass($student->id, $teacher['user']->id, $campus);

        $round = $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus,
            'title' => '分數邊界',
            'grade_ids' => [4],
            'subjects' => [['name' => '自然', 'max_score' => 100]],
        ])->assertCreated()->json('data');
        $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds/' . $round['id'] . '/publish')->assertOk();

        $this->withAuth($teacher['token'])->postJson('/api/v1/exam-rounds/' . $round['id'] . '/scores', [
            'scores' => [
                [
                    'student_id' => $student->id,
                    'exam_round_subject_id' => $round['subjects'][0]['id'],
                    'score' => 101,
                ],
            ],
        ])->assertStatus(422);
    }

    private function makeCampus(): int
    {
        return (int) CampusFactory::new()->create([
            'name' => '段考分校 ' . Str::random(5),
        ])->id;
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
            'CampusID' => $campus,
            'UserID' => $user->id,
            'Admin' => $type === 'A' ? 1 : 0,
            'Approved' => 1,
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
