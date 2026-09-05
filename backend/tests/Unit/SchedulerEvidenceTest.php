<?php

namespace Tests\Unit;

use App\Support\SchedulerEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerEvidenceTest extends TestCase
{
    private CarbonImmutable $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = CarbonImmutable::parse('2037-01-02 04:30:00', SchedulerEvidence::TIMEZONE);
        $this->cleanArtifacts();
        SchedulerEvidence::ensureDirectories();
    }

    protected function tearDown(): void
    {
        $this->cleanArtifacts();
        parent::tearDown();
    }

    public function test_summary_requires_one_successful_execution_and_parseable_output_for_every_job(): void
    {
        foreach (SchedulerEvidence::jobs() as $job => $definition) {
            file_put_contents(SchedulerEvidence::outputPath($job, $this->date), $this->outputFor($job));
            SchedulerEvidence::recordCompletion($job, 'success', $this->date);
        }

        $summary = SchedulerEvidence::summarize($this->date->toDateString());

        $this->assertTrue($summary['execution_healthy']);
        $this->assertTrue($summary['healthy']);
        $this->assertSame('succeeded', $summary['jobs']['reconcile-nightly']['status']);
        $this->assertSame(
            ['attendance_ahead' => 2, 'ledger_ahead' => 1],
            $summary['jobs']['reconcile-nightly']['observed_result']['cause_counts']
        );
        $this->assertSame(0, $summary['jobs']['bugs-verify-reproductions']['observed_result']['regressed']);
        $this->assertSame('succeeded_with_zero_work', $summary['jobs']['learning-records-backfill-missing']['status']);
        $this->assertSame(0, $summary['jobs']['learning-records-backfill-missing']['observed_result']['affected_rows']);
        $this->assertSame(3, $summary['jobs']['sessions-generate-forward']['observed_result']['sessions_created']);
        $this->assertSame(12, $summary['jobs']['ops-business-digest']['observed_result']['revenue_at_risk_sessions']);
        $this->assertSame(44, $summary['jobs']['ops-business-digest']['observed_result']['coverage_next_7d']);
        $this->assertSame('succeeded_with_zero_work', $summary['jobs']['teacher-signin-close-orphans']['status']);
        $this->assertNotSame(
            $summary['jobs']['teacher-signin-close-orphans']['status'],
            'no_run',
            'zero-work must not be conflated with no-run'
        );
    }

    public function test_summary_rejects_duplicate_or_failed_execution_evidence(): void
    {
        $job = 'rfid-prune-pending';
        file_put_contents(SchedulerEvidence::outputPath($job, $this->date), $this->outputFor($job));
        SchedulerEvidence::recordCompletion($job, 'success', $this->date);
        SchedulerEvidence::recordCompletion($job, 'success', $this->date->addMinute());

        $summary = SchedulerEvidence::summarize($this->date->toDateString());

        $this->assertFalse($summary['healthy']);
        $this->assertSame('partial', $summary['jobs'][$job]['status']);
    }

    public function test_missing_job_is_no_run_not_zero_work(): void
    {
        // Only seed one job — others past due must be no_run
        $job = 'rfid-prune-pending';
        file_put_contents(SchedulerEvidence::outputPath($job, $this->date), $this->outputFor($job));
        SchedulerEvidence::recordCompletion($job, 'success', $this->date);

        $summary = SchedulerEvidence::summarize($this->date->toDateString(), $this->date);

        $this->assertFalse($summary['execution_healthy']);
        $this->assertSame('no_run', $summary['jobs']['reconcile-nightly']['status']);
        $this->assertNotSame('succeeded_with_zero_work', $summary['jobs']['reconcile-nightly']['status']);
    }

    public function test_summary_exposes_per_job_evidence_age_and_observation_time(): void
    {
        $job = 'rfid-prune-pending';
        $observedAt = $this->date->addMinutes(30);
        file_put_contents(SchedulerEvidence::outputPath($job, $this->date), $this->outputFor($job));
        SchedulerEvidence::recordCompletion($job, 'success', $this->date);

        $summary = SchedulerEvidence::summarize($this->date->toDateString(), $observedAt);

        $this->assertSame($observedAt->toIso8601String(), $summary['observed_at']);
        $this->assertSame($this->date->toIso8601String(), $summary['jobs'][$job]['evidence_timestamp']);
        $this->assertSame(1800, $summary['jobs'][$job]['evidence_age_seconds']);
        $this->assertNull($summary['jobs']['reconcile-nightly']['evidence_timestamp']);
        $this->assertNull($summary['jobs']['reconcile-nightly']['evidence_age_seconds']);
    }

    public function test_every_observed_job_is_registered_once_with_private_output_and_taipei_timezone(): void
    {
        $events = app(Schedule::class)->events();

        foreach (SchedulerEvidence::jobs() as $job => $definition) {
            $matching = array_values(array_filter($events, static function ($event) use ($definition): bool {
                return str_contains($event->command, $definition['command']);
            }));

            $this->assertCount(1, $matching, "{$job} must be scheduled exactly once");
            $this->assertSame(SchedulerEvidence::TIMEZONE, $matching[0]->timezone);
            $this->assertStringContainsString('scheduler-output', $matching[0]->output);
        }
    }

    public function test_pop_executor_is_a_local_minute_schedule_with_private_output(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains($event->command, 'pop:execute-approved')
        ));

        $this->assertCount(1, $events);
        $this->assertSame(SchedulerEvidence::TIMEZONE, $events[0]->timezone);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertStringContainsString('pop-execute-approved.log', $events[0]->output);
    }

    private function outputFor(string $job): string
    {
        $outputs = [
            'teacher-signin-close-orphans' => "Closed 0 orphan TeacherSingIn record(s).\n",
            'reconcile-nightly' => "Checked: 3 courses | Mismatches: 3 | Report: /private/path\nCauses: {\"attendance_ahead\":2,\"ledger_ahead\":1}\n",
            'student-signin-close-orphans' => "Closed 0 orphan StudentSignIn record(s).\n",
            'rfid-prune-pending' => "Deleted 0 pending swipes.\n",
            'learning-records-drift-check' => "Drift counts: {\"null_class_session_id\":0}\n",
            'sessions-audit-stranded' => "{\"as_of\":\"2037-01-02\",\"stranded_courses\":0,\"stranded_sessions\":0}\n",
            'sessions-generate-forward' => "=== EXECUTE sessions:generate-forward (horizon=4w, courses=2) ===\n---\ncourses_planned=1 courses_skipped=1 slots_planned=3 sessions_created=3\n",
            'learning-records-backfill-missing' => "learning-records:backfill-missing — total created: 0.\n",
            'learning-records-void-stale-leave' => "learning-records:void-stale-leave — total voided: 0.\n",
            'bugs-verify-reproductions' => "{\"regressed\":0,\"conditions\":[{\"key\":\"fixed-condition\",\"count\":0,\"state\":\"FIXED-OK\"}]}\n",
            'bindings-cleanup-orphans' => "orphan_bindings_deleted=0 anomaly_students=0\n",
            'ops-business-digest' => <<<'OUTPUT'
                === AllTrue Business Digest — 2037-01-02 04:10:00 ===
                +----------------------------+-------+-----------------------------------------+
                | Signal                     | Value | Meaning                                 |
                +----------------------------+-------+-----------------------------------------+
                | revenue_at_risk_sessions   | 12    | prepaid sessions owed                   |
                | revenue_at_risk_amount     | 24000 | estimated NT$ owed                      |
                | unpaid_active_courses      | 2     | active courses flagged unpaid           |
                | retention_risk_students    | 5     | active students with no upcoming class  |
                | dq_attended_no_LR          | 0     | attended sessions with no record        |
                | dq_cross_sc_dup            | 1     | cross-contract duplicates               |
                | dq_remaining_divergent     | 7     | remaining session divergence            |
                | coverage_next_7d           | 44    | materialized sessions                   |
                +----------------------------+-------+-----------------------------------------+
                OUTPUT,
        ];

        return $outputs[$job];
    }

    private function cleanArtifacts(): void
    {
        $date = $this->date ?? CarbonImmutable::parse('2037-01-02', SchedulerEvidence::TIMEZONE);
        @unlink(SchedulerEvidence::ledgerPath($date));
        foreach (array_keys(SchedulerEvidence::jobs()) as $job) {
            @unlink(SchedulerEvidence::outputPath($job, $date));
        }
    }
}
