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

class ParentLearningRecordFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_upserts_feedback_for_own_approved_record(): void
    {
        $s = $this->record();
        $token = $this->parentToken($s['student_id']);

        $this->putJson($this->parentUrl($s), ['content' => '請老師下次協助加強英文單字'], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('feedback.learning_record_id', $s['record_id']);

        $this->putJson($this->parentUrl($s), ['content' => '更新：孩子已複習一次'], $this->bearer($token))
            ->assertOk();

        $this->assertSame(1, DB::table('learning_record_feedbacks')->where('learning_record_id', $s['record_id'])->count());
        $this->assertDatabaseHas('learning_record_feedbacks', [
            'learning_record_id' => $s['record_id'],
            'student_id' => $s['student_id'],
            'teacher_id' => $s['teacher_id'],
            'campus_id' => $s['campus_id'],
            'content' => '更新：孩子已複習一次',
        ]);
    }

    public function test_parent_feedback_guards_ownership_status_and_content(): void
    {
        $own = $this->record();
        $other = $this->record();
        $pending = $this->record(['status' => 'pending']);
        $token = $this->parentToken($own['student_id']);

        $this->putJson($this->parentUrl($other), ['content' => '越權'], $this->bearer($token))->assertStatus(403);
        $this->putJson($this->parentUrl($pending), ['content' => '未核准'], $this->bearer($this->parentToken($pending['student_id'])))->assertStatus(409);
        $this->putJson($this->parentUrl($own), ['content' => '   '], $this->bearer($token))->assertStatus(422);
        $this->putJson($this->parentUrl($own), ['content' => Str::repeat('a', 501)], $this->bearer($token))->assertStatus(422);
    }

    public function test_staff_feedback_list_scopes_teacher_and_director_campus(): void
    {
        $campusA = CampusFactory::new()->create();
        $campusB = CampusFactory::new()->create();
        $a = $this->record(['campus' => $campusA]);
        $b = $this->record(['campus' => $campusB]);
        $this->submit($a, '同分校');
        $this->submit($b, '跨分校');

        $teacherRes = $this->getJson('/api/v1/learning-record-feedbacks', $this->bearer($this->staffToken($a['teacher'], [$campusA->id])));
        $teacherRes->assertOk();
        $teacherIds = collect($teacherRes->json('data'))->pluck('learning_record_id')->all();
        $this->assertContains($a['record_id'], $teacherIds);
        $this->assertNotContains($b['record_id'], $teacherIds);

        $director = $this->user('A');
        $directorToken = $this->staffToken($director, [$campusA->id]);
        $directorRes = $this->getJson("/api/v1/learning-record-feedbacks?branch_id={$campusA->id}", $this->bearer($directorToken));
        $directorRes->assertOk();
        $directorIds = collect($directorRes->json('data'))->pluck('learning_record_id')->all();
        $this->assertContains($a['record_id'], $directorIds);
        $this->assertNotContains($b['record_id'], $directorIds);
        $this->getJson("/api/v1/learning-record-feedbacks?branch_id={$campusB->id}", $this->bearer($directorToken))->assertStatus(403);
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
