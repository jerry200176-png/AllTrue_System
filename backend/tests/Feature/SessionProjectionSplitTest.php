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

class SessionProjectionSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_dates_returns_materialized_and_projected_buckets(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => 'Split Test', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $courseId = $this->makeCourse($student->id);

        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-03-07',
            'StartTime' => '15:00:00',
            'EndTime' => '16:00:00',
            'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', [
            'branch_id' => 1,
            'range_start' => '2026-03-01',
            'range_end' => '2026-07-31',
            'courses' => [[
                'id' => $courseId,
                'first_class_date' => '2026-03-07',
                'sessions_purchased' => 20,
                'days_of_week' => [5],
            ]],
        ]);

        $res->assertOk();
        $payload = $res->json((string) $courseId);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('materialized', $payload);
        $this->assertArrayHasKey('projected', $payload);

        $this->assertCount(1, $payload['materialized']);
        $this->assertArrayHasKey('id', $payload['materialized'][0]);
        $this->assertSame($courseId, (int) $payload['materialized'][0]['student_class_id']);

        foreach ($payload['projected'] as $slot) {
            $this->assertArrayNotHasKey('id', $slot);
            $this->assertSame('projected', $slot['status']);
            $this->assertSame('projected', $slot['kind']);
        }
    }

    public function test_class_sessions_index_exposes_materialized_and_projected_sections(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => 'Index Split', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $courseId = $this->makeCourse($student->id, [
            'ScheduleMode' => 'date',
            'week' => 5,
            'time' => '15:00',
            'StartDate' => '2026-03-01 00:00:00',
            'EndDate' => '2026-12-31 00:00:00',
        ]);

        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-03-07',
            'StartTime' => '15:00:00',
            'EndTime' => '16:00:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$courseId}&start=2026-03-01&end=2026-03-31");

        $res->assertOk();
        $res->assertJsonStructure([
            'materialized' => ['data', 'by_class'],
            'projected' => ['by_class'],
        ]);

        $materialized = $res->json('materialized.data') ?? [];
        $this->assertNotEmpty($materialized);
        $this->assertArrayHasKey('id', $materialized[0]);

        $projected = $res->json('projected.by_class.' . $courseId) ?? [];
        foreach ($projected as $slot) {
            $this->assertArrayNotHasKey('id', $slot);
        }
    }

    private function makeToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'split-test@example.com',
            'Name' => 'Split Tester',
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
            'RoomID' => '',
            'SessionCount' => 20,
            'SessionDuration' => 60,
            'RemainingSessions' => 14,
            'UsedSessions' => 6,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'week' => 5,
            'time' => '15:00',
        ], $extra));
    }
}
