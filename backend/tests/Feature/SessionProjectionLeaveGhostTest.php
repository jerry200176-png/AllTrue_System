<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GitHub #1101 (in-app #196): after registering leave on a Sunday 10-12 course,
 * course-detail session-dates must NOT fabricate a semi-transparent 16-18 projected
 * chip on the same date. Production had ClassSession leave rows at 10:00 but
 * collectMaterializedFromRows dropped leave status, so buildProjectedFromEffectiveDates
 * synthesized a phantom slot — and POST session-dates omitted time fields, so the
 * fallback resolved to the global default 16:00.
 */
class SessionProjectionLeaveGhostTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_session_materialized_and_no_phantom_projected_slot(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => '劉芯岑', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $courseId = $this->makeCourse($student->id, [
            'week' => 7,
            'time' => '10:00:00',
            'SessionDuration' => 120,
            'SessionCount' => 8,
            'RemainingSessions' => 7,
            'StartDate' => '2026-06-01 00:00:00',
        ]);

        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-07',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'leave',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', [
            'branch_id' => 1,
            'range_start' => '2026-06-01',
            'range_end' => '2026-07-31',
            'courses' => [[
                'id' => $courseId,
                'first_class_date' => '2026-06-01',
                'sessions_purchased' => 8,
                'days_of_week' => [7],
            ]],
        ]);

        $res->assertOk();
        $payload = $res->json((string) $courseId);
        $this->assertIsArray($payload);

        $materialized = $payload['materialized'] ?? [];
        $leaveRows = array_values(array_filter(
            $materialized,
            fn ($row) => ($row['session_date'] ?? '') === '2026-06-07'
        ));
        $this->assertCount(
            1,
            $leaveRows,
            'Leave ClassSession must appear in materialized bucket at real slot time'
        );
        $this->assertSame('10:00', $leaveRows[0]['start_time']);
        $this->assertSame('leave', $leaveRows[0]['status']);

        $projected = $payload['projected'] ?? [];
        $ghostOnLeaveDate = array_filter(
            $projected,
            fn ($row) => ($row['session_date'] ?? '') === '2026-06-07'
        );
        $this->assertEmpty(
            $ghostOnLeaveDate,
            'Must not synthesize projected slot on a date that already has a leave session'
        );

        $wrongTimeProjected = array_filter(
            $projected,
            fn ($row) => ($row['start_time'] ?? '') === '16:00'
        );
        $this->assertEmpty(
            $wrongTimeProjected,
            'Projected fallback must not default to 16:00 when contract time is 10:00'
        );
    }

    private function makeToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'leave-ghost@example.com',
            'Name' => 'Leave Ghost Tester',
            'PSW' => bcrypt('x'),
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

    private function makeCourse(int $studentId, array $extra = []): int
    {
        return (int) DB::table('StudentClass')->insertGetId(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_one',
            'StartDate' => now()->subMonths(6)->toDateTimeString(),
            'SessionCount' => 20,
            'SessionDuration' => 120,
            'RemainingSessions' => 14,
            'UsedSessions' => 6,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'week' => 5,
            'time' => '15:00',
        ], $extra));
    }
}
