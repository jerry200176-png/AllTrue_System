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
        $campus = (int) CampusFactory::new()->create(['name' => '段考分校 ' . Str::random(5)])->id;
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
            'campus_id' => $campus, 'title' => '113-1 第一次段考', 'entry_deadline' => now()->addWeek()->toDateString(),
            'grade_ids' => [3], 'subjects' => [['name' => '國文'], ['name' => '英文']],
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

    /** @return array{campus:int,director:array,teacher:array,student:Student,round:int,subjects:array} */
    private function seedRound(bool $publish = true): array
    {
        $campus = (int) CampusFactory::new()->create(['name' => '段考分校 ' . Str::random(5)])->id;
        $director = $this->makeStaff('A', Str::random(8) . '@example.com', $campus);
        $teacher = $this->makeStaff('T', Str::random(8) . '@example.com', $campus);
        $student = Student::create(['name' => '段考學生', 'CampusID' => $campus, 'ClassID' => 3, 'enable' => 1, 'MDT' => now()]);
        $this->makeClass($student->id, $teacher['user']->id, $campus);
        $created = $this->withAuth($director['token'])->postJson('/api/v1/exam-rounds', [
            'campus_id' => $campus, 'title' => '段考', 'grade_ids' => [3], 'subjects' => [['name' => '國文'], ['name' => '英文']],
        ])->assertCreated();
        $round = (int) $created->json('data.id');
        if ($publish) {
            $this->withAuth($director['token'])->postJson("/api/v1/exam-rounds/{$round}/publish")->assertOk();
        }
        return compact('campus', 'director', 'teacher', 'student', 'round') + ['subjects' => $created->json('data.subjects')];
    }

    private function putScore(array $c, string $who, int $subjectIdx, float $score)
    {
        return $this->withAuth($c[$who]['token'])->postJson("/api/v1/exam-rounds/{$c['round']}/scores", [
            'scores' => [['student_id' => $c['student']->id, 'exam_round_subject_id' => $c['subjects'][$subjectIdx]['id'], 'score' => $score]],
        ]);
    }

    public function test_delete_without_scores_hard_deletes_round_and_subjects(): void
    {
        $c = $this->seedRound();
        $this->withAuth($c['teacher']['token'])->deleteJson("/api/v1/exam-rounds/{$c['round']}")->assertForbidden();
        $this->withAuth($c['director']['token'])->deleteJson("/api/v1/exam-rounds/{$c['round']}")
            ->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('exam_rounds', ['id' => $c['round']]);
        $this->assertDatabaseCount('exam_round_subjects', 0);
        $this->assertDatabaseHas('exam_score_audit_logs', ['exam_round_id' => $c['round'], 'action' => 'round_deleted']);
    }

    public function test_delete_with_scores_voids_with_reason_and_restore_returns_previous_status(): void
    {
        $c = $this->seedRound();
        $this->putScore($c, 'teacher', 0, 80)->assertOk();
        $this->withAuth($c['director']['token'])->postJson("/api/v1/exam-rounds/{$c['round']}/close")->assertOk();
        $d = $this->withAuth($c['director']['token']);
        $d->deleteJson("/api/v1/exam-rounds/{$c['round']}")->assertStatus(422);
        $this->assertDatabaseHas('exam_rounds', ['id' => $c['round'], 'status' => 'closed']);
        $this->withAuth($c['director']['token'])->deleteJson("/api/v1/exam-rounds/{$c['round']}", ['reason' => '開錯場次'])
            ->assertOk()->assertJsonPath('deleted', false)->assertJsonPath('data.status', 'void');
        $this->assertDatabaseHas('exam_score_audit_logs', ['exam_round_id' => $c['round'], 'action' => 'round_voided', 'reason' => '開錯場次']);

        // hidden everywhere by default
        $ids = fn ($q = '') => collect($this->withAuth($c['director']['token'])
            ->getJson("/api/v1/exam-rounds?campus_id={$c['campus']}{$q}")->json('data'))->pluck('id')->all();
        $this->assertNotContains($c['round'], $ids());
        $this->assertContains($c['round'], $ids('&include_void=1'));
        $this->withAuth($c['teacher']['token'])->getJson("/api/v1/exam-rounds/{$c['round']}/students")->assertNotFound();
        $this->withAuth($c['teacher']['token'])->getJson('/api/v1/me/exam-pending-summary')->assertJsonPath('data.count', 0);
        $this->withAuth($c['director']['token'])->postJson("/api/v1/exam-rounds/{$c['round']}/publish")->assertStatus(409);
        $this->putScore($c, 'director', 0, 90)->assertNotFound();

        $this->withAuth($c['teacher']['token'])->postJson("/api/v1/exam-rounds/{$c['round']}/restore")->assertForbidden();
        $this->withAuth($c['director']['token'])->postJson("/api/v1/exam-rounds/{$c['round']}/restore")
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->withAuth($c['director']['token'])->postJson("/api/v1/exam-rounds/{$c['round']}/restore")->assertStatus(409);
    }

    public function test_other_campus_director_cannot_edit_delete_or_restore(): void
    {
        $c = $this->seedRound();
        $otherCampus = (int) CampusFactory::new()->create(['name' => '他校 ' . Str::random(5)])->id;
        $out = $this->makeStaff('A', Str::random(8) . '@example.com', $otherCampus);
        $this->withAuth($out['token'])->patchJson("/api/v1/exam-rounds/{$c['round']}", ['title' => 'x'])->assertForbidden();
        $this->withAuth($out['token'])->deleteJson("/api/v1/exam-rounds/{$c['round']}")->assertForbidden();
        $this->withAuth($out['token'])->postJson("/api/v1/exam-rounds/{$c['round']}/restore")->assertForbidden();
        $this->assertDatabaseHas('exam_rounds', ['id' => $c['round'], 'title' => '段考']);
    }

    public function test_patch_edits_fields_and_subjects_but_cannot_drop_scored_subject(): void
    {
        $c = $this->seedRound();
        $this->putScore($c, 'teacher', 0, 80)->assertOk();
        $s = $c['subjects'];
        $this->withAuth($c['teacher']['token'])->patchJson("/api/v1/exam-rounds/{$c['round']}", ['title' => 'x'])->assertForbidden();

        $this->withAuth($c['director']['token'])->patchJson("/api/v1/exam-rounds/{$c['round']}", [
            'title' => '改名段考', 'exam_date' => '2026-11-20', 'entry_deadline' => '2026-11-25', 'grade_ids' => [3, 4],
            'subjects' => [['id' => $s[0]['id'], 'name' => '國語'], ['id' => $s[1]['id'], 'name' => '英文'], ['name' => '理化']],
        ])->assertOk()->assertJsonPath('data.title', '改名段考')->assertJsonPath('data.grade_ids', [3, 4])
            ->assertJsonPath('data.subjects.0.name', '國語')->assertJsonPath('data.subjects.2.name', '理化');
        $this->assertDatabaseHas('exam_score_audit_logs', ['exam_round_id' => $c['round'], 'action' => 'round_updated']);

        // dropping the unscored 英文 is fine; dropping scored 國語 is 422 in Chinese
        $this->withAuth($c['director']['token'])->patchJson("/api/v1/exam-rounds/{$c['round']}", [
            'subjects' => [['id' => $s[0]['id'], 'name' => '國語']],
        ])->assertOk()->assertJsonCount(1, 'data.subjects');
        $this->withAuth($c['director']['token'])->patchJson("/api/v1/exam-rounds/{$c['round']}", [
            'subjects' => [['name' => '數學']],
        ])->assertStatus(422)->assertJsonPath('errors.subjects.0', '「國語」已有成績，不能移除');
        $this->assertDatabaseHas('exam_scores', ['exam_round_id' => $c['round'], 'exam_round_subject_id' => $s[0]['id']]);
    }

    /** @return array{token:string,user:User} */
    private function makeStaff(string $type, string $login, int $campus): array
    {
        $user = User::create([
            'LoginName' => $login, 'Name' => $type === 'A' ? '段考主任' : '段考老師',
            'PSW' => 'secret', 'type' => $type, 'phone' => (string) random_int(900000000, 999999999),
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
        foreach ([
            'StudentID' => $studentId, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => $campus, 'StartDate' => '2026-08-01', 'TotalHours' => 20, 'Charge' => 1000,
            'Pay' => 0, 'Paid' => 0, 'Rate' => 1000, 'Stop' => 0, 'SessionCount' => 10,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ] as $k => $v) {
            $class->{$k} = $v;
        }
        $class->save();
        return $class->fresh();
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
