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
use Illuminate\Support\Facades\DB;
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
        $this->assertSame('counter_overstated', $report['mismatches'][0]['category']);
        $this->assertSame(['counter_overstated' => 1], $report['cause_counts']);
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

    public function test_reconcile_classifies_ledger_evidence_ahead_of_counter(): void
    {
        $courseId = $this->bootstrapCourse(6, 0);

        SessionDeductionLedger::create([
            'student_class_id' => $courseId,
            'event_type' => 'deduct',
            'source' => 'attendance',
            'minutes' => null,
        ]);

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame('ledger_ahead', $report['mismatches'][0]['category']);
        $this->assertSame(['ledger_ahead' => 1], $report['cause_counts']);
    }

    public function test_reconcile_flags_positive_usage_artifact_on_cancelled_session(): void
    {
        $courseId = $this->bootstrapCourse(8, 7);
        $sessions = [];
        for ($i = 1; $i <= 6; $i++) {
            $sessions[] = $this->attendedSession($courseId, Carbon::now()->subDays(10 + $i)->toDateString());
        }
        $cancelled = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => Carbon::now()->subDays(20)->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'cancelled',
        ]);
        $sessions[] = $cancelled;

        foreach ($sessions as $session) {
            SessionDeductionLedger::create([
                'student_class_id' => $courseId,
                'class_session_id' => $session->id,
                'event_type' => 'deduct',
                'source' => 'attendance',
            ]);
        }

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame(1, $report['mismatch_count']);
        $this->assertSame('source_conflict', $report['mismatches'][0]['category']);
        $this->assertSame(['source_conflict' => 1], $report['cause_counts']);
    }

    public function test_reconcile_does_not_report_zero_count_difference_for_cancelled_artifact(): void
    {
        $courseId = $this->bootstrapCourse(8, 0);
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => Carbon::now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'cancelled',
        ]);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'Content' => '',
            'Subject' => '數學',
            'SessionDate' => Carbon::now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame(0, $report['mismatch_count']);
        $this->assertSame([], $report['mismatches']);
    }

    public function test_reconcile_classifies_fractional_minute_drift(): void
    {
        $courseId = $this->bootstrapCourse(6, 0);

        SessionDeductionLedger::create([
            'student_class_id' => $courseId,
            'event_type' => 'deduct',
            'source' => 'attendance',
            'minutes' => 90,
        ]);

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame(1, $report['mismatches'][0]['expected_used']);
        $this->assertSame('partial_minutes', $report['mismatches'][0]['category']);
    }

    public function test_reconcile_classifies_attendance_beyond_contract_cap(): void
    {
        $courseId = $this->bootstrapCourse(2, 1);
        $this->attendedSession($courseId, Carbon::now()->subDays(2)->toDateString());
        $this->attendedSession($courseId, Carbon::now()->subDays(3)->toDateString());
        $this->attendedSession($courseId, Carbon::now()->subDays(4)->toDateString());

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame(2, $report['mismatches'][0]['expected_used']);
        $this->assertSame(3, $report['mismatches'][0]['actual_attended']);
        $this->assertSame('contract_cap', $report['mismatches'][0]['category']);
    }

    public function test_live_reconcile_notifies_actual_super_admin_role_without_internal_ids(): void
    {
        User::create([
            'LoginName' => 'nr-super-' . uniqid(), 'Name' => '系統管理員', 'PSW' => 'secret',
            'type' => 'S', 'phone' => '0922000001', 'MustChangePassword' => false,
        ]);
        $courseId = $this->bootstrapCourse(4, 2);
        $this->attendedSession($courseId, Carbon::now()->subDays(2)->toDateString());

        $this->artisan('reconcile:nightly')
            ->assertExitCode(0);

        $notification = DB::table('Notifications')
            ->where('SourceKey', 'nightly_reconcile:' . now()->toDateString())
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('已用堂數高於現有證據 1 筆', $notification->Body);
        $this->assertStringContainsString('不是學費', $notification->Body);
        $this->assertStringNotContainsString('Course #', $notification->Body);
    }

    public function test_dry_run_does_not_replace_the_live_report(): void
    {
        $courseId = $this->bootstrapCourse(4, 2);
        $this->attendedSession($courseId, Carbon::now()->subDays(2)->toDateString());
        @unlink($this->reportPath(false));
        @unlink($this->reportPath(true));

        $this->artisan('reconcile:nightly')->assertExitCode(0);
        $live = (string) file_get_contents($this->reportPath(false));

        $this->artisan('reconcile:nightly', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame($live, (string) file_get_contents($this->reportPath(false)));
        $this->assertSame('live', $this->readReport(false)['mode']);
        $this->assertSame('dry-run', $this->readReport(true)['mode']);
    }

    public function test_partial_minutes_applies_only_to_count_mode_courses(): void
    {
        // A date-mode course never converts minutes to sessions, so a
        // nonstandard-minute ledger row cannot explain its counter gap.
        $courseId = $this->bootstrapCourse(6, 0, 'date');

        SessionDeductionLedger::create([
            'student_class_id' => $courseId,
            'event_type' => 'deduct',
            'source' => 'attendance',
            'minutes' => 90,
        ]);

        $this->artisan('reconcile:nightly', ['--dry-run' => true])
            ->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame($courseId, $report['mismatches'][0]['student_class_id']);
        $this->assertNotSame('partial_minutes', $report['mismatches'][0]['category']);
        $this->assertSame('ledger_ahead', $report['mismatches'][0]['category']);
    }

    public function test_every_mismatch_beyond_the_200_row_summary_is_stored_without_names(): void
    {
        // Counter says 2 used, no evidence exists -> one mismatch per course.
        $ids = [];
        for ($i = 0; $i < 205; $i++) {
            $ids[] = $this->bootstrapCourse(4, 2);
        }
        $rowsPath = storage_path('logs/nightly-reconcile-rows-dryrun-' . now()->format('Ymd') . '.json');
        @unlink($rowsPath);

        $this->artisan('reconcile:nightly', ['--dry-run' => true])->assertExitCode(0);

        $report = $this->readReport();
        $this->assertSame(205, $report['mismatch_count']);
        $this->assertCount(200, $report['mismatches']);

        $raw = (string) file_get_contents($rowsPath);
        $rows = json_decode($raw, true);
        $this->assertSame($report['checked_at'], $rows['checked_at']);
        $this->assertSame(205, $rows['mismatch_count']);
        $stored = array_column($rows['mismatches'], 'student_class_id');
        sort($stored);
        $this->assertSame($ids, $stored);
        // The summary's 200 rows are the head of the full list, in order.
        $this->assertSame($report['mismatches'], array_slice($rows['mismatches'], 0, 200));
        $this->assertStringNotContainsString('對帳測試', $raw);
        $this->assertStringNotContainsString('student_name', $raw);
        @unlink($rowsPath);
    }

    public function test_failed_rows_file_write_fails_the_run(): void
    {
        $this->bootstrapCourse(4, 2);
        $rowsPath = storage_path('logs/nightly-reconcile-rows-dryrun-' . now()->format('Ymd') . '.json');
        @unlink($rowsPath);
        // A directory at the target path makes file_put_contents fail.
        mkdir($rowsPath);

        try {
            $this->artisan('reconcile:nightly', ['--dry-run' => true])->assertExitCode(1);
        } finally {
            @rmdir($rowsPath);
        }

        // The summary report is still written for the panel's fallback.
        $this->assertSame(1, $this->readReport()['mismatch_count']);
    }

    // ── Helpers ──

    /** Most tests run --dry-run, which writes its own preview file (#3945). */
    private function readReport(bool $dryRun = true): ?array
    {
        $path = $this->reportPath($dryRun);
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    private function reportPath(bool $dryRun): string
    {
        return storage_path('logs/' . ($dryRun ? 'nightly-reconcile-dryrun-' : 'nightly-reconcile-') . now()->format('Ymd') . '.json');
    }

    private function bootstrapCourse(int $sessionCount, int $usedSessions, string $scheduleMode = 'count'): int
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
            'Paid' => 0, 'Stop' => 0, 'ScheduleMode' => $scheduleMode,
            'SessionCount' => $sessionCount, 'RemainingSessions' => max(0, $sessionCount - $usedSessions),
            'UsedSessions' => $usedSessions,
            'SessionDuration' => 120, 'ClassType' => 'one_on_one',
            'MDate' => now(), 'Rate' => 500,
        ]);
        return (int) $course->ID;
    }

    private function attendedSession(int $courseId, string $date): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'attended',
            'Note' => '',
        ]);
    }
}
