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
     * @return array{bodyIds:list<int>, visibleCount:int, sessionCount:int, scheduleCount:int}
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
     * @param  list<int>  $bodyIds
     * @return list<array<string, mixed>>
     */
    private function makeBodyCourses(array $bodyIds): array
    {
        return array_map(static fn (int $id): array => [
            'id' => $id,
            'first_class_date' => '2026-01-01',
            'sessions_purchased' => 24,
            'days_of_week' => [5],
        ], $bodyIds);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $bodyCourses
     * @return array<string, mixed>
     */
    private function summarizeContent(array $payload, array $bodyCourses): array
    {
        $courses = [];
        foreach ($bodyCourses as $course) {
            $id = (string) $course['id'];
            $entry = $payload[$id] ?? [];
            $normalize = static function ($rows): array {
                return array_map(static fn (array $row): array => [
                    'kind' => (string) ($row['kind'] ?? ''),
                    'session_date' => (string) ($row['session_date'] ?? ''),
                    'start_time' => (string) ($row['start_time'] ?? ''),
                    'end_time' => (string) ($row['end_time'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                ], is_array($rows) ? $rows : []);
            };
            $materialized = $normalize($entry['materialized'] ?? []);
            $projected = $normalize($entry['projected'] ?? []);
            $courses[$id] = [
                'materialized_count' => count($materialized),
                'projected_count' => count($projected),
                'semantic_hash' => hash('sha256', json_encode([
                    'materialized' => $materialized,
                    'projected' => $projected,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ];
        }

        return [
            'requested_course_count' => count($bodyCourses),
            'response_course_count' => count($payload),
            'requested_courses' => $courses,
        ];
    }

    /**
     * @return array{status:int, elapsed_ms:float, query_count:int, query_ms:float, response_bytes:int, memory_delta_bytes:int, peak_memory_delta_bytes:int, content:array<string, mixed>}
     */
    private function measure(string $token, array $bodyCourses): array
    {
        $queryCount = 0;
        $queryMs = 0.0;
        DB::listen(static function ($query) use (&$queryCount, &$queryMs): void {
            $queryCount++;
            $queryMs += (float) $query->time;
        });

        $memoryBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);
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
        $memoryAfter = memory_get_usage(true);
        $peakAfter = memory_get_peak_usage(true);
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
            'memory_delta_bytes' => max(0, $memoryAfter - $memoryBefore),
            'peak_memory_delta_bytes' => max(0, $peakAfter - $peakBefore),
            'content' => $this->summarizeContent($payload, $bodyCourses),
        ];
    }

    /**
     * @param  array{bodyIds:list<int>, visibleCount:int, sessionCount:int, scheduleCount:int}  $fixture
     * @return array<string, mixed>
     */
    private function workModel(array $fixture, int $bodyCount): array
    {
        return [
            'visible_class_scan_passes_before' => $fixture['visibleCount'],
            'visible_session_row_inspections_before' => $fixture['visibleCount'] * $fixture['sessionCount'],
            'visible_session_row_inspections_after' => $fixture['sessionCount'],
            'visible_schedule_row_inspections_before' => $fixture['visibleCount'] * $fixture['scheduleCount'],
            'visible_schedule_row_inspections_after' => $fixture['scheduleCount'],
            'projection_rescan_rows_before_upper_bound' => $fixture['visibleCount'] * $fixture['sessionCount'],
            'projection_rescan_rows_after_upper_bound' => $fixture['sessionCount'],
            'body_course_count' => $bodyCount,
            'historical_rows_are_synthetic' => true,
            'model_is_work_not_cpu_time' => true,
        ];
    }

    public function test_fixed_fixture_reports_query_and_scan_workload(): void
    {
        $token = $this->makeToken();
        $fixture = $this->seedWorkload(12, 18, 6);
        $measurements = [];
        foreach ([1, 3, 6] as $bodyCount) {
            $bodyCourses = $this->makeBodyCourses(array_slice($fixture['bodyIds'], 0, $bodyCount));
            $measurements[(string) $bodyCount] = [
                'first' => $this->measure($token, $bodyCourses),
                'repeat' => $this->measure($token, $bodyCourses),
            ];
        }
        $metrics = [
            'case' => 'axis-a-visible-12-history-18-body-1-3-6',
            'fixture' => $fixture,
            'measurements' => $measurements,
            'work_model' => $this->workModel($fixture, 6),
            'contract_coverage' => [
                'count' => true,
                'date' => true,
                'manual_occurrence' => true,
                'package_fallback' => 'regression-covered separately; not included in timing fixture',
            ],
        ];
        fwrite(STDOUT, 'SESSION_DATES_WORKLOAD ' . json_encode($metrics, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    /**
     * @dataProvider singleCourseScalingCases
     */
    public function test_single_course_scales_by_visible_and_history_rows(string $case, int $visibleCount, int $historyPerCourse): void
    {
        $token = $this->makeToken();
        $fixture = $this->seedWorkload($visibleCount, $historyPerCourse, 1);
        $bodyCourses = $this->makeBodyCourses($fixture['bodyIds']);
        $metrics = [
            'case' => $case,
            'fixture' => $fixture,
            'first' => $this->measure($token, $bodyCourses),
            'repeat' => $this->measure($token, $bodyCourses),
            'work_model' => $this->workModel($fixture, 1),
        ];
        fwrite(STDOUT, 'SESSION_DATES_WORKLOAD ' . json_encode($metrics, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    public static function singleCourseScalingCases(): array
    {
        return [
            ['axis-b-visible-4-history-18-body-1', 4, 18],
            ['axis-b-visible-12-history-18-body-1', 12, 18],
            ['axis-b-history-6-visible-12-body-1', 12, 6],
            ['axis-b-history-18-visible-12-body-1', 12, 18],
        ];
    }
}
