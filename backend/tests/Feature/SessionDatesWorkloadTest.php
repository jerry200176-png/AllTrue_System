<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bounded read-only workload evidence for POST session-dates.
 *
 * This is deliberately diagnostic rather than a latency gate: CI hardware is
 * not production hardware. It records query count/time, response size and
 * deterministic fixture work so a before/after run can distinguish query
 * growth from repeated in-memory scans.
 */
class SessionDatesWorkloadTest extends TestCase
{
    use RefreshDatabase;

    private const BRANCH_ID = 1;

    private function makeToken(): string
    {
        $user = User::create([
            'LoginName' => 'session-dates-workload@example.com',
            'Name' => 'Session Dates Workload',
            'PSW' => Hash::make('fixture-only'),
            'type' => 'A',
            'status' => 'active',
        ]);
        UserCampus::create([
            'UserID' => $user->id,
            'CampusID' => self::BRANCH_ID,
            'Admin' => 1,
        ]);
        $token = hash('sha256', 'session-dates-workload-token');
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addHour(),
        ]);

        return $token;
    }

    /**
     * @return array{body_ids:list<int>, visible_count:int, session_count:int, schedule_count:int}
     */
    private function seedWorkload(int $visibleCount, int $historyPerCourse, int $bodyCount): array
    {
        $bodyIds = [];
        $sessionCount = 0;
        $scheduleCount = 0;

        for ($index = 1; $index <= $visibleCount; $index++) {
            $studentId = (int) Student::create([
                'name' => "Synthetic Student {$index}",
                'CampusID' => self::BRANCH_ID,
                'ClassID' => 1,
                'enable' => 1,
                'SchoolName' => 'Synthetic',
            ])->id;

            $mode = $index % 3 === 0 ? 'date' : 'count';
            $policy = $index % 5 === 0 ? 'manual_occurrence' : 'auto_recurrence';
            $courseId = (int) DB::table('StudentClass')->insertGetId([
                'StudentID' => $studentId,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 1,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-01-01 00:00:00',
                'EndDate' => '2026-12-31 00:00:00',
                'week' => 5,
                'time' => '15:00:00',
                'TotalHours' => 0,
                'Charge' => 0,
                'Pay' => 0,
                'Paid' => 0,
                'Rate' => 0,
                'Stop' => 0,
                'ScheduleMode' => $mode,
                'scheduling_policy' => $policy,
                'SessionCount' => 24,
                'RemainingSessions' => 24,
                'UsedSessions' => 0,
                'SessionDuration' => 60,
            ]);

            if ($index <= $bodyCount) {
                $bodyIds[] = $courseId;
            }

            for ($offset = 0; $offset < $historyPerCourse; $offset++) {
                $day = str_pad((string) (($offset % 27) + 1), 2, '0', STR_PAD_LEFT);
                $month = str_pad((string) (($offset % 8) + 1), 2, '0', STR_PAD_LEFT);
                ClassSession::create([
                    'StudentClassID' => $courseId,
                    'SessionDate' => "2026-{$month}-{$day}",
                    'StartTime' => '15:00:00',
                    'EndTime' => '16:00:00',
                    'Status' => $offset % 7 === 0 ? 'leave' : 'scheduled',
                ]);
                $sessionCount++;

                DB::table('schedules')->insert([
                    'student_id' => $studentId,
                    'teacher_id' => 1,
                    'day_of_week' => 5,
                    'start_time' => '15:00',
                    'end_time' => '16:00',
                    'duration_hours' => 1,
                    'class_type' => 'one_on_one',
                    'status' => $offset % 7 === 0 ? 'leave' : 'scheduled',
                    'type' => 'normal',
                    'deduction' => 1,
                    'branch_id' => self::BRANCH_ID,
                    'schedule_date' => "2026-{$month}-{$day}",
                    'student_course_id' => $courseId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $scheduleCount++;
            }
        }

        return compact('bodyIds', 'visibleCount', 'sessionCount', 'scheduleCount');
    }

    /**
     * @return array{status:int, elapsed_ms:float, query_count:int, query_ms:float, response_bytes:int, payload:array}
     */
    private function measure(string $token, array $bodyCourses): array
    {
        $queryCount = 0;
        $queryMs = 0.0;
        DB::listen(static function ($query) use (&$queryCount, &$queryMs): void {
            $queryCount++;
            $queryMs += (float) $query->time;
        });

        $started = hrtime(true);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', [
            'branch_id' => self::BRANCH_ID,
            'range_start' => '2026-08-01',
            'range_end' => '2026-09-30',
            'courses' => $bodyCourses,
        ]);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        $payload = $response->json();

        $this->assertSame(200, $response->status());
        $this->assertIsArray($payload);
        foreach ($bodyCourses as $course) {
            $this->assertArrayHasKey((string) $course['id'], $payload);
            $this->assertArrayHasKey('materialized', $payload[(string) $course['id']]);
            $this->assertArrayHasKey('projected', $payload[(string) $course['id']]);
        }

        return [
            'status' => $response->status(),
            'elapsed_ms' => round($elapsedMs, 3),
            'query_count' => $queryCount,
            'query_ms' => round($queryMs, 3),
            'response_bytes' => strlen($response->getContent()),
            'payload' => $payload,
        ];
    }

    public function test_fixed_fixture_reports_query_and_scan_workload(): void
    {
        $token = $this->makeToken();
        $fixture = $this->seedWorkload(12, 18, 3);
        $bodyCourses = array_map(static fn (int $id): array => [
            'id' => $id,
            'first_class_date' => '2026-01-01',
            'sessions_purchased' => 24,
            'days_of_week' => [5],
        ], $fixture['bodyIds']);

        $measurement = $this->measure($token, $bodyCourses);
        $metrics = [
            'case' => 'fixed-visible-12-history-18-body-3',
            'fixture' => $fixture,
            'measurement' => $measurement,
            'work_model' => [
                'visible_class_scan_passes' => $fixture['visibleCount'],
                'session_rows_available_to_handler' => $fixture['sessionCount'],
                'schedule_rows_available_to_handler' => $fixture['scheduleCount'],
                'historical_rows_are_synthetic' => true,
            ],
        ];
        fwrite(STDOUT, 'SESSION_DATES_WORKLOAD ' . json_encode($metrics, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
