<?php

namespace Tests\Feature;

use App\Models\StudentSignIn;
use App\Support\SchedulerEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerEvidenceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2037-01-02';

    protected function tearDown(): void
    {
        $date = CarbonImmutable::parse(self::DATE, SchedulerEvidence::TIMEZONE);
        @unlink(SchedulerEvidence::ledgerPath($date));
        @unlink(SchedulerEvidence::outputPath('student-signin-close-orphans', $date));

        parent::tearDown();
    }

    public function test_summary_classifies_backdated_orphan_created_after_nightly_close(): void
    {
        $nightlyExecution = CarbonImmutable::parse(self::DATE . ' 02:30:00', SchedulerEvidence::TIMEZONE);

        // The business event is historical, but MDT proves this row was written
        // after the verified nightly command completed.
        $this->makeOpenOrphan(
            CarbonImmutable::parse(self::DATE, SchedulerEvidence::TIMEZONE)
                ->subMinute()
                ->toDateTimeString(),
            $nightlyExecution->addMinute()->toDateTimeString()
        );

        $checks = $this->runSummary($nightlyExecution);

        $this->assertSame(1, $checks['student_orphans_remaining']);
        $this->assertSame(0, $checks['student_orphans_mdt_at_or_before_nightly']);
        $this->assertSame(1, $checks['student_orphans_mdt_after_nightly']);
        $this->assertSame(0, $checks['student_orphans_unclassified']);
    }

    public function test_summary_classifies_orphan_present_before_nightly_close(): void
    {
        $nightlyExecution = CarbonImmutable::parse(self::DATE . ' 02:30:00', SchedulerEvidence::TIMEZONE);

        $this->makeOpenOrphan(
            CarbonImmutable::parse(self::DATE, SchedulerEvidence::TIMEZONE)
                ->subMinute()
                ->toDateTimeString(),
            $nightlyExecution->subMinute()->toDateTimeString()
        );

        $checks = $this->runSummary($nightlyExecution);

        $this->assertSame(1, $checks['student_orphans_remaining']);
        $this->assertSame(1, $checks['student_orphans_mdt_at_or_before_nightly']);
        $this->assertSame(0, $checks['student_orphans_mdt_after_nightly']);
        $this->assertSame(0, $checks['student_orphans_unclassified']);
    }

    private function runSummary(CarbonImmutable $nightlyExecution): array
    {
        SchedulerEvidence::ensureDirectories();
        file_put_contents(
            SchedulerEvidence::outputPath('student-signin-close-orphans', $nightlyExecution),
            "Closed 0 orphan StudentSignIn record(s).\n"
        );
        SchedulerEvidence::recordCompletion(
            'student-signin-close-orphans',
            'success',
            $nightlyExecution
        );

        $summary = SchedulerEvidence::summarize(self::DATE);
        $command = app(\App\Console\Commands\SchedulerEvidenceSummary::class);
        $reflection = new \ReflectionMethod($command, 'databaseChecks');
        $reflection->setAccessible(true);

        return $reflection->invoke($command, self::DATE, $summary);
    }

    private function makeOpenOrphan(string $signInAt, string $mdt): void
    {
        StudentSignIn::create([
            'StudentID' => 1,
            'SignInDT' => $signInAt,
            'SignOutDT' => null,
            'MDT' => $mdt,
            'Status' => 'present',
        ]);
    }
}
