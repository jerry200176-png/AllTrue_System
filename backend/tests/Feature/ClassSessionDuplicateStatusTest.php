<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression test: when a course has both cancelled + scheduled sessions
 * on the same date/time, the class-sessions API must return both rows
 * so the frontend can apply status-priority logic correctly.
 *
 * Root cause: 暫停→恢復 creates a new scheduled row alongside the old
 * cancelled row for the same (StudentClassID, SessionDate, StartTime).
 */
class ClassSessionDuplicateStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_returns_both_cancelled_and_scheduled_rows_for_same_date(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-dup@example.com');
        $student = $this->createStudent(1, '重複狀態測試');
        $courseId = $this->createCourse($student->id, 1, ['SessionCount' => 4, 'RemainingSessions' => 2, 'UsedSessions' => 2]);
        $targetDate = '2026-04-15';

        DB::table('ClassSession')->insert([
            ['StudentClassID' => $courseId, 'SessionDate' => $targetDate, 'StartTime' => '19:30', 'EndTime' => '21:30', 'Status' => 'cancelled', 'Note' => '暫停取消'],
            ['StudentClassID' => $courseId, 'SessionDate' => $targetDate, 'StartTime' => '19:30', 'EndTime' => '21:30', 'Status' => 'scheduled', 'Note' => ''],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertIsArray($data);

        $april15 = collect($data)->filter(fn ($r) => str_starts_with($r['session_date'], '2026-04-15'));
        $this->assertCount(2, $april15, 'Both cancelled and scheduled rows should be returned');

        $statuses = $april15->pluck('status')->sort()->values()->all();
        $this->assertEquals(['cancelled', 'scheduled'], $statuses);
    }

    public function test_all_cancelled_returns_only_cancelled(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-allcanc@example.com');
        $student = $this->createStudent(1, '全取消測試');
        $courseId = $this->createCourse($student->id, 1, ['SessionCount' => 2, 'RemainingSessions' => 0, 'UsedSessions' => 0, 'Stop' => 1]);

        DB::table('ClassSession')->insert([
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-15', 'StartTime' => '19:30', 'EndTime' => '21:30', 'Status' => 'cancelled', 'Note' => ''],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $april15 = collect($res->json('data'))
            ->filter(fn ($r) => str_starts_with($r['session_date'], '2026-04-15'));
        $this->assertCount(1, $april15);
        $this->assertEquals('cancelled', $april15->first()['status']);
    }

    public function test_leave_plus_scheduled_returns_both(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-leavesched@example.com');
        $student = $this->createStudent(1, '請假加排課測試');
        $courseId = $this->createCourse($student->id, 1, ['SessionCount' => 4, 'RemainingSessions' => 3, 'UsedSessions' => 1]);

        DB::table('ClassSession')->insert([
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-15', 'StartTime' => '19:30', 'EndTime' => '21:30', 'Status' => 'leave', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-15', 'StartTime' => '19:30', 'EndTime' => '21:30', 'Status' => 'scheduled', 'Note' => ''],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $april15 = collect($res->json('data'))
            ->filter(fn ($r) => str_starts_with($r['session_date'], '2026-04-15'));
        $this->assertCount(2, $april15);
        $statuses = $april15->pluck('status')->sort()->values()->all();
        $this->assertEquals(['leave', 'scheduled'], $statuses);
    }

    // --- helpers ---

    private function createUserToken(string $suffix, array $campusIds, string $email): string
    {
        $user = User::create([
            'LoginName' => $email,
            'Name' => "test-user-{$suffix}",
            'PSW' => bcrypt('test'),
            'type' => 'A',
            'status' => 'active',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::firstOrCreate(
                ['UserID' => $user->id, 'CampusID' => $cid],
                ['Admin' => 1, 'Approved' => 1]
            );
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
        ]);
    }

    private function createCourse(int $studentId, int $teacherId, array $overrides = []): int
    {
        return DB::table('StudentClass')->insertGetId(array_merge([
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
            'Rate' => 0,
            'ClassType' => 'one_on_two',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 3,
            'UsedSessions' => 5,
            'Stop' => 0,
        ], $overrides));
    }
}
