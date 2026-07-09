<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningRecordTeacherCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_upserts_teacher_comment_and_teacher_reads_it(): void
    {
        $campus = CampusFactory::new()->create();
        $ctx = $this->record(['campus' => $campus]);
        $director = $this->user('A', '主任');
        $directorToken = $this->staffToken($director, [$campus->id]);

        $this->putJson(
            "/api/v1/learning-records/{$ctx['record_id']}/teacher-comment",
            ['content' => '請下次補充學生卡住的單元與追蹤方式'],
            $this->bearer($directorToken)
        )
            ->assertOk()
            ->assertJsonPath('comment.learning_record_id', $ctx['record_id'])
            ->assertJsonPath('comment.teacher_id', $ctx['teacher_id'])
            ->assertJsonPath('comment.author_user_id', $director->id)
            ->assertJsonPath('comment.unread_for_teacher', true);

        $this->putJson(
            "/api/v1/learning-records/{$ctx['record_id']}/teacher-comment",
            ['content' => '更新：請補下一週作業追蹤'],
            $this->bearer($directorToken)
        )->assertOk();

        $this->assertSame(1, DB::table('learning_record_teacher_comments')->where('learning_record_id', $ctx['record_id'])->count());
        $this->assertDatabaseHas('learning_record_teacher_comments', [
            'learning_record_id' => $ctx['record_id'],
            'student_id' => $ctx['student_id'],
            'student_class_id' => $ctx['student_class_id'],
            'teacher_id' => $ctx['teacher_id'],
            'campus_id' => $campus->id,
            'author_user_id' => $director->id,
            'content' => '更新：請補下一週作業追蹤',
        ]);

        $teacherToken = $this->staffToken($ctx['teacher'], [$campus->id]);
        $records = $this->getJson('/api/v1/learning-records?per_page=100', $this->bearer($teacherToken))
            ->assertOk()
            ->json('data');
        $record = collect($records)->firstWhere('id', $ctx['record_id']);
        $this->assertNotNull($record);
        $this->assertSame('更新：請補下一週作業追蹤', $record['teacher_comment']['content']);
        $this->assertTrue($record['teacher_comment']['unread_for_teacher']);
    }

    public function test_teacher_comment_access_guards_role_campus_and_content(): void
    {
        $campusA = CampusFactory::new()->create();
        $campusB = CampusFactory::new()->create();
        $a = $this->record(['campus' => $campusA]);
        $b = $this->record(['campus' => $campusB]);

        $directorA = $this->user('A', '主任A');
        $directorToken = $this->staffToken($directorA, [$campusA->id]);
        $teacherToken = $this->staffToken($a['teacher'], [$campusA->id]);

        $this->putJson(
            "/api/v1/learning-records/{$a['record_id']}/teacher-comment",
            ['content' => '   '],
            $this->bearer($directorToken)
        )->assertStatus(422);

        $this->putJson(
            "/api/v1/learning-records/{$a['record_id']}/teacher-comment",
            ['content' => Str::repeat('a', 501)],
            $this->bearer($directorToken)
        )->assertStatus(422);

        $this->putJson(
            "/api/v1/learning-records/{$a['record_id']}/teacher-comment",
            ['content' => '老師不可寫主任評語'],
            $this->bearer($teacherToken)
        )->assertStatus(403);

        $this->putJson(
            "/api/v1/learning-records/{$b['record_id']}/teacher-comment",
            ['content' => '跨分校不可寫'],
            $this->bearer($directorToken)
        )->assertStatus(403);
    }

    public function test_teacher_mark_read_does_not_touch_comment_updated_at(): void
    {
        $campus = CampusFactory::new()->create();
        $ctx = $this->record(['campus' => $campus]);
        $director = $this->user('A', '主任');
        $directorToken = $this->staffToken($director, [$campus->id]);

        $this->putJson(
            "/api/v1/learning-records/{$ctx['record_id']}/teacher-comment",
            ['content' => '請補充評量細節'],
            $this->bearer($directorToken)
        )->assertOk();

        $commentId = DB::table('learning_record_teacher_comments')
            ->where('learning_record_id', $ctx['record_id'])
            ->value('id');
        $originalUpdatedAt = Carbon::parse('2026-04-27 10:00:00');
        DB::table('learning_record_teacher_comments')
            ->where('id', $commentId)
            ->update(['updated_at' => $originalUpdatedAt]);

        Carbon::setTestNow(Carbon::parse('2026-04-27 10:05:00'));
        try {
            $teacherToken = $this->staffToken($ctx['teacher'], [$campus->id]);
            $this->postJson("/api/v1/learning-record-teacher-comments/{$commentId}/read", [], $this->bearer($teacherToken))
                ->assertOk();

            $row = DB::table('learning_record_teacher_comments')->where('id', $commentId)->first();
            $this->assertSame($originalUpdatedAt->format('Y-m-d H:i:s'), Carbon::parse($row->updated_at)->format('Y-m-d H:i:s'));
            $this->assertSame('2026-04-27 10:05:00', Carbon::parse($row->last_read_by_teacher_at)->format('Y-m-d H:i:s'));

            $records = $this->getJson('/api/v1/learning-records?per_page=100', $this->bearer($teacherToken))
                ->assertOk()
                ->json('data');
            $record = collect($records)->firstWhere('id', $ctx['record_id']);
            $this->assertFalse($record['teacher_comment']['unread_for_teacher']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function record(array $o = []): array
    {
        $campus = $o['campus'] ?? CampusFactory::new()->create();
        $teacher = $o['teacher'] ?? $this->user('T', '老師');
        UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $sc = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'TeacherID' => $teacher->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(30)->toDateString(),
            'TotalHours' => 20,
            'Charge' => 1000,
            'Pay' => 500,
            'Paid' => 0,
            'Rate' => 1000,
            'Stop' => 0,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'SessionCount' => 10,
            'SessionDuration' => 60,
            'ClassType' => 'one_on_one',
        ]);
        $date = now()->subDay()->toDateString();
        $cs = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc,
            'SessionDate' => $date,
            'StartTime' => '14:00',
            'EndTime' => '15:00',
            'Status' => 'attended',
        ]);
        $lr = DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id,
            'StudentClassID' => $sc,
            'ClassSessionID' => $cs,
            'TeacherID' => $teacher->id,
            'Subject' => 'English',
            'SessionDate' => $date,
            'StartTime' => '14:00',
            'EndTime' => '15:00',
            'Content' => '課堂內容',
            'Status' => 'approved',
            'ApprovedBy' => 1,
            'ApprovedAt' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'record_id' => $lr,
            'student_id' => $student->id,
            'student_class_id' => $sc,
            'teacher_id' => $teacher->id,
            'teacher' => $teacher,
            'campus_id' => $campus->id,
        ];
    }

    private function user(string $type, string $name): User
    {
        return User::create([
            'LoginName' => Str::random(8) . '@test.com',
            'Name' => $name,
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
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

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
