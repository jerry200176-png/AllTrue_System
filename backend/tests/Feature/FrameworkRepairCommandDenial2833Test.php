<?php

namespace Tests\Feature;

use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #2833: maintenance authorization stays an immediate, existing process read. */
class FrameworkRepairCommandDenial2833Test extends TestCase
{
    use RefreshDatabase;

    public function test_transferred_ledger_guard_denies_or_reaches_snapshot_gate_without_writes(): void
    {
        $student = \App\Models\Student::create(['name' => 'Framework gate fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = $this->course($student->id);
        $target = $this->course($student->id);
        $session = \App\Models\ClassSession::create(['StudentClassID' => $target->ID, 'SessionDate' => '2026-08-05', 'StartTime' => '19:30:00', 'EndTime' => '21:30:00', 'Status' => 'attended']);
        $ledger = \App\Models\SessionDeductionLedger::create(['student_class_id' => $source->ID, 'class_session_id' => $session->id, 'event_type' => 'deduct', 'source' => 'attendance']);
        $oldEnv = $this->app['env'];
        $saved = [getenv('ALLOW_PROD_REPAIR'), $_ENV['ALLOW_PROD_REPAIR'] ?? null, $_SERVER['ALLOW_PROD_REPAIR'] ?? null];
        $this->app['env'] = 'production';
        try {
            foreach ([[false, '1'], [true, null], [true, '0'], [true, 'true'], [true, '1']] as [$force, $value]) {
                $value === null ? putenv('ALLOW_PROD_REPAIR') : putenv('ALLOW_PROD_REPAIR=' . $value);
                unset($_ENV['ALLOW_PROD_REPAIR'], $_SERVER['ALLOW_PROD_REPAIR']);
                if ($value !== null) $_ENV['ALLOW_PROD_REPAIR'] = $_SERVER['ALLOW_PROD_REPAIR'] = $value;
                DB::enableQueryLog();
                DB::flushQueryLog();
                $options = ['--source-class' => $source->ID, '--target-class' => $target->ID, '--session-ids' => (string) $session->id, '--execute' => true];
                if ($force) $options['--force'] = true;
                $code = Artisan::call('repair:transferred-session-ledger', $options);
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertSame(1, $code);
                $this->assertStringContainsString($force && $value === '1' ? 'A new snapshot path' : 'Production requires --force and ALLOW_PROD_REPAIR=1', Artisan::output());
                $this->assertNotEmpty($queries, 'The real command must pass its valid fixture plan.');
                foreach ($queries as $query) {
                    $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query['query']);
                }
                $this->assertSame((int) $source->ID, (int) $ledger->refresh()->student_class_id);
                $this->assertSame((int) $target->ID, (int) $session->refresh()->StudentClassID);
            }
        } finally {
            DB::disableQueryLog();
            $this->app['env'] = $oldEnv;
            [$process, $environment, $server] = $saved;
            $process === false ? putenv('ALLOW_PROD_REPAIR') : putenv('ALLOW_PROD_REPAIR=' . $process);
            unset($_ENV['ALLOW_PROD_REPAIR'], $_SERVER['ALLOW_PROD_REPAIR']);
            if ($environment !== null) $_ENV['ALLOW_PROD_REPAIR'] = $environment;
            if ($server !== null) $_SERVER['ALLOW_PROD_REPAIR'] = $server;
        }
    }

    public function test_confirmed_assessment_execute_denies_before_any_scan_or_write(): void
    {
        $oldEnv = $this->app['env'];
        $saved = [getenv('ALLOW_PROD_REPAIR'), $_ENV['ALLOW_PROD_REPAIR'] ?? null, $_SERVER['ALLOW_PROD_REPAIR'] ?? null];
        $this->app['env'] = 'production'; // Only a gate fixture; DB remains ephemeral localhost.
        try {
            foreach ([[false, '1'], [true, null], [true, '0'], [true, 'true']] as [$force, $value]) {
                $value === null ? putenv('ALLOW_PROD_REPAIR') : putenv('ALLOW_PROD_REPAIR=' . $value);
                unset($_ENV['ALLOW_PROD_REPAIR'], $_SERVER['ALLOW_PROD_REPAIR']);
                if ($value !== null) $_ENV['ALLOW_PROD_REPAIR'] = $_SERVER['ALLOW_PROD_REPAIR'] = $value;
                DB::enableQueryLog();
                DB::flushQueryLog();
                $options = ['--execute' => true];
                if ($force) $options['--force'] = true;
                $code = Artisan::call('repair:confirmed-attendance-assessment', $options);
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertSame(1, $code);
                $this->assertStringContainsString('Production requires --force and ALLOW_PROD_REPAIR=1', Artisan::output());
                $this->assertSame([], $queries, 'Denied command must not even scan the isolated business tables.');
            }
        } finally {
            DB::disableQueryLog();
            $this->app['env'] = $oldEnv;
            [$process, $environment, $server] = $saved;
            $process === false ? putenv('ALLOW_PROD_REPAIR') : putenv('ALLOW_PROD_REPAIR=' . $process);
            unset($_ENV['ALLOW_PROD_REPAIR'], $_SERVER['ALLOW_PROD_REPAIR']);
            if ($environment !== null) $_ENV['ALLOW_PROD_REPAIR'] = $environment;
            if ($server !== null) $_SERVER['ALLOW_PROD_REPAIR'] = $server;
        }
    }
    private function course(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-20',
            'TotalHours' => 16,
            'Charge' => 12000,
            'Paid' => 0,
            'Rate' => 1500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_two',
        ], $overrides));
    }
}
