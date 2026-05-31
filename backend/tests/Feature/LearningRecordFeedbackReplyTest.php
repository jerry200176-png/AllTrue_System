<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ParentSession;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 家長回饋雙向回覆對話串（System A）。
 * 老師/主任可回覆家長、家長在入口看得到並可追問；分校隔離 + 未讀重新觸發。
 */
class LearningRecordFeedbackReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_reply_and_parent_sees_reply(): void
    {
        $campus = CampusFactory::new()->create();
        $s = $this->record(['campus' => $campus]);
        $this->submit($s, '請老師多協助孩子的英文');
        $feedbackId = $this->feedbackId($s);

        $teacherToken = $this->staffToken($s['teacher'], [$campus->id]);
        $this->postJson(
            "/api/v1/learning-record-feedbacks/{$feedbackId}/reply",
            ['content' => '好的，我們這週會加強單字默寫'],
            $this->bearer($teacherToken)
        )->assertOk();

        $this->assertDatabaseHas('learning_record_feedback_replies', [
            'feedback_id' => $feedbackId,
            'author_role' => 'teacher',
            'content' => '好的，我們這週會加強單字默寫',
        ]);

        // 家長端看得到老師回覆
        $parentToken = $this->parentToken($s['student_id']);
        $res = $this->getJson($this->parentUrl($s), $this->bearer($parentToken))->assertOk();
        $replies = $res->json('feedback.replies');
        $this->assertIsArray($replies);
        $this->assertCount(1, $replies);
        $this->assertSame('teacher', $replies[0]['author_role']);
        $this->assertSame('好的，我們這週會加強單字默寫', $replies[0]['content']);
    }

    public function test_reply_blocks_other_teacher_and_cross_campus_director(): void
    {
        $campusA = CampusFactory::new()->create();
        $campusB = CampusFactory::new()->create();
        $a = $this->record(['campus' => $campusA]);
        $this->submit($a, '家長回饋A');
        $feedbackId = $this->feedbackId($a);

        // 別的老師（非該堂老師）不可回覆
        $otherTeacher = $this->user('T');
        $this->postJson(
            "/api/v1/learning-record-feedbacks/{$feedbackId}/reply",
            ['content' => '越權回覆'],
            $this->bearer($this->staffToken($otherTeacher, [$campusA->id]))
        )->assertStatus(403);

        // 跨分校主任不可回覆
        $directorB = $this->user('A');
        $this->postJson(
            "/api/v1/learning-record-feedbacks/{$feedbackId}/reply",
            ['content' => '跨校越權'],
            $this->bearer($this->staffToken($directorB, [$campusB->id]))
        )->assertStatus(403);

        // 同分校主任可回覆
        $directorA = $this->user('A');
        $this->postJson(
            "/api/v1/learning-record-feedbacks/{$feedbackId}/reply",
            ['content' => '主任已協調老師'],
            $this->bearer($this->staffToken($directorA, [$campusA->id]))
        )->assertOk();

        $this->assertDatabaseHas('learning_record_feedback_replies', [
            'feedback_id' => $feedbackId,
            'author_role' => 'director',
            'content' => '主任已協調老師',
        ]);
    }

    public function test_parent_followup_reflags_staff_unread(): void
    {
        $campus = CampusFactory::new()->create();
        $s = $this->record(['campus' => $campus]);
        $this->submit($s, '家長初次回饋');
        $feedbackId = $this->feedbackId($s);
        $teacherToken = $this->staffToken($s['teacher'], [$campus->id]);

        // 老師先讀掉
        $this->postJson("/api/v1/learning-record-feedbacks/{$feedbackId}/read", [], $this->bearer($teacherToken))->assertOk();
        $this->getJson('/api/v1/me/unread-feedback-count', $this->bearer($teacherToken))
            ->assertOk()->assertJsonPath('count', 0);

        // 家長追問 → 老師端應重新未讀
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        try {
            $this->postJson(
                "/api/v1/parent/learning-records/{$s['record_id']}/feedback/reply",
                ['content' => '想再請問老師作業份量'],
                $this->bearer($this->parentToken($s['student_id']))
            )->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseHas('learning_record_feedback_replies', [
            'feedback_id' => $feedbackId,
            'author_role' => 'parent',
        ]);
        $this->getJson('/api/v1/me/unread-feedback-count', $this->bearer($teacherToken))
            ->assertOk()->assertJsonPath('count', 1);
    }

    public function test_parent_sees_unread_reply_dot_until_read(): void
    {
        $campus = CampusFactory::new()->create();
        $s = $this->record(['campus' => $campus]);
        $this->submit($s, '家長回饋');
        $feedbackId = $this->feedbackId($s);

        // 老師回覆
        $this->postJson(
            "/api/v1/learning-record-feedbacks/{$feedbackId}/reply",
            ['content' => '收到，謝謝家長'],
            $this->bearer($this->staffToken($s['teacher'], [$campus->id]))
        )->assertOk();

        // 家長第一次取得 → 有新回覆 = true，並標記已讀
        $parentToken = $this->parentToken($s['student_id']);
        $res1 = $this->getJson($this->parentUrl($s), $this->bearer($parentToken))->assertOk();
        $this->assertTrue($res1->json('feedback.has_unread_reply'));

        // 再次取得 → 已讀 = false
        $res2 = $this->getJson($this->parentUrl($s), $this->bearer($parentToken))->assertOk();
        $this->assertFalse($res2->json('feedback.has_unread_reply'));
    }

    public function test_reply_content_validation(): void
    {
        $campus = CampusFactory::new()->create();
        $s = $this->record(['campus' => $campus]);
        $this->submit($s, '家長回饋');
        $feedbackId = $this->feedbackId($s);
        $teacherToken = $this->staffToken($s['teacher'], [$campus->id]);

        $this->postJson("/api/v1/learning-record-feedbacks/{$feedbackId}/reply", ['content' => ' '], $this->bearer($teacherToken))
            ->assertStatus(422);
        $this->postJson("/api/v1/learning-record-feedbacks/{$feedbackId}/reply", ['content' => Str::repeat('a', 1001)], $this->bearer($teacherToken))
            ->assertStatus(422);
    }

    private function feedbackId(array $s): int
    {
        return (int) DB::table('learning_record_feedbacks')->where('learning_record_id', $s['record_id'])->value('id');
    }

    private function submit(array $s, string $content): void
    {
        $this->putJson($this->parentUrl($s), ['content' => $content], $this->bearer($this->parentToken($s['student_id'])))->assertOk();
    }

    private function record(array $o = []): array
    {
        $campus = $o['campus'] ?? CampusFactory::new()->create();
        $teacher = $o['teacher'] ?? $this->user('T');
        UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $sc = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id, 'TeacherID' => $teacher->id, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(30)->toDateString(), 'RoomID' => '',
            'TotalHours' => 20, 'Charge' => 1000, 'Pay' => 500, 'Paid' => 0, 'Rate' => 1000,
            'Stop' => 0, 'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionCount' => 10,
            'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ]);
        $date = now()->subDay()->toDateString();
        $cs = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => '14:00', 'EndTime' => '15:00', 'Status' => 'attended',
        ]);
        $lr = DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id, 'StudentClassID' => $sc, 'ClassSessionID' => $cs, 'TeacherID' => $teacher->id,
            'Subject' => 'English', 'SessionDate' => $date, 'StartTime' => '14:00', 'EndTime' => '15:00',
            'Content' => '課堂內容', 'Status' => $o['status'] ?? 'approved', 'ApprovedBy' => 1,
            'ApprovedAt' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['record_id' => $lr, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'teacher' => $teacher, 'campus_id' => $campus->id];
    }

    private function user(string $type): User
    {
        return User::create([
            'LoginName' => Str::random(8) . '@test.com', 'Name' => 'Feedback User', 'PSW' => 'secret',
            'type' => $type, 'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false,
        ]);
    }

    private function parentToken(int $studentId): string
    {
        $raw = Str::random(32);
        ParentSession::create(['StudentID' => $studentId, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);
        return $raw;
    }

    private function staffToken(User $user, array $campusIds): string
    {
        foreach ($campusIds as $id) UserCampus::firstOrCreate(['CampusID' => $id, 'UserID' => $user->id], ['Admin' => $user->type !== 'T', 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function parentUrl(array $s): string
    {
        return "/api/v1/parent/learning-records/{$s['record_id']}/feedback";
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
