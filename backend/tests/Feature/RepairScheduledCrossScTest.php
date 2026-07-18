<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * repair:duplicate-sessions --case=scheduled-cross-sc
 * Cancels redundant future/today scheduled rows across StudentClass contracts.
 */
class RepairScheduledCrossScTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_cancels_nothing_and_keeps_of_record(): void
    {
        [$keepId, $dropId] = $this->seedCrossScScheduled();

        $this->assertSame(0, Artisan::call('repair:duplicate-sessions', [
            '--case' => 'scheduled-cross-sc',
        ]));
        $out = Artisan::output();
        $this->assertStringContainsString('WOULD cancel ClassSession id=' . $dropId, $out);
        $this->assertSame('scheduled', DB::table('ClassSession')->where('id', $keepId)->value('Status'));
        $this->assertSame('scheduled', DB::table('ClassSession')->where('id', $dropId)->value('Status'));
    }

    public function test_execute_cancels_ghost_side_and_stops_shell(): void
    {
        [$keepId, $dropId, $ghostSc] = $this->seedCrossScScheduled();

        putenv('ALLOW_PROD_REPAIR=1');
        try {
            $this->assertSame(0, Artisan::call('repair:duplicate-sessions', [
                '--case' => 'scheduled-cross-sc',
                '--execute' => true,
                '--force' => true,
            ]));
        } finally {
            putenv('ALLOW_PROD_REPAIR');
        }

        $this->assertSame('scheduled', DB::table('ClassSession')->where('id', $keepId)->value('Status'));
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $dropId)->value('Status'));
        $this->assertSame(1, (int) DB::table('StudentClass')->where('ID', $ghostSc)->value('Stop'));
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function seedCrossScScheduled(): array
    {
        $studentId = 97001;
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => '陳品承修測', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1,
        ]);
        $keepSc = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 67,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 800, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-06-01', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'UsedSessions' => 4, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
        $ghostSc = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 67,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Rate' => 800, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-07-18', 'SessionCount' => 0, 'SessionDuration' => 60,
            'RemainingSessions' => 0, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        $date = now()->addDay()->toDateString();
        $keepId = (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $keepSc, 'SessionDate' => $date,
            'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => 'scheduled',
            'Note' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dropId = (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $ghostSc, 'SessionDate' => $date,
            'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => 'scheduled',
            'Note' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$keepId, $dropId, $ghostSc];
    }
}
