<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ParentSession;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentFeedbackTest extends TestCase
{
    use RefreshDatabase;

    // ─── 家長端：送出回饋 ────────────────────────────────────────────

    public function test_parent_can_submit_feedback(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $token = $this->parentToken($student->id);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'teaching',
            'content'  => '老師教得很好，孩子進步明顯！',
            'rating'   => 5,
        ], $this->bearer($token))
            ->assertCreated()
            ->assertJsonFragment(['message' => '感謝您的寶貴建議！全真團隊將認真參考。']);

        $this->assertDatabaseHas('parent_feedback', [
            'student_id' => $student->id,
            'campus_id'  => $campus->id,
            'category'   => 'teaching',
            'rating'     => 5,
            'content'    => '老師教得很好，孩子進步明顯！',
            'is_read'    => false,
        ]);
    }

    public function test_parent_feedback_requires_minimum_content(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $token = $this->parentToken($student->id);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'other',
            'content'  => '太短',
        ], $this->bearer($token))
            ->assertStatus(422);
    }

    public function test_parent_feedback_rejects_invalid_category(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $token = $this->parentToken($student->id);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'hacker_category',
            'content'  => '這是一個足夠長的測試內容，超過十個字',
        ], $this->bearer($token))
            ->assertStatus(422);
    }

    public function test_unauthenticated_parent_cannot_submit(): void
    {
        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'other',
            'content'  => '這是一個足夠長的測試內容，超過十個字',
        ])->assertStatus(401);
    }

    public function test_expired_parent_token_is_rejected(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $raw = Str::random(32);
        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->subHour(),
        ]);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'other',
            'content'  => '這是一個足夠長的測試內容，超過十個字',
        ], $this->bearer($raw))
            ->assertStatus(401);
    }

    // ─── Super Admin 端：查看 / 標記 ────────────────────────────────

    public function test_super_admin_can_list_feedback(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $token = $this->parentToken($student->id);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'schedule',
            'content'  => '希望排課時間可以更彈性，方便我們接送',
            'rating'   => 4,
        ], $this->bearer($token))->assertCreated();

        $admin = $this->superAdmin($campus->id);
        $this->getJson("/api/v1/parent-feedback?campus_id={$campus->id}", $this->bearer($admin['token']))
            ->assertOk()
            ->assertJsonPath('data.0.category', 'schedule')
            ->assertJsonPath('data.0.rating', 4);
    }

    public function test_super_admin_can_mark_feedback_read(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $pToken = $this->parentToken($student->id);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'other',
            'content'  => '系統用起來很方便，希望能繼續保持品質！',
        ], $this->bearer($pToken))->assertCreated();

        $id = DB::table('parent_feedback')->latest('id')->value('id');
        $admin = $this->superAdmin($campus->id);

        $this->postJson("/api/v1/parent-feedback/{$id}/mark-read", [], $this->bearer($admin['token']))
            ->assertOk();

        $this->assertDatabaseHas('parent_feedback', ['id' => $id, 'is_read' => true]);
    }

    public function test_non_super_admin_cannot_list_feedback(): void
    {
        $campus = CampusFactory::new()->create();
        $director = User::create([
            'LoginName' => 'dir@test.com', 'Name' => 'Director', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0912345678', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $this->getJson("/api/v1/parent-feedback?campus_id={$campus->id}", $this->bearer($token))
            ->assertStatus(403);
    }

    public function test_unread_count_returns_correct_number(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $pToken = $this->parentToken($student->id);
        $admin = $this->superAdmin($campus->id);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'other',
            'content'  => '第一則足夠長的回饋，測試未讀計數功能正常運作',
        ], $this->bearer($pToken))->assertCreated();

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'teaching',
            'content'  => '第二則足夠長的回饋，測試未讀計數功能正常運作',
        ], $this->bearer($pToken))->assertCreated();

        $this->getJson("/api/v1/parent-feedback/unread-count?campus_id={$campus->id}", $this->bearer($admin['token']))
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_teacher_cannot_mark_read_other_teachers_student_feedback(): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $ownerTeacher = $this->teacherStaff($campus->id, 'owner-teacher@test.com');
        $otherTeacher = $this->teacherStaff($campus->id, 'other-teacher@test.com');
        $this->createStudentClass($student->id, $ownerTeacher['user']->id);

        $pToken = $this->parentToken($student->id);
        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'teaching',
            'content'  => '這是老師專屬學生的家長回饋，其他老師不應能讀取',
        ], $this->bearer($pToken))->assertCreated();

        $feedbackId = (int) DB::table('parent_feedback')->latest('id')->value('id');

        $this->postJson(
            "/api/v1/parent-feedback/{$feedbackId}/read",
            [],
            array_merge($this->bearer($otherTeacher['token']), ['X-Branch-Id' => (string) $campus->id])
        )->assertStatus(403);
    }

    public function test_director_cannot_access_other_campus_feedback_replies(): void
    {
        $campusA = CampusFactory::new()->create();
        $campusB = CampusFactory::new()->create();
        $studentB = StudentFactory::new()->create(['CampusID' => $campusB->id]);
        $pToken = $this->parentToken($studentB->id);

        $this->postJson('/api/v1/parent/feedback', [
            'category' => 'system',
            'content'  => 'B分校家長回饋，A分校主任不應能查看回覆串',
        ], $this->bearer($pToken))->assertCreated();

        $feedbackId = (int) DB::table('parent_feedback')->latest('id')->value('id');
        $directorA = $this->directorStaff($campusA->id);

        $this->getJson(
            "/api/v1/parent-feedback/{$feedbackId}/replies",
            array_merge($this->bearer($directorA['token']), ['X-Branch-Id' => (string) $campusA->id])
        )->assertStatus(403);
    }

    // ─── Helpers ────────────────────────────────────────────────────

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

    private function superAdmin(int $campusId): array
    {
        $user = User::create([
            'LoginName' => Str::random(6) . '@sa.com',
            'Name'      => 'SuperAdmin',
            'PSW'       => 'secret',
            'type'      => 'S',
            'phone'     => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['user' => $user, 'token' => $token];
    }

    private function teacherStaff(int $campusId, string $email): array
    {
        $user = User::create([
            'LoginName' => $email,
            'Name' => 'Teacher',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['user' => $user, 'token' => $token];
    }

    private function directorStaff(int $campusId): array
    {
        $user = User::create([
            'LoginName' => Str::random(6) . '@dir.com',
            'Name' => 'Director',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['user' => $user, 'token' => $token];
    }

    private function createStudentClass(int $studentId, int $teacherId): int
    {
        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'RoomID' => '',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 3,
            'UsedSessions' => 5,
            'Stop' => 0,
        ]);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
