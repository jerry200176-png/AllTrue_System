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

class LearningRecordLatestApprovedSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_approved_summary_returns_previous_record_for_same_student_class(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->makeUser('A', '主任');
        $teacher = $this->makeUser('T', '老師甲');
        $token = $this->makeStaffToken($director, [$campus->id]);

        UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);

        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'TeacherID' => $teacher->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-01',
            'RoomID' => '',
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'Stop' => 0,
            'RemainingSessions' => 8,
            'UsedSessions' => 2,
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
        ]);

        $csOld = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => '2026-05-10',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'attended',
        ]);
        $oldApprovedId = DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $csOld,
            'TeacherID' => $teacher->id,
            'Subject' => 'Math',
            'SessionDate' => '2026-05-10',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Content' => '教材講解與練習',
            'Progress' => '課本 p.12~18',
            'NextHomework' => '習作 p.20',
            'Comment' => '專注度提升',
            'Status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $csNew = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => '2026-05-17',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);
        $editingRecordId = DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $csNew,
            'TeacherID' => $teacher->id,
            'Subject' => 'Math',
            'SessionDate' => '2026-05-17',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Content' => '',
            'Progress' => '',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->getJson(
            "/api/v1/learning-records/latest-approved-summary?student_class_id={$scId}&session_date=2026-05-17&exclude_id={$editingRecordId}",
            $this->bearer($token)
        );

        $res->assertOk();
        $res->assertJsonPath('summary.id', (int) $oldApprovedId);
        $res->assertJsonPath('summary.session_date', '2026-05-10');
        $res->assertJsonPath('summary.progress', '課本 p.12~18');
        $res->assertJsonPath('summary.next_homework', '習作 p.20');
    }

    private function makeUser(string $type, string $name): User
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

    private function makeStaffToken(User $user, array $campusIds): string
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
