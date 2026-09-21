<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairFounderStudent9AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('Student')->insert([
            'id' => 9, 'name' => '翟君和', 'CampusID' => 15, 'ClassID' => 1, 'enable' => 1,
        ]);
        foreach ([2812 => [70, 49], 2819 => [71, 67]] as $id => [$subject, $teacher]) {
            DB::table('StudentClass')->insert([
                'ID' => $id, 'StudentID' => 9, 'GradeID' => 1, 'SubjectID' => $subject,
                'TeacherID' => $teacher, 'by1' => 1, 'Period' => 4, 'TotalHours' => 0,
                'StartDate' => '2026-07-01 00:00:00', 'EndDate' => '2026-08-31 00:00:00',
                'Charge' => $id === 2812 ? 8250 : 8250, 'Pay' => 0, 'Paid' => 0,
                'Rate' => 2750, 'SessionDuration' => 120, 'ScheduleMode' => 'count',
                'SessionCount' => 3, 'UsedSessions' => 1, 'RemainingSessions' => 2, 'Stop' => 0,
            ]);
        }
        DB::table('ClassSession')->insert([
            'id' => 28451, 'StudentClassID' => 2819, 'SessionDate' => '2026-08-03',
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'cancelled',
            'Note' => 'projected-monthly-materialized', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('LearningRecord')->insert([
            'id' => 15134, 'StudentClassID' => 2819, 'ClassSessionID' => 28451,
            'TeacherID' => 67, 'Content' => '', 'Subject' => '生物', 'SessionDate' => '2026-08-03',
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'approved',
            'VoidedAt' => now(), 'VoidedByUserID' => 4, 'VoidReason' => 'attendance-root-fix-2026-08-26',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('StudentSingIn')->insert([
            'id' => 8493, 'StudentClassID' => 2819, 'StudentID' => 9, 'TeacherID' => 67,
            'ClassSessionID' => 28451, 'Status' => 'present', 'SessionDeducted' => 1,
            'SignInDT' => '2026-08-03 10:00:00', 'CampusID' => 15,
            'VoidedAt' => now(), 'VoidedByUserID' => 4, 'VoidReason' => 'attendance-root-fix-2026-08-26',
        ]);
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => 2819, 'class_session_id' => 28451,
            'event_type' => 'reverse', 'source' => 'status_adjust', 'created_by' => 4,
            'note' => 'attendance-root-fix-2026-08-26', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('schedule_audit_logs')->insert([
            'session_id' => 28451, 'action_type' => 'update', 'description' => 'root fix',
            'operator_id' => 4, 'branch_id' => 15,
            'old_data' => json_encode(['Note' => 'projected-monthly-materialized']),
            'new_data' => json_encode(['Note' => 'projected-monthly-materialized attendance-root-fix-2026-08-26']),
            'created_at' => now(),
        ]);
    }

    public function test_execute_restores_biology_and_creates_social_without_touching_financial_tables(): void
    {
        $manifest = dirname(base_path()) . '/docs/incidents/2026-09-21-founder-student9-attendance-repair-manifest.json';
        $snapshot = storage_path('app/repair-snapshots/test-founder-student9-attendance.json');

        $this->assertSame(0, Artisan::call('repair:founder-student9-attendance', [
            '--manifest' => $manifest,
            '--execute' => true,
            '--snapshot' => $snapshot,
        ]));

        $this->assertSame('attended', DB::table('ClassSession')->where('id', 28451)->value('Status'));
        $this->assertNull(DB::table('LearningRecord')->where('id', 15134)->value('VoidedAt'));
        $this->assertNull(DB::table('StudentSingIn')->where('id', 8493)->value('VoidedAt'));
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', 28451)->where('event_type', 'deduct')->count());

        $social = DB::table('ClassSession')->where('StudentClassID', 2812)->whereDate('SessionDate', '2026-07-28')->first();
        $this->assertNotNull($social);
        $this->assertSame('13:00:00', $social->StartTime);
        $this->assertSame('15:00:00', $social->EndTime);
        $this->assertSame('attended', $social->Status);
        $this->assertSame(2750, (int) $social->session_charge);
        $this->assertSame(1, DB::table('StudentSingIn')->where('ClassSessionID', $social->id)->whereNull('VoidedAt')->count());
        $this->assertSame(1, DB::table('LearningRecord')->where('ClassSessionID', $social->id)->whereNull('VoidedAt')->count());

        $this->assertFileExists($snapshot);
        $this->assertSame(0, DB::table('Invoice')->count());
        $this->assertSame(0, DB::table('payment_reports')->count());
        $this->assertSame(0, DB::table('Payment')->count());
    }

    public function test_second_execute_is_idempotent(): void
    {
        $manifest = dirname(base_path()) . '/docs/incidents/2026-09-21-founder-student9-attendance-repair-manifest.json';
        $this->assertSame(0, Artisan::call('repair:founder-student9-attendance', [
            '--manifest' => $manifest, '--execute' => true,
        ]));
        $this->assertSame(0, Artisan::call('repair:founder-student9-attendance', [
            '--manifest' => $manifest, '--execute' => true,
        ]));
        $this->assertSame(1, DB::table('ClassSession')->where('StudentClassID', 2812)->whereDate('SessionDate', '2026-07-28')->count());
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', 28451)->where('event_type', 'deduct')->count());
    }
}
