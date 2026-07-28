<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\LearningRecordFeedback;
use App\Models\LearningRecordFeedbackReply;
use App\Models\ParentSession;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ParentFeedbackAwaitingService;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRD parent_feedback_awaiting_reply_inbox — E1–E7, idempotency, scope, count=list.
 * Must NOT treat analytics.unreplied_records as awaiting.
 */
class ParentFeedbackAwaitingReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_e1_initial_parent_message_is_awaiting(): void
    {
        $s = $this->seedRecord();
        $this->putJson($this->parentUrl($s), ['content' => '請加強'], $this->bearer($this->parentToken($s['student_id'])))
            ->assertOk()
            ->assertJsonPath('feedback.awaiting_staff_reply', true)
            ->assertJsonPath('idempotent', false);

        $teacherToken = $this->staffToken($s['teacher'], [$s['campus_id']]);
        $this->getJson('/api/v1/me/awaiting-reply-count', $this->bearer($teacherToken))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_count', 1);
    }

    public function test_e4_identical_upsert_is_idempotent_noop(): void
    {
        $s = $this->seedRecord();
        $token = $this->parentToken($s['student_id']);
        $this->putJson($this->parentUrl($s), ['content' => '  相同內容  '], $this->bearer($token))->assertOk();

        $fb = LearningRecordFeedback::where('learning_record_id', $s['record_id'])->first();
        $updatedAt = (string) $fb->updated_at;
        $readTeacher = $fb->last_read_by_teacher_at;
        DB::table('learning_record_feedbacks')->where('id', $fb->id)->update([
            'last_read_by_teacher_at' => now(),
        ]);
        $fb->refresh();
        $readAfterMark = (string) $fb->last_read_by_teacher_at;

        $res = $this->putJson($this->parentUrl($s), ['content' => '相同內容'], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $fb->refresh();
        $this->assertSame($updatedAt, (string) $fb->updated_at, 'E4 must not bump updated_at');
        $this->assertSame($readAfterMark, (string) $fb->last_read_by_teacher_at, 'E4 must not reset unread');
        $this->assertSame(0, LearningRecordFeedbackReply::where('feedback_id', $fb->id)->count());
        $this->assertTrue($res->json('idempotent'));
    }

    public function test_e2_content_change_reopens_awaiting_and_appends_parent_reply(): void
    {
        $s = $this->seedRecord();
        $parentToken = $this->parentToken($s['student_id']);
        $this->putJson($this->parentUrl($s), ['content' => '初版'], $this->bearer($parentToken))->assertOk();

        $fb = LearningRecordFeedback::where('learning_record_id', $s['record_id'])->first();
        $teacherToken = $this->staffToken($s['teacher'], [$s['campus_id']]);
        $this->postJson("/api/v1/learning-record-feedbacks/{$fb->id}/reply", ['content' => '老師已回'], $this->bearer($teacherToken))
            ->assertOk();

        $this->getJson('/api/v1/me/awaiting-reply-count', $this->bearer($teacherToken))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_count', 0);

        $this->putJson($this->parentUrl($s), ['content' => '修改後內容'], $this->bearer($parentToken))
            ->assertOk()
            ->assertJsonPath('idempotent', false)
            ->assertJsonPath('feedback.awaiting_staff_reply', true);

        $this->assertSame(1, LearningRecordFeedbackReply::where('feedback_id', $fb->id)->where('author_role', 'parent')->count());
        $this->getJson('/api/v1/me/awaiting-reply-count', $this->bearer($teacherToken))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_count', 1);
    }

    public function test_e3_parent_followup_reopens_awaiting(): void
    {
        $s = $this->seedRecord();
        $parentToken = $this->parentToken($s['student_id']);
        $this->putJson($this->parentUrl($s), ['content' => '初版'], $this->bearer($parentToken))->assertOk();
        $fb = LearningRecordFeedback::where('learning_record_id', $s['record_id'])->first();
        $teacherToken = $this->staffToken($s['teacher'], [$s['campus_id']]);
        $this->postJson("/api/v1/learning-record-feedbacks/{$fb->id}/reply", ['content' => '老師回'], $this->bearer($teacherToken))->assertOk();

        $this->postJson("/api/v1/parent/learning-records/{$s['record_id']}/feedback/reply", ['content' => '追問'], $this->bearer($parentToken))
            ->assertOk();

        $this->getJson('/api/v1/me/awaiting-reply-count', $this->bearer($teacherToken))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_count', 1);
    }

    public function test_e5_director_public_reply_clears_awaiting(): void
    {
        $s = $this->seedRecord();
        $this->putJson($this->parentUrl($s), ['content' => '請看'], $this->bearer($this->parentToken($s['student_id'])))->assertOk();
        $fb = LearningRecordFeedback::where('learning_record_id', $s['record_id'])->first();

        $director = $this->user('A');
        $directorToken = $this->staffToken($director, [$s['campus_id']]);
        $this->postJson("/api/v1/learning-record-feedbacks/{$fb->id}/reply", ['content' => '主任代回'], $this->bearer($directorToken))
            ->assertOk();

        $this->getJson('/api/v1/me/awaiting-reply-count?branch_id=' . $s['campus_id'], $this->bearer($directorToken))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_count', 0);
    }

    public function test_e6_mark_read_does_not_clear_awaiting(): void
    {
        $s = $this->seedRecord();
        $this->putJson($this->parentUrl($s), ['content' => '未回'], $this->bearer($this->parentToken($s['student_id'])))->assertOk();
        $fb = LearningRecordFeedback::where('learning_record_id', $s['record_id'])->first();
        $teacherToken = $this->staffToken($s['teacher'], [$s['campus_id']]);

        $this->postJson("/api/v1/learning-record-feedbacks/{$fb->id}/read", [], $this->bearer($teacherToken))->assertOk();
        $this->getJson('/api/v1/me/unread-feedback-count', $this->bearer($teacherToken))
            ->assertOk()
            ->assertJsonPath('count', 0);
        $this->getJson('/api/v1/me/awaiting-reply-count', $this->bearer($teacherToken))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_count', 1);
    }

    public function test_e7_internal_teacher_comment_does_not_clear_awaiting(): void
    {
        $s = $this->seedRecord();
        $this->putJson($this->parentUrl($s), ['content' => '對外'], $this->bearer($this->parentToken($s['student_id'])))->assertOk();

        $director = $this->user('A');
        $directorToken = $this->staffToken($director, [$s['campus_id']]);
        $this->putJson("/api/v1/learning-records/{$s['record_id']}/teacher-comment", [
            'content' => '僅內部',
        ], $this->bearer($directorToken))->assertOk();

        $teacherToken = $this->staffToken($s['teacher'], [$s['campus_id']]);
        $this->getJson('/api/v1/me/awaiting-reply-count', $this->bearer($teacherToken))
            ->assertOk()
            ->assertJsonPath('awaiting_reply_count', 1);
    }

    public function test_same_second_parent_after_staff_uses_id_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 10:00:00'));
        try {
            $s = $this->seedRecord();
            $this->putJson($this->parentUrl($s), ['content' => '初'], $this->bearer($this->parentToken($s['student_id'])))->assertOk();
            $fb = LearningRecordFeedback::where('learning_record_id', $s['record_id'])->first();
            $teacherToken = $this->staffToken($s['teacher'], [$s['campus_id']]);

            $staff = LearningRecordFeedbackReply::create([
                'feedback_id' => $fb->id,
                'author_user_id' => $s['teacher_id'],
                'author_role' => 'teacher',
                'content' => '同秒員工',
            ]);
            DB::table('learning_record_feedback_replies')->where('id', $staff->id)->update([
                'created_at' => '2026-07-28 10:00:00',
                'updated_at' => '2026-07-28 10:00:00',
            ]);

            $parent = LearningRecordFeedbackReply::create([
                'feedback_id' => $fb->id,
                'author_user_id' => null,
                'author_role' => 'parent',
                'content' => '同秒家長後到',
            ]);
            DB::table('learning_record_feedback_replies')->where('id', $parent->id)->update([
                'created_at' => '2026-07-28 10:00:00',
                'updated_at' => '2026-07-28 10:00:00',
            ]);

            $service = app(ParentFeedbackAwaitingService::class);
            $this->assertTrue($service->isAwaitingStaffReply($fb->fresh()), 'higher parent reply id at same second must win');
            $this->assertGreaterThan($staff->id, $parent->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_count_equals_list_total_for_teacher_and_director(): void
    {
        $campusA = CampusFactory::new()->create();
        $campusB = CampusFactory::new()->create();
        $teacher = $this->user('T');
        $a = $this->seedRecord(['campus' => $campusA, 'teacher' => $teacher]);
        $b = $this->seedRecord(['campus' => $campusB, 'teacher' => $teacher]);
        $this->putJson($this->parentUrl($a), ['content' => 'A校'], $this->bearer($this->parentToken($a['student_id'])))->assertOk();
        $this->putJson($this->parentUrl($b), ['content' => 'B校'], $this->bearer($this->parentToken($b['student_id'])))->assertOk();

        $teacherToken = $this->staffToken($teacher, [$campusA->id, $campusB->id]);
        $count = $this->getJson('/api/v1/me/awaiting-reply-count', $this->bearer($teacherToken))
            ->assertOk()
            ->json('awaiting_reply_count');
        $this->assertSame(2, (int) $count);

        $list = $this->getJson('/api/v1/learning-records?feedback=awaiting_reply&per_page=50', $this->bearer($teacherToken))
            ->assertOk()
            ->json();
        $this->assertSame(2, (int) ($list['total'] ?? count($list['data'] ?? [])));

        $director = $this->user('A');
        $directorToken = $this->staffToken($director, [$campusA->id, $campusB->id]);
        $dirCount = $this->getJson('/api/v1/me/awaiting-reply-count?branch_id=' . $campusA->id, $this->bearer($directorToken))
            ->assertOk()
            ->json('awaiting_reply_count');
        $this->assertSame(1, (int) $dirCount);

        $dirList = $this->getJson(
            '/api/v1/learning-records?branch_id=' . $campusA->id . '&feedback=awaiting_reply&per_page=50',
            $this->bearer($directorToken)
        )->assertOk()->json();
        $this->assertSame(1, (int) ($dirList['total'] ?? count($dirList['data'] ?? [])));
    }

    public function test_awaiting_filter_returns_old_dated_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 12:00:00'));
        try {
            $campus = CampusFactory::new()->create();
            $s = $this->seedRecord(['campus' => $campus, 'session_date' => '2026-01-05']);
            $this->putJson($this->parentUrl($s), ['content' => '很舊'], $this->bearer($this->parentToken($s['student_id'])))->assertOk();
            $director = $this->user('A');
            $directorToken = $this->staffToken($director, [$campus->id]);

            $data = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&feedback=awaiting_reply&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json('data');
            $this->assertContains((int) $s['record_id'], collect($data)->pluck('id')->map(fn ($i) => (int) $i)->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_super_admin_awaiting_count_forbidden_on_teacher_director_route(): void
    {
        // Route group is role:director,teacher — super_admin must not gain access via this endpoint.
        $campus = CampusFactory::new()->create();
        $admin = $this->user('S');
        $token = $this->staffToken($admin, [$campus->id]);
        $this->getJson('/api/v1/me/awaiting-reply-count?branch_id=' . $campus->id, $this->bearer($token))
            ->assertStatus(403);
    }

    private function seedRecord(array $o = []): array
    {
        $campus = $o['campus'] ?? CampusFactory::new()->create();
        $teacher = $o['teacher'] ?? $this->user('T');
        UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $sc = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id, 'TeacherID' => $teacher->id, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(30)->toDateString(),
            'TotalHours' => 20, 'Charge' => 1000, 'Pay' => 500, 'Paid' => 0, 'Rate' => 1000,
            'Stop' => 0, 'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionCount' => 10,
            'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ]);
        $date = $o['session_date'] ?? now()->subDay()->toDateString();
        $cs = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'attended',
        ]);
        $lr = DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id, 'StudentClassID' => $sc, 'ClassSessionID' => $cs, 'TeacherID' => $teacher->id,
            'Subject' => 'English', 'SessionDate' => $date, 'StartTime' => '23:00', 'EndTime' => '23:30',
            'Content' => '課堂內容', 'Status' => 'approved', 'ApprovedBy' => 1,
            'ApprovedAt' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'record_id' => $lr,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'teacher' => $teacher,
            'campus_id' => $campus->id,
        ];
    }

    private function user(string $type): User
    {
        return User::create([
            'LoginName' => Str::random(8) . '@test.com',
            'Name' => 'Awaiting User',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
    }

    private function parentToken(int $studentId): string
    {
        $raw = Str::random(32);
        ParentSession::create([
            'StudentID' => $studentId,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addHours(2),
        ]);

        return $raw;
    }

    private function staffToken(User $user, array $campusIds): string
    {
        foreach ($campusIds as $id) {
            UserCampus::firstOrCreate(
                ['CampusID' => $id, 'UserID' => $user->id],
                ['Admin' => $user->type !== 'T', 'Approved' => 1]
            );
        }
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
