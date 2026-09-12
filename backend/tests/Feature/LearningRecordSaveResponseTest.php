<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningRecordSaveResponseTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider saveCases */
    public function test_save_returns_canonical_student_aliases_without_repairing_legacy_data(string $role, bool $editing, bool $wrongRequestStudent = false): void
    {
        $campus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $teacher = $this->staff('T', $campus->id);
        $actor = $role === 'T' ? $teacher : $this->staff('A', $campus->id);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $actor->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $day = now()->subDay()->toDateString();
        $courseId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id, 'TeacherID' => $teacher->id,
            'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => $day, 'TotalHours' => 10, 'Charge' => 1000,
            'Pay' => 500, 'Paid' => 0, 'Rate' => 1000, 'Stop' => 0,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionCount' => 10,
            'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ]);
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId, 'SessionDate' => $day,
            'StartTime' => '14:00', 'EndTime' => '15:00', 'Status' => 'attended',
        ]);
        $recordId = null;
        if ($editing) {
            $recordId = DB::table('LearningRecord')->insertGetId([
                // Production legacy column defaults to zero; the course owns identity.
                'StudentID' => 0, 'StudentClassID' => $courseId,
                'ClassSessionID' => $sessionId, 'TeacherID' => $teacher->id,
                'SessionDate' => $day, 'StartTime' => '14:00', 'EndTime' => '15:00',
                'Subject' => 'Chinese', 'Content' => '原內容', 'Status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $requestStudentId = $wrongRequestStudent
            ? StudentFactory::new()->create(['CampusID' => $campus->id])->id : $student->id;
        $response = $this->postJson('/api/v1/learning-records' . ($editing ? "/{$recordId}" : ''), [
            'StudentID' => $requestStudentId, 'TeacherID' => $teacher->id,
            'StudentClassID' => $courseId, 'ClassSessionID' => $sessionId,
            'SessionDate' => $day, 'Subject' => 'Chinese', 'Content' => '已更新內容',
        ], ['Authorization' => "Bearer {$token}"]);
        $response->assertStatus($editing ? 200 : 201)
            ->assertJsonPath('StudentID', $student->id)
            ->assertJsonPath('student_id', $student->id)
            ->assertJsonPath('TeacherID', $teacher->id)
            ->assertJsonPath('ClassSessionID', $sessionId)
            ->assertJsonPath('Status', 'pending');
        $savedId = $response->json('id');
        if ($editing) $this->assertSame($recordId, $savedId);
        $this->assertDatabaseHas('LearningRecord', [
            'id' => $savedId, 'StudentID' => 0, 'Content' => '已更新內容',
        ]);
        $this->assertSame(1, DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->count());
    }

    public static function saveCases(): array
    {
        return ['teacher create' => ['T', false], 'teacher edit' => ['T', true],
            'director create' => ['A', false], 'director edit' => ['A', true],
            'edit never echoes unrelated request student' => ['A', true, true]];
    }

    private function staff(string $type, int $campusId): User
    {
        $user = User::create(['LoginName' => Str::random(12), 'Name' => '測試教職員',
            'PSW' => 'test-only', 'type' => $type, 'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false]);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId,
            'Admin' => $type !== 'T', 'Approved' => 1]);
        return $user;
    }
}
