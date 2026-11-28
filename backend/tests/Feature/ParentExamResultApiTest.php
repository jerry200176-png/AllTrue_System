<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ExamRound;
use App\Models\ExamRoundSubject;
use App\Models\ExamScore;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 家長端段考成績: closed rounds only, the session's child only, own campus only, fixed field set, latest 3. Fake data. */
class ParentExamResultApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/parent/exam-results';

    private function makeStudent(int $campus): Student
    {
        return Student::create(['name' => '虛構學生', 'CampusID' => $campus, 'ClassID' => 3, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @return array{0:ExamRound,1:array<int,ExamRoundSubject>} subjects in the given order, sort_order reversed to prove ordering */
    private function makeRound(int $campus, string $status, string $title, ?string $closedAt = '2026-11-01 10:00:00', array $names = ['國文']): array
    {
        $round = ExamRound::create(['campus_id' => $campus, 'title' => $title, 'status' => $status, 'grade_ids' => [3],
            'exam_date' => '2026-10-15', 'closed_at' => $status === 'closed' ? $closedAt : null]);
        $subjects = [];
        foreach ($names as $i => $name) {
            $subjects[] = ExamRoundSubject::create(['exam_round_id' => $round->id, 'name' => $name, 'sort_order' => $i, 'max_score' => 100]);
        }
        return [$round, $subjects];
    }

    private function score(ExamRoundSubject $subject, Student $student, array $o = []): ExamScore
    {
        return ExamScore::create($o + ['exam_round_id' => $subject->exam_round_id, 'exam_round_subject_id' => $subject->id,
            'student_id' => $student->id, 'score' => 80, 'is_absent' => false]);
    }

    private function asParentOf(Student $student)
    {
        $raw = Str::random(32);
        ParentSession::create(['StudentID' => $student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);
        return $this->withHeaders(['Authorization' => 'Bearer ' . $raw, 'Accept' => 'application/json']);
    }

    public function test_only_closed_rounds_show_with_exactly_the_decided_fields_and_never_notes(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        foreach (['draft', 'published', 'void'] as $status) {
            [, [$s]] = $this->makeRound($campus, $status, "{$status} 場");
            $this->score($s, $child);
        }
        [, [$chinese, $english]] = $this->makeRound($campus, 'closed', '第一次段考', '2026-11-01 10:00:00', ['國文', '英文']);
        $this->score($english, $child, ['score' => null, 'is_absent' => true, 'notes' => '內部：家長不要看']);
        $this->score($chinese, $child, ['score' => 85.5, 'notes' => '內部：家長不要看', 'parent_comment' => "閱讀進步。\n作文再加油。", 'recorded_by_user_id' => 77]);

        $data = $this->asParentOf($child)->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(['第一次段考'], array_column($data, 'title'));
        $this->assertSame(['exam_date', 'id', 'subjects', 'title'], collect(array_keys($data[0]))->sort()->values()->all());
        $this->assertSame('2026-10-15', $data[0]['exam_date']);
        $this->assertSame(['國文', '英文'], array_column($data[0]['subjects'], 'name'));
        foreach ($data[0]['subjects'] as $row) {
            $this->assertSame(['is_absent', 'max_score', 'name', 'parent_comment', 'score'], collect(array_keys($row))->sort()->values()->all());
        }
        $this->assertSame([85.5, false, "閱讀進步。\n作文再加油。"], [$data[0]['subjects'][0]['score'], $data[0]['subjects'][0]['is_absent'], $data[0]['subjects'][0]['parent_comment']]);
        $this->assertSame([null, true], [$data[0]['subjects'][1]['score'], $data[0]['subjects'][1]['is_absent']]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('內部', $json);
        $this->assertStringNotContainsString('notes', $json);
    }

    public function test_another_students_scores_never_show(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        $other = $this->makeStudent($campus);
        [, [$chinese, $english]] = $this->makeRound($campus, 'closed', '同一場', '2026-11-01 10:00:00', ['國文', '英文']);
        $this->score($chinese, $child, ['score' => 70]);
        $this->score($english, $other, ['score' => 99, 'parent_comment' => '別人的話']);
        [, [$onlyOther]] = $this->makeRound($campus, 'closed', '孩子沒考的場');
        $this->score($onlyOther, $other);

        $data = $this->asParentOf($child)->getJson(self::URL . "?student_id={$other->id}")->assertOk()->json('data');

        $this->assertSame(['同一場'], array_column($data, 'title'));
        $this->assertEquals([['name' => '國文', 'max_score' => 100, 'score' => 70, 'is_absent' => false, 'parent_comment' => null]], $data[0]['subjects']);
        $this->assertStringNotContainsString('別人的話', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_other_campus_rounds_are_not_shown_and_non_parent_tokens_are_refused(): void
    {
        $campusA = (int) CampusFactory::new()->create()->id;
        $campusB = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campusB);
        [, [$a]] = $this->makeRound($campusA, 'closed', 'A 校段考');
        $this->score($a, $child);
        [, [$b]] = $this->makeRound($campusB, 'closed', 'B 校段考');
        $this->score($b, $child);

        $this->assertSame(['B 校段考'], array_column($this->asParentOf($child)->getJson(self::URL)->assertOk()->json('data'), 'title'));

        $this->flushHeaders()->getJson(self::URL, ['Accept' => 'application/json'])->assertStatus(401);
        $director = User::create(['LoginName' => 'dr' . random_int(1, 99999) . '@example.com', 'Name' => 'Staff', 'PSW' => 'secret',
            'type' => 'A', 'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusA, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $staffToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $staffToken, 'expires_at' => now()->addDay()]);
        $this->withHeaders(['Authorization' => "Bearer {$staffToken}", 'Accept' => 'application/json'])->getJson(self::URL)->assertStatus(401);
    }

    public function test_only_the_latest_three_closed_rounds(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $child = $this->makeStudent($campus);
        foreach (['2026-03-01', '2026-06-01', '2026-10-01', '2026-11-20'] as $i => $day) {
            [, [$s]] = $this->makeRound($campus, 'closed', "第 {$i} 場", "{$day} 10:00:00");
            $this->score($s, $child);
        }

        $data = $this->asParentOf($child)->getJson(self::URL)->assertOk()->json('data');
        $this->assertSame(['第 3 場', '第 2 場', '第 1 場'], array_column($data, 'title'));
    }
}
