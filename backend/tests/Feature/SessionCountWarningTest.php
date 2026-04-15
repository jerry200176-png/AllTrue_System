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
 * Regression test: class-sessions API must return status values that let the
 * frontend compute "effective session count" (sessions occupying purchased quota)
 * correctly. Statuses that do NOT occupy quota: cancelled, leave, leave_adjusted.
 *
 * CaseA: purchased=8, 6 attended + 1 leave + 1 scheduled + 1 scheduled(makeup) => effective=8 => no warning
 * CaseB: purchased=8, 9 scheduled => effective=9 => warning (over)
 * CaseC: purchased=8, 6 attended + 1 leave + 0 makeup => effective=7 => warning (under, has leave)
 * CaseD: same-slot cancelled+scheduled => effective counts only scheduled
 * CaseE: historical excused status => treated like leave (not counted)
 */
class SessionCountWarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_a_leave_plus_makeup_equals_purchased_no_warning(): void
    {
        $token = $this->createUserToken('A', [1], 'warn-a@example.com');
        $student = $this->createStudent(1, '警示A');
        $courseId = $this->createCourse($student->id, 1, [
            'SessionCount' => 8, 'RemainingSessions' => 2, 'UsedSessions' => 6,
        ]);

        // Real scenario: purchased=8, 6 attended + 2 scheduled = 8 effective
        // Plus 1 leave + 1 leave_adjusted = 2 non-quota rows => total 10 rows
        $sessions = [];
        for ($i = 1; $i <= 6; $i++) {
            $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => "2026-03-0{$i}", 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended', 'Note' => ''];
        }
        $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-11', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'leave_adjusted', 'Note' => '補請假'];
        $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-18', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'leave', 'Note' => '請假'];
        $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-25', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => ''];
        $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => '2026-05-02', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => ''];
        DB::table('ClassSession')->insert($sessions);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $data = collect($res->json('data'));

        $statusCounts = $data->groupBy('status')->map->count();
        $nonQuota = ['cancelled', 'leave', 'leave_adjusted', 'excused'];
        $effective = $data->reject(fn ($r) => in_array($r['status'], $nonQuota))->count();

        $this->assertEquals(8, $effective, 'Effective count should equal purchased (8)');
        $this->assertEquals(1, $statusCounts->get('leave', 0), 'Should have 1 leave');
    }

    public function test_case_b_over_scheduled_triggers_warning(): void
    {
        $token = $this->createUserToken('B', [1], 'warn-b@example.com');
        $student = $this->createStudent(1, '警示B');
        $courseId = $this->createCourse($student->id, 1, [
            'SessionCount' => 8, 'RemainingSessions' => 8, 'UsedSessions' => 0,
        ]);

        $sessions = [];
        for ($i = 1; $i <= 9; $i++) {
            $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => "2026-04-" . str_pad($i, 2, '0', STR_PAD_LEFT), 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => ''];
        }
        DB::table('ClassSession')->insert($sessions);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $data = collect($res->json('data'));
        $nonQuota = ['cancelled', 'leave', 'leave_adjusted', 'excused'];
        $effective = $data->reject(fn ($r) => in_array($r['status'], $nonQuota))->count();

        $this->assertEquals(9, $effective, 'Effective=9 > purchased=8 means over-scheduled');
    }

    public function test_case_c_leave_without_makeup_under_purchased(): void
    {
        $token = $this->createUserToken('C', [1], 'warn-c@example.com');
        $student = $this->createStudent(1, '警示C');
        $courseId = $this->createCourse($student->id, 1, [
            'SessionCount' => 8, 'RemainingSessions' => 2, 'UsedSessions' => 6,
        ]);

        $sessions = [];
        for ($i = 1; $i <= 6; $i++) {
            $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => "2026-03-0{$i}", 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended', 'Note' => ''];
        }
        $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-11', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'leave', 'Note' => '請假'];
        $sessions[] = ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-25', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => ''];
        DB::table('ClassSession')->insert($sessions);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $data = collect($res->json('data'));
        $nonQuota = ['cancelled', 'leave', 'leave_adjusted', 'excused'];
        $effective = $data->reject(fn ($r) => in_array($r['status'], $nonQuota))->count();

        $this->assertEquals(7, $effective, 'Effective=7 < purchased=8 (leave not yet made up)');
        $this->assertEquals(1, $data->where('status', 'leave')->count());
    }

    public function test_case_d_cancelled_plus_scheduled_same_slot(): void
    {
        $token = $this->createUserToken('D', [1], 'warn-d@example.com');
        $student = $this->createStudent(1, '警示D');
        $courseId = $this->createCourse($student->id, 1, [
            'SessionCount' => 4, 'RemainingSessions' => 2, 'UsedSessions' => 2,
        ]);

        DB::table('ClassSession')->insert([
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-15', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'cancelled', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-15', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-08', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-22', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => ''],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $data = collect($res->json('data'));
        $nonQuota = ['cancelled', 'leave', 'leave_adjusted', 'excused'];
        $effective = $data->reject(fn ($r) => in_array($r['status'], $nonQuota))->count();

        $this->assertEquals(4, $effective, 'cancelled row must not inflate effective count');
    }

    public function test_case_e_historical_excused_treated_as_leave(): void
    {
        $token = $this->createUserToken('E', [1], 'warn-e@example.com');
        $student = $this->createStudent(1, '警示E');
        $courseId = $this->createCourse($student->id, 1, [
            'SessionCount' => 4, 'RemainingSessions' => 1, 'UsedSessions' => 3,
        ]);

        DB::table('ClassSession')->insert([
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-03-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-03-08', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-03-15', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended', 'Note' => ''],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-03-22', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'excused', 'Note' => '歷史公假'],
            ['StudentClassID' => $courseId, 'SessionDate' => '2026-04-05', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => ''],
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&branch_id=1");

        $res->assertOk();
        $data = collect($res->json('data'));
        $nonQuota = ['cancelled', 'leave', 'leave_adjusted', 'excused'];
        $effective = $data->reject(fn ($r) => in_array($r['status'], $nonQuota))->count();

        $this->assertEquals(4, $effective, 'excused should not occupy quota, effective=4 matches purchased');
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
            'StartDate' => now()->subDays(60)->toDateTimeString(),
            'RoomID' => '',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 2,
            'UsedSessions' => 6,
            'Stop' => 0,
        ], $overrides));
    }
}
