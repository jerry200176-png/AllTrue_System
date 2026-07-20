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

    /**
     * Production digest dq_attended_no_LR / bugs:verify-reproductions count only active LRs
     * (VoidedAt IS NULL). A voided-only row must be restored in place — not skipped — or the
     * nightly backfill can never clear the enforced #1078 integrity metric.
     */
    public function test_backfill_restores_voided_lr_for_past_attended_session(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '作廢回補測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scId = DB::table('StudentClass')->insertGetId($this->scRow((int) $student->id));

        $attended = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2020-02-01', 'StartTime' => '10:00',
            'EndTime' => '12:00', 'Status' => 'attended',
        ]);
        $leave = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2020-02-02', 'StartTime' => '10:00',
            'EndTime' => '12:00', 'Status' => 'leave',
        ]);

        DB::table('LearningRecord')->insert([
            'StudentClassID' => $scId,
            'ClassSessionID' => $attended,
            'TeacherID' => 1,
            'Content' => '舊評量',
            'Subject' => '數學',
            'SessionDate' => '2020-02-01',
            'StartTime' => '10:00',
            'EndTime' => '12:00',
            'Status' => 'pending',
            'VoidedAt' => '2020-02-03 12:00:00',
            'VoidReason' => '一般請假',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $scId,
            'ClassSessionID' => $leave,
            'TeacherID' => 1,
            'Content' => '請假作廢',
            'Subject' => '數學',
            'SessionDate' => '2020-02-02',
            'StartTime' => '10:00',
            'EndTime' => '12:00',
            'Status' => 'pending',
            'VoidedAt' => '2020-02-03 12:00:00',
            'VoidReason' => '一般請假',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            0,
            (int) DB::table('LearningRecord')->where('ClassSessionID', $attended)->whereNull('VoidedAt')->count(),
            'precondition: attended session has no active LearningRecord'
        );

        Artisan::call('learning-records:backfill-missing', ['--branch_id' => $campus->id]);

        $restored = DB::table('LearningRecord')->where('ClassSessionID', $attended)->first();
        $this->assertNotNull($restored);
        $this->assertNull($restored->VoidedAt, 'voided LR on attended session must be restored');
        $this->assertSame('pending', $restored->Status);
        $this->assertSame(
            1,
            (int) DB::table('LearningRecord')->where('ClassSessionID', $attended)->count(),
            'must restore in place — unique(ClassSessionID) forbids a second row'
        );

        $leaveLr = DB::table('LearningRecord')->where('ClassSessionID', $leave)->first();
        $this->assertNotNull($leaveLr->VoidedAt, 'leave session voided LR must stay voided');

        // Idempotent after restore.
        Artisan::call('learning-records:backfill-missing', ['--branch_id' => $campus->id]);
        $this->assertSame(
            1,
            (int) DB::table('LearningRecord')->where('ClassSessionID', $attended)->whereNull('VoidedAt')->count()
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
