<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1080 — bug-termination gate. The command must EXIT NON-ZERO when a verified-FIXED bug
 * regresses in the data (here: a leave session that carries a live LearningRecord, the #170
 * reproduction condition), and EXIT ZERO when no enforced condition is violated.
 */
class VerifyBugReproductionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_no_enforced_condition_is_violated(): void
    {
        $this->artisan('bugs:verify-reproductions')->assertExitCode(0);
    }

    public function test_fails_when_a_fixed_bug_regresses(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '重現閘測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID' => (int) $student->id, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now(), 'TotalHours' => 8, 'RoomID' => 'R1',
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        // #170 reproduction: a LEAVE session that still carries a live, non-approved LearningRecord.
        $csId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2020-01-01', 'StartTime' => '10:00',
            'EndTime' => '12:00', 'Status' => 'leave',
        ]);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $scId, 'ClassSessionID' => $csId, 'TeacherID' => 1,
            'Content' => '', 'Subject' => '英文', 'SessionDate' => '2020-01-01',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'pending',
        ]);

        $this->artisan('bugs:verify-reproductions')->assertExitCode(1);
    }
}
