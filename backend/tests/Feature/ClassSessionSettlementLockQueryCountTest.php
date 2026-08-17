<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1731 — Sentry PHP-LARAVEL-2A N+1 Query
 * `select exists(select * from StudentClass …)` on every ClassSession create/update.
 */
class ClassSessionSettlementLockQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_session_creates_do_not_repeat_student_class_exists(): void
    {
        $courseIds = [];
        for ($i = 0; $i < 8; $i++) {
            $courseIds[] = (int) $this->course("N+1 學生{$i}")->getKey();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ($courseIds as $index => $courseId) {
            ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-08-17',
                'StartTime' => sprintf('%02d:00', 10 + $index),
                'EndTime' => sprintf('%02d:00', 11 + $index),
                'Status' => 'scheduled',
            ]);
        }

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $studentClassLockQueries = 0;
        $existsPerIdQueries = 0;
        foreach ($log as $entry) {
            $sql = strtolower((string) ($entry['query'] ?? ''));
            if (!str_contains($sql, 'studentclass')) {
                continue;
            }
            if (str_contains($sql, 'exists') && str_contains($sql, '`id` = ?')) {
                $existsPerIdQueries++;
            }
            if (str_contains($sql, 'settlement_locked_at')) {
                $studentClassLockQueries++;
            }
        }

        $this->assertSame(
            0,
            $existsPerIdQueries,
            "per-row StudentClass exists() came back (N+1):\n".$this->dumpQueries($log)
        );
        $this->assertLessThanOrEqual(
            1,
            $studentClassLockQueries,
            "settlement-lock lookup should be one batched query, got {$studentClassLockQueries}:\n".$this->dumpQueries($log)
        );
        $this->assertSame(8, ClassSession::query()->count());
    }

    private function course(string $studentName): StudentClass
    {
        $student = Student::create([
            'name' => $studentName,
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test School',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
    }

    /**
     * @param  list<array{query?:string,bindings?:array}>  $log
     */
    private function dumpQueries(array $log): string
    {
        $lines = [];
        foreach ($log as $entry) {
            $sql = (string) ($entry['query'] ?? '');
            if (str_contains(strtolower($sql), 'studentclass')) {
                $lines[] = $sql;
            }
        }

        return implode("\n", $lines);
    }
}
