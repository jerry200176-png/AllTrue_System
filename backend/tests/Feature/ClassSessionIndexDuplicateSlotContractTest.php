<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1043 — API contract: GET /class-sessions must not return duplicate
 * student+date+slot rows for legacy identical-status duplicates.
 *
 * Scoped narrower than a blanket "one row per slot" rule: rows that
 * legitimately coexist with *different* statuses (cancelled+scheduled,
 * leave+scheduled) must still both be returned — see
 * ClassSessionDuplicateStatusTest, which this test must not regress.
 */
class ClassSessionIndexDuplicateSlotContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_status_duplicate_rows_collapse_to_one(): void
    {
        $token = $this->createUserToken('dup-slot@example.com');
        $student = $this->createStudent(1, '重複堂次契約測試');
        $courseId = $this->createCourse($student->id, 1);

        $keeperId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-05-10',
            'StartTime' => '19:30',
            'EndTime' => '21:30',
            'Status' => 'scheduled',
            'Note' => 'first',
        ]);
        $duplicateId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-05-10',
            'StartTime' => '19:30',
            'EndTime' => '21:30',
            'Status' => 'scheduled',
            'Note' => 'duplicate',
        ]);
        $this->assertGreaterThan($keeperId, $duplicateId);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $may10 = collect($res->json('data'))
            ->filter(fn ($r) => str_starts_with((string) $r['session_date'], '2026-05-10'));

        $this->assertCount(1, $may10, 'Identical-status duplicate rows for the same slot must collapse to one');
        $this->assertSame($duplicateId, (int) $may10->first()['id'], 'Keeps the higher-id (most recent) row');
    }

    public function test_cancelled_plus_scheduled_still_returns_both(): void
    {
        $token = $this->createUserToken('dup-slot-mixed@example.com');
        $student = $this->createStudent(1, '混合狀態不合併測試');
        $courseId = $this->createCourse($student->id, 1);

        DB::table('ClassSession')->insert([
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-05-11', 'StartTime' => '19:30', 'EndTime' => '21:30', 'Status' => 'cancelled', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-05-11', 'StartTime' => '19:30', 'EndTime' => '21:30', 'Status' => 'scheduled', 'Note' => ''],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $may11 = collect($res->json('data'))
            ->filter(fn ($r) => str_starts_with((string) $r['session_date'], '2026-05-11'));

        $this->assertCount(2, $may11, 'Different-status rows for the same slot must not be collapsed');
    }

    // --- helpers ---

    private function createUserToken(string $email): string
    {
        $user = User::create([
            'LoginName' => $email,
            'Name' => 'contract-test-user',
            'PSW' => bcrypt('test'),
            'type' => 'A',
            'status' => 'active',
        ]);
        UserCampus::firstOrCreate(
            ['UserID' => $user->id, 'CampusID' => 1],
            ['Admin' => 1, 'Approved' => 1]
        );
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

    private function createCourse(int $studentId, int $teacherId): int
    {
        return DB::table('StudentClass')->insertGetId([
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
        ]);
    }
}
