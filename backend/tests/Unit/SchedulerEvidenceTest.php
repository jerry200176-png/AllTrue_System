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

        $this->assertTrue($summary['healthy']);
        $this->assertSame('verified', $summary['jobs']['reconcile-nightly']['status']);
        $this->assertSame(0, $summary['jobs']['bugs-verify-reproductions']['observed_result']['regressed']);
        $this->assertSame(0, $summary['jobs']['learning-records-backfill-missing']['observed_result']['affected_rows']);
        $this->assertSame(3, $summary['jobs']['sessions-generate-forward']['observed_result']['sessions_created']);
        $this->assertSame(12, $summary['jobs']['ops-business-digest']['observed_result']['revenue_at_risk_sessions']);
        $this->assertSame(44, $summary['jobs']['ops-business-digest']['observed_result']['coverage_next_7d']);
    }

    public function test_summary_rejects_duplicate_or_failed_execution_evidence(): void
    {
        $job = 'rfid-prune-pending';
        file_put_contents(SchedulerEvidence::outputPath($job, $this->date), $this->outputFor($job));
        SchedulerEvidence::recordCompletion($job, 'success', $this->date);
        SchedulerEvidence::recordCompletion($job, 'success', $this->date->addMinute());

        $summary = SchedulerEvidence::summarize($this->date->toDateString());

        $this->assertFalse($summary['healthy']);
        $this->assertSame('duplicate', $summary['jobs'][$job]['status']);
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

    private function outputFor(string $job): string
    {
        $outputs = [
            'teacher-signin-close-orphans' => "Closed 0 orphan TeacherSingIn record(s).\n",
            'reconcile-nightly' => "Checked: 1 courses | Mismatches: 0 | Report: /private/path\n",
            'student-signin-close-orphans' => "Closed 0 orphan StudentSignIn record(s).\n",
            'rfid-prune-pending' => "Deleted 0 pending swipes.\n",
            'learning-records-drift-check' => "Drift counts: {\"null_class_session_id\":0}\n",
            'sessions-audit-stranded' => "{\"as_of\":\"2037-01-02\",\"stranded_courses\":0,\"stranded_sessions\":0}\n",
            'sessions-generate-forward' => "=== EXECUTE sessions:generate-forward (horizon=4w, courses=2) ===\n---\ncourses_planned=1 courses_skipped=1 slots_planned=3 sessions_created=3\n",
            'learning-records-backfill-missing' => "learning-records:backfill-missing — total created: 0.\n",
            'bugs-verify-reproductions' => "{\"regressed\":0,\"conditions\":[{\"key\":\"fixed-condition\",\"count\":0,\"state\":\"FIXED-OK\"}]}\n",
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
