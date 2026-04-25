<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentLearningRecordFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_upsert_feedback_for_own_approved_learning_record(): void
    {
        $setup = $this->makeApprovedRecord();
        $token = $this->makeParentToken($setup['student']);

        $res = $this->putJson("/api/v1/parent/learning-records/{$setup['record_id']}/feedback", [
            'content' => '孩子回家說英文單字還不熟，想請老師下次協助加強。',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $res->assertJsonPath('feedback.learning_record_id', $setup['record_id']);
        $res->assertJsonPath('feedback.content', '孩子回家說英文單字還不熟，想請老師下次協助加強。');

        $this->assertDatabaseHas('learning_record_feedbacks', [
            'learning_record_id' => $setup['record_id'],
            'student_id' => $setup['student']->id,
            'teacher_id' => $setup['teacher']->id,
            'campus_id' => $setup['campus']->id,
            'content' => '孩子回家說英文單字還不熟，想請老師下次協助加強。',
        ]);

        $this->putJson("/api/v1/parent/learning-records/{$setup['record_id']}/feedback", [
            'content' => '補充：孩子已經複習一次，謝謝老師。',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk();

        $this->assertSame(1, DB::table('learning_record_feedbacks')
            ->where('learning_record_id', $setup['record_id'])
            ->count());
        $this->assertDatabaseHas('learning_record_feedbacks', [
            'learning_record_id' => $setup['record_id'],
            'content' => '補充：孩子已經複習一次，謝謝老師。',
        ]);
    }

    public function test_parent_cannot_feedback_other_students_learning_record(): void
    {
        $own = $this->makeApprovedRecord();
        $other = $this->makeApprovedRecord();
        $token = $this->makeParentToken($own['student']);

        $this->putJson("/api/v1/parent/learning-records/{$other['record_id']}/feedback", [
            'content' => '這不是我的孩子，不能留言。',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('learning_record_feedbacks', [
            'learning_record_id' => $other['record_id'],
        ]);
    }

    public function test_parent_cannot_feedback_pending_learning_record(): void
    {
        $setup = $this->makeApprovedRecord(['record_status' => 'pending']);
        $token = $this->makeParentToken($setup['student']);

        $this->putJson("/api/v1/parent/learning-records/{$setup['record_id']}/feedback", [
            'content' => '尚未核准的評量不應開放家長回饋。',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(409);
    }

    public function test_feedback_content_is_required_and_limited_to_500_chars(): void
    {
        $setup = $this->makeApprovedRecord();
        $token = $this->makeParentToken($setup['student']);

        $this->putJson("/api/v1/parent/learning-records/{$setup['record_id']}/feedback", [
            'content' => '   ',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(422);

        $this->putJson("/api/v1/parent/learning-records/{$setup['record_id']}/feedback", [
            'content' => Str::repeat('a', 501),
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(422);
    }

    public function test_teacher_lists_only_own_learning_record_feedbacks(): void
    {
        $own = $this->makeApprovedRecord();
        $other = $this->makeApprovedRecord();
        $this->submitParentFeedback($own, '給自己老師看的回饋');
        $this->submitParentFeedback($other, '不該被其他老師看到');

        $teacherToken = $this->makeStaffToken($own['teacher'], [$own['campus']->id]);

        $res = $this->getJson('/api/v1/learning-record-feedbacks', [
            'Authorization' => 'Bearer ' . $teacherToken,
        ]);

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('learning_record_id')->all();
        $this->assertContains($own['record_id'], $ids);
        $this->assertNotContains($other['record_id'], $ids);
    }

    public function test_director_feedback_list_is_scoped_by_accessible_campus(): void
    {
        $campusA = Campus::factory()->create();
        $campusB = Campus::factory()->create();
        $sameCampus = $this->makeApprovedRecord(['campus' => $campusA]);
        $otherCampus = $this->makeApprovedRecord(['campus' => $campusB]);
        $this->submitParentFeedback($sameCampus, '同分校主任可看');
        $this->submitParentFeedback($otherCampus, '跨分校主任不可看');

        $director = User::create([
            'LoginName' => 'feedback-director@test.com',
            'Name' => 'Feedback Director',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000222',
            'MustChangePassword' => false,
        ]);
        $directorToken = $this->makeStaffToken($director, [$campusA->id]);

        $res = $this->getJson("/api/v1/learning-record-feedbacks?branch_id={$campusA->id}", [
            'Authorization' => 'Bearer ' . $directorToken,
        ]);

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('learning_record_id')->all();
        $this->assertContains($sameCampus['record_id'], $ids);
        $this->assertNotContains($otherCampus['record_id'], $ids);

        $this->getJson("/api/v1/learning-record-feedbacks?branch_id={$campusB->id}", [
            'Authorization' => 'Bearer ' . $directorToken,
        ])->assertStatus(403);
    }

    private function submitParentFeedback(array $setup, string $content): void
    {
        $token = $this->makeParentToken($setup['student']);
        $this->putJson("/api/v1/parent/learning-records/{$setup['record_id']}/feedback", [
            'content' => $content,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk();
    }

    private function makeApprovedRecord(array $overrides = []): array
    {
        $campus = $overrides['campus'] ?? Campus::factory()->create();
        $teacher = $overrides['teacher'] ?? User::create([
            'LoginName' => 'feedback-teacher-' . Str::random(8) . '@test.com',
            'Name' => 'Feedback Teacher',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campus->id,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $student = Student::factory()->create([
            'CampusID' => $campus->id,
            'name' => '回饋測試學生' . Str::random(4),
            'Phone' => '0911' . random_int(100000, 999999),
        ]);

        $studentClassId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'TeacherID' => $teacher->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(30)->toDateString(),
            'RoomID' => '',
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

        $sessionDate = now()->subDay()->toDateString();
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $studentClassId,
            'SessionDate' => $sessionDate,
            'StartTime' => '14:00',
            'EndTime' => '15:00',
            'Status' => 'attended',
        ]);

        $recordId = DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id,
            'StudentClassID' => $studentClassId,
            'ClassSessionID' => $sessionId,
            'TeacherID' => $teacher->id,
            'Subject' => 'English',
            'SessionDate' => $sessionDate,
            'StartTime' => '14:00',
            'EndTime' => '15:00',
            'Content' => '課堂內容',
            'Status' => $overrides['record_status'] ?? 'approved',
            'ApprovedBy' => 1,
            'ApprovedAt' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'campus' => $campus,
            'teacher' => $teacher,
            'student' => $student,
            'student_class_id' => $studentClassId,
            'session_id' => $sessionId,
            'record_id' => $recordId,
        ];
    }

    private function makeParentToken(Student $student): string
    {
        $raw = Str::random(32);
        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addHours(2),
        ]);

        return $raw;
    }

    private function makeStaffToken(User $user, array $campusIds): string
    {
        foreach ($campusIds as $campusId) {
            UserCampus::firstOrCreate([
                'CampusID' => $campusId,
                'UserID' => $user->id,
            ], [
                'Admin' => $user->type !== 'T' ? 1 : 0,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
