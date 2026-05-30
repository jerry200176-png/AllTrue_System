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

/**
 * #139/#595: the director review tabs derived their badge counts from a single client page, so
 * "待審 15" never matched the dashboard's server-side total. `with_status_counts=1` returns
 * authoritative per-status totals over the whole filtered scope, independent of per_page.
 */
class LearningRecordStatusCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_status_counts_returns_full_scope_totals_independent_of_per_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-30 12:00:00'));
        try {
            $campus = CampusFactory::new()->create();
            $director = $this->makeUser('A', '主任');
            $teacher = $this->makeUser('T', '老師');
            UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
            $directorToken = $this->makeStaffToken($director, [$campus->id]);

            $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
            $sc = $this->makeStudentClass($student->id, $teacher->id);

            // Create more than one page (per_page=2) worth of pending so client-page counting would undercount.
            for ($i = 0; $i < 5; $i++) {
                $this->makeLearningRecord($student->id, $sc, $teacher->id, '2026-05-' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'pending');
            }
            $this->makeLearningRecord($student->id, $sc, $teacher->id, '2026-05-20', 'changes_requested');
            $this->makeLearningRecord($student->id, $sc, $teacher->id, '2026-05-21', 'changes_requested');
            for ($i = 0; $i < 3; $i++) {
                $this->makeLearningRecord($student->id, $sc, $teacher->id, '2026-04-' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'approved');
            }

            $body = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&with_status_counts=1&per_page=2',
                $this->bearer($directorToken)
            )->assertOk()->json();

            $this->assertArrayHasKey('status_counts', $body, 'response must include status_counts when requested');
            $counts = $body['status_counts'];
            // Full-scope totals, NOT limited by per_page=2.
            $this->assertEquals(5, (int) ($counts['pending'] ?? 0));
            $this->assertEquals(2, (int) ($counts['changes_requested'] ?? 0));
            $this->assertEquals(3, (int) ($counts['approved'] ?? 0));
            // The list itself is still paginated.
            $this->assertCount(2, $body['data']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_with_status_counts_is_skipped_when_status_filter_present(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-30 12:00:00'));
        try {
            $campus = CampusFactory::new()->create();
            $director = $this->makeUser('A', '主任');
            $teacher = $this->makeUser('T', '老師');
            UserCampus::firstOrCreate(['CampusID' => $campus->id, 'UserID' => $teacher->id], ['Approved' => 1]);
            $directorToken = $this->makeStaffToken($director, [$campus->id]);
            $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
            $sc = $this->makeStudentClass($student->id, $teacher->id);
            $this->makeLearningRecord($student->id, $sc, $teacher->id, '2026-05-10', 'pending');

            $body = $this->getJson(
                '/api/v1/learning-records?branch_id=' . $campus->id . '&with_status_counts=1&status=pending&per_page=50',
                $this->bearer($directorToken)
            )->assertOk()->json();

            // When a status filter is applied the counts would be meaningless, so they are omitted.
            $this->assertArrayNotHasKey('status_counts', $body);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeStudentClass(int $studentId, int $teacherId): int
    {
        return DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-01-01',
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
    }

    private function makeLearningRecord(int $studentId, int $sc, int $teacherId, string $date, string $status): int
    {
        $cs = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc,
            'SessionDate' => $date,
            'StartTime' => '18:00',
            'EndTime' => '19:00',
            'Status' => 'attended',
        ]);

        return DB::table('LearningRecord')->insertGetId([
            'StudentID' => $studentId,
            'StudentClassID' => $sc,
            'ClassSessionID' => $cs,
            'TeacherID' => $teacherId,
            'Subject' => 'English',
            'SessionDate' => $date,
            'StartTime' => '18:00',
            'EndTime' => '19:00',
            'Content' => '課堂內容',
            'Status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
