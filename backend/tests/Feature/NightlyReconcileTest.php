<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for reconcile:nightly (App\Console\Commands\NightlyReconcile).
 *
 * The command compares StudentClass.UsedSessions with the same attendance,
 * completed-session, orphan-LearningRecord, ledger, and fractional-minute
 * semantics used by SessionDeductionService::recomputeCounters(). It previously
 * used ClassSession status alone and therefore reported valid legacy/partial
 * deductions as drift. Before that, it also filtered on a non-existent
 * ClassSession.VoidedAt column and crashed when the scheduler invoked it.
 *
 * Assertions read the JSON report the command always writes, because Laravel 8
 * lacks expectsOutputToContain and the first Artisan call under RefreshDatabase
 * can swallow console output.
 */
class NightlyReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_runs_without_missing_column_error(): void
    {
        // The mere existence of ClassSession rows with attended status is enough
        // to exercise the aggregate query that used to reference VoidedAt.
        $courseId = $this->bootstrapCourse(2, 1);
        $this->attendedSession($courseId, Carbon::now()->subDays(1)->toDateString());

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNotNull($this->readReport(), 'Reconcile report should be written');
    }

    public function test_reconcile_flags_used_sessions_mismatch(): void
    {
        // Recorded UsedSessions = 2 but only 1 attended session exists -> mismatch.
        $courseId = $this->bootstrapCourse(4, 2);
        $this->attendedSession($courseId, Carbon::now()->subDays(2)->toDateString());

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame(1, $report['mismatch_count']);
        $this->assertSame($courseId, $report['mismatches'][0]['student_class_id']);
        $this->assertSame(2, $report['mismatches'][0]['recorded_used']);
        $this->assertSame(1, $report['mismatches'][0]['actual_attended']);
    }

    public function test_reconcile_reports_no_mismatch_when_counts_align(): void
    {
        $courseId = $this->bootstrapCourse(2, 2);
        $this->attendedSession($courseId, Carbon::now()->subDays(2)->toDateString());
        $this->attendedSession($courseId, Carbon::now()->subDays(3)->toDateString());

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, $this->readReport()['mismatch_count']);
    }

    public function test_reconcile_accepts_deducted_attendance_without_completed_session_status(): void
    {
        $courseId = $this->bootstrapCourse(4, 1);
        $course = StudentClass::findOrFail($courseId);

        StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID' => $course->StudentID,
            'SignInDT' => now()->subDay(),
            'MDT' => now(),
            'Status' => 'present',
            'SessionDeducted' => true,
        ]);

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, $this->readReport()['mismatch_count']);
    }

    public function test_reconcile_uses_fractional_ledger_rounding_for_partial_makeup(): void
    {
        $courseId = $this->bootstrapCourse(6, 1);

        SessionDeductionLedger::create([
            'student_class_id' => $courseId,
            'event_type' => 'deduct',
            'source' => 'attendance',
            'minutes' => 90,
        ]);

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, $this->readReport()['mismatch_count']);
    }

    // ── Helpers ──

    private function readReport(): ?array
    {
        $path = storage_path('logs/nightly-reconcile-' . now()->format('Ymd') . '.json');
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    private function bootstrapCourse(int $sessionCount, int $usedSessions): int
    {
        $teacher = User::create([
            'LoginName' => 'nr-teacher-' . uniqid(), 'Name' => '老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000', 'MustChangePassword' => false,
        ]);
        $student = Student::create([
            'name' => '對帳測試', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher->id, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-03-01', 'TotalHours' => 40, 'Memo' => 'nr-test',
            'Paid' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => $sessionCount, 'RemainingSessions' => max(0, $sessionCount - $usedSessions),
            'UsedSessions' => $usedSessions,
            'SessionDuration' => 120, 'ClassType' => 'one_on_one',
            'MDate' => now(), 'Rate' => 500,
        ]);
        return (int) $course->ID;
    }

    private function attendedSession(int $courseId, string $date): void
    {
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'attended',
            'Note' => '',
        ]);
    }
}
