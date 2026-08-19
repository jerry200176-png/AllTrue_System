<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairTyphoonAttendance1903Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert(['id' => 900190, 'name' => 'Case1903 Fixture A', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1]);
        DB::table('Student')->insert(['id' => 900191, 'name' => 'Case1903 Fixture B', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1]);
        foreach ([[1849, 900190], [2081, 900191]] as [$classId, $studentId]) {
            DB::table('StudentClass')->insert([
                'ID' => $classId, 'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 53, 'TeacherID' => 65, 'by1' => 1,
                'Period' => 4, 'TotalHours' => 0, 'StartDate' => '2026-06-01 00:00:00', 'EndDate' => '2026-08-31 00:00:00',
                'Charge' => 8800, 'Pay' => 0, 'Paid' => 1, 'Rate' => 1100, 'SessionDuration' => 120, 'ScheduleMode' => 'count',
                'SessionCount' => 8, 'UsedSessions' => 4, 'RemainingSessions' => 4, 'Stop' => 0,
            ]);
        }
        foreach ([[22302, 1849], [16728, 2081]] as [$sessionId, $classId]) {
            // Three OTHER legitimate attended sessions per course, so
            // recomputeCounters() (which derives UsedSessions from actual
            // ClassSession/ledger/sign-in state, not the raw stored field)
            // has real signal to land on 4 before / 3 after repair.
            foreach (range(1, 3) as $i) {
                $otherId = $sessionId * 1000 + $i;
                DB::table('ClassSession')->insert([
                    'id' => $otherId, 'StudentClassID' => $classId, 'SessionDate' => "2026-06-0{$i}", 'StartTime' => '10:00:00',
                    'EndTime' => '12:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('session_deduction_ledger')->insert([
                    'student_class_id' => $classId, 'class_session_id' => $otherId, 'event_type' => 'deduct',
                    'source' => 'attendance', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('ClassSession')->insert([
                'id' => $sessionId, 'StudentClassID' => $classId, 'SessionDate' => '2026-07-11', 'StartTime' => '10:00:00',
                'EndTime' => '12:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('session_deduction_ledger')->insert([
                'student_class_id' => $classId, 'class_session_id' => $sessionId, 'event_type' => 'deduct',
                'source' => 'attendance', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $this->assertSame(0, Artisan::call('repair:typhoon-attendance-1903'));
        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertSame('attended', DB::table('ClassSession')->where('id', 22302)->value('Status'));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', 16728)->value('Status'));
    }

    public function test_execute_cancels_both_sessions_and_reverses_deduction(): void
    {
        $snapshot = storage_path('app/repair-snapshots/test-1903.json');
        @unlink($snapshot);
        $this->assertSame(0, Artisan::call('repair:typhoon-attendance-1903', ['--execute' => true, '--snapshot' => $snapshot]));
        $this->assertFileExists($snapshot);

        foreach ([[22302, 1849], [16728, 2081]] as [$sessionId, $classId]) {
            $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $sessionId)->value('Status'));
            $this->assertSame(3, (int) DB::table('StudentClass')->where('ID', $classId)->value('UsedSessions'));
            $this->assertSame(5, (int) DB::table('StudentClass')->where('ID', $classId)->value('RemainingSessions'));
            $this->assertSame(
                1,
                DB::table('session_deduction_ledger')->where('student_class_id', $classId)->where('class_session_id', $sessionId)->where('event_type', 'reverse')->count()
            );
            $this->assertSame(
                1,
                DB::table('session_corrections')->where('session_id', $sessionId)->where('decision_reference', 'in-app #1903')->whereNull('rolled_back_at')->count()
            );
        }
    }

    public function test_second_execute_is_a_noop_not_a_double_reversal(): void
    {
        $this->assertSame(0, Artisan::call('repair:typhoon-attendance-1903', ['--execute' => true]));
        $this->assertSame(0, Artisan::call('repair:typhoon-attendance-1903', ['--execute' => true]));
        $this->assertSame(
            1,
            DB::table('session_deduction_ledger')->where('student_class_id', 1849)->where('class_session_id', 22302)->where('event_type', 'reverse')->count()
        );
    }

    public function test_rollback_restores_attendance_and_deduction(): void
    {
        $this->assertSame(0, Artisan::call('repair:typhoon-attendance-1903', ['--execute' => true]));
        $this->assertSame(0, Artisan::call('repair:typhoon-attendance-1903', ['--rollback' => true, '--execute' => true]));

        foreach ([[22302, 1849], [16728, 2081]] as [$sessionId, $classId]) {
            $this->assertSame('attended', DB::table('ClassSession')->where('id', $sessionId)->value('Status'));
            $this->assertSame(4, (int) DB::table('StudentClass')->where('ID', $classId)->value('UsedSessions'));
            $this->assertSame(4, (int) DB::table('StudentClass')->where('ID', $classId)->value('RemainingSessions'));
        }
    }
}
