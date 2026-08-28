<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairUnattendedSession29212Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('Student')->insert([
            'id' => 9, 'name' => '翟君和', 'CampusID' => 15, 'ClassID' => 1, 'enable' => 1,
        ]);
        DB::table('StudentClass')->insert([
            'ID' => 3112, 'StudentID' => 9, 'GradeID' => 1, 'SubjectID' => 70, 'TeacherID' => 49,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0,
            'StartDate' => '2026-08-01 00:00:00', 'EndDate' => '2026-08-28 00:00:00',
            'Charge' => 11000, 'Pay' => 0, 'Paid' => 1, 'Rate' => 2750,
            'SessionDuration' => 120, 'ScheduleMode' => 'date', 'SessionCount' => 4,
            'UsedSessions' => 1, 'RemainingSessions' => 3, 'Stop' => 0,
        ]);
        DB::table('ClassSession')->insert([
            'id' => 29212, 'StudentClassID' => 3112, 'SessionDate' => '2026-08-28',
            'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended',
            'Note' => 'leave; revert-to-scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('LearningRecord')->insert([
            'id' => 17922, 'StudentID' => 9, 'StudentClassID' => 3112, 'ClassSessionID' => 29212,
            'TeacherID' => 49, 'Subject' => '社會', 'SessionDate' => '2026-08-28',
            'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('StudentSingIn')->insert([
            'id' => 11003, 'StudentClassID' => 3112, 'StudentID' => 9, 'TeacherID' => 49,
            'ClassSessionID' => 29212, 'Status' => 'present', 'SessionDeducted' => 1,
            'SignInDT' => '2026-08-28 13:00:00', 'CampusID' => 15, 'PersonType' => 'student',
        ]);
        DB::table('session_deduction_ledger')->insert([
            'id' => 13675, 'student_class_id' => 3112, 'class_session_id' => 29212,
            'event_type' => 'deduct', 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dry_run_does_not_change_attendance_evaluation_or_ledger(): void
    {
        $this->assertSame(0, Artisan::call('repair:unattended-session-29212'));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', 29212)->value('Status'));
        $this->assertNull(DB::table('LearningRecord')->where('id', 17922)->value('VoidedAt'));
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', 29212)->where('event_type', 'deduct')->count());
    }

    public function test_execute_voids_live_artifacts_reverses_deduction_and_recomputes(): void
    {
        $snapshot = storage_path('app/repair-snapshots/test-unattended-29212.json');
        $this->assertSame(0, Artisan::call('repair:unattended-session-29212', [
            '--execute' => true, '--snapshot' => $snapshot,
        ]));

        $this->assertFileExists($snapshot);
        $this->assertSame('scheduled', DB::table('ClassSession')->where('id', 29212)->value('Status'));
        $this->assertNotNull(DB::table('LearningRecord')->where('id', 17922)->value('VoidedAt'));
        $this->assertSame('由已上調整狀態', DB::table('LearningRecord')->where('id', 17922)->value('VoidReason'));
        $this->assertNotNull(DB::table('StudentSingIn')->where('id', 11003)->value('VoidedAt'));
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', 29212)->where('event_type', 'reverse')->count());
        $this->assertSame(0, (int) DB::table('StudentClass')->where('ID', 3112)->value('UsedSessions'));
        $this->assertSame(4, (int) DB::table('StudentClass')->where('ID', 3112)->value('RemainingSessions'));
        $this->assertSame(1, DB::table('schedule_audit_logs')->where('session_id', 29212)->count());
    }

    public function test_execute_is_idempotent_without_a_second_reverse_or_audit(): void
    {
        $this->assertSame(0, Artisan::call('repair:unattended-session-29212', ['--execute' => true]));
        $this->assertSame(0, Artisan::call('repair:unattended-session-29212', ['--execute' => true]));
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', 29212)->where('event_type', 'reverse')->count());
        $this->assertSame(1, DB::table('schedule_audit_logs')->where('session_id', 29212)->count());
    }
}
