<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1078 / in-app #186/#188 — evaluation integrity.
 * learning-records:backfill-missing must create the pending LearningRecord that a teacher
 * fills for a past attended session that lacks one, and must NOT create one for leave or
 * future sessions. Idempotent. Guards the regression where 1,274 attended sessions had no LR.
 */
class LearningRecordBackfillMissingTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_pending_lr_only_for_past_attended_sessions(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '回補測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scId = DB::table('StudentClass')->insertGetId($this->scRow((int) $student->id));

        $attended = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2020-01-01', 'StartTime' => '10:00',
            'EndTime' => '12:00', 'Status' => 'attended',
        ]);
        $leave = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2020-01-02', 'StartTime' => '10:00',
            'EndTime' => '12:00', 'Status' => 'leave',
        ]);
        $future = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2099-01-01', 'StartTime' => '10:00',
            'EndTime' => '12:00', 'Status' => 'scheduled',
        ]);

        $this->assertSame(0, DB::table('LearningRecord')->where('ClassSessionID', $attended)->count());

        Artisan::call('learning-records:backfill-missing', ['--branch_id' => $campus->id]);

        $this->assertSame(
            1,
            DB::table('LearningRecord')->where('ClassSessionID', $attended)->where('Status', 'pending')->count(),
            'past attended session must get exactly one pending LearningRecord'
        );
        $this->assertSame(
            0,
            DB::table('LearningRecord')->where('ClassSessionID', $leave)->count(),
            'leave session must not get a LearningRecord'
        );
        $this->assertSame(
            0,
            DB::table('LearningRecord')->where('ClassSessionID', $future)->count(),
            'future session must not get a LearningRecord'
        );

        // Idempotent: a second run creates nothing more.
        Artisan::call('learning-records:backfill-missing', ['--branch_id' => $campus->id]);
        $this->assertSame(
            1,
            DB::table('LearningRecord')->where('ClassSessionID', $attended)->count(),
            'backfill must be idempotent — no duplicate LearningRecord'
        );
    }

    /** @return array<string,mixed> */
    private function scRow(int $studentId): array
    {
        return [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now(), 'TotalHours' => 8,             'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
        ];
    }
}
