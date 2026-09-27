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
                'Charge' => 8250, 'Pay' => 0, 'Paid' => 0,
                'Rate' => 2750, 'SessionDuration' => 120, 'ScheduleMode' => 'count',
                'SessionCount' => 3, 'UsedSessions' => 1, 'RemainingSessions' => 2, 'Stop' => 0,
            ]);
        }
        DB::table('StudentClass')->insert([
            'ID' => 316, 'StudentID' => 9, 'GradeID' => 1, 'SubjectID' => 64,
            'TeacherID' => 60, 'by1' => 1, 'Period' => 4, 'TotalHours' => 0,
            'StartDate' => '2026-04-12 00:00:00', 'EndDate' => '2026-09-14 00:00:00',
            'Charge' => 6600, 'Pay' => 0, 'Paid' => 0,
            'Rate' => 2200, 'SessionDuration' => 120, 'ScheduleMode' => 'date',
            'SessionCount' => 0, 'UsedSessions' => 1, 'RemainingSessions' => 0, 'Stop' => 1,
        ]);
        DB::table('User')->insert([
            'id' => 261, 'LoginName' => 'yangmo@example.com', 'Name' => '楊墨',
            'PSW' => 'secret', 'type' => 'T', 'phone' => 900000261,
        ]);
        DB::table('UserCampus')->insert(['UserID' => 261, 'CampusID' => 15, 'Approved' => 1]);
        DB::table('ClassSession')->insert([
            'id' => 28451, 'StudentClassID' => 2819, 'SessionDate' => '2026-08-03',
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'cancelled',
            'Note' => 'projected-monthly-materialized', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ClassSession')->insert([
            'id' => 30367, 'StudentClassID' => 316, 'SubjectID' => 64, 'SessionDate' => '2026-08-12',
            'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended',
            'Note' => '系統加課', 'session_charge' => null, 'created_at' => now(), 'updated_at' => now(),
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

    public function test_execute_repairs_three_attendance_rows_and_pricing_without_touching_financial_tables(): void
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

        $chinese = DB::table('ClassSession')->where('StudentClassID', 316)->whereDate('SessionDate', '2026-08-05')->first();
        $this->assertNotNull($chinese);
        $this->assertSame('13:00:00', $chinese->StartTime);
        $this->assertSame('15:00:00', $chinese->EndTime);
        $this->assertSame('attended', $chinese->Status);
        $this->assertSame(261, (int) DB::table('StudentSingIn')->where('ClassSessionID', $chinese->id)->value('TeacherID'));
        $this->assertSame(261, (int) DB::table('LearningRecord')->where('ClassSessionID', $chinese->id)->value('TeacherID'));
        $this->assertSame(2750, (int) $chinese->session_charge);
        $this->assertSame(1, DB::table('student_class_pricing_amendments')->where('student_class_id', 316)->whereNull('voided_at')->where('rate', 2750)->count());
        $this->assertSame(2750, (int) DB::table('ClassSession')->where('id', 30367)->value('session_charge'));

        $this->assertFileExists($snapshot);
        $this->assertSame(0, DB::table('Invoice')->count());
        $this->assertSame(0, DB::table('payment_reports')->count());
        $this->assertSame(0, DB::table('Payment')->count());
    }
    public function test_second_execute_is_idempotent(): void
    {
        $manifest = dirname(base_path()) . '/docs/incidents/2026-09-21-founder-student9-attendance-repair-manifest.json';
        $result = Artisan::call('repair:founder-student9-attendance', [
            '--manifest' => $manifest, '--execute' => true,
        ]);
        $this->assertSame(0, $result, Artisan::output());
        $result = Artisan::call('repair:founder-student9-attendance', [
            '--manifest' => $manifest, '--execute' => true,
        ]);
        $this->assertSame(0, $result, Artisan::output());
        $this->assertSame(1, DB::table('ClassSession')->where('StudentClassID', 2812)->whereDate('SessionDate', '2026-07-28')->count());
        $this->assertSame(1, DB::table('ClassSession')->where('StudentClassID', 316)->whereDate('SessionDate', '2026-08-05')->count());
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', 28451)->where('event_type', 'deduct')->count());
    }

    public function test_correction_for_completed_social_target_does_not_block_chinese_target(): void
    {
        DB::table('ClassSession')->insert([
            'id' => 39434, 'StudentClassID' => 2812, 'SessionDate' => '2026-07-28',
            'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended',
            'Note' => 'founder-student9-attendance-20260921', 'session_charge' => 2750,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('session_corrections')->insert([
            'session_id' => 39434, 'replaced_by_session_id' => null,
            'correction_reason' => 'founder_attendance_repair',
            'decision_reference' => 'founder-student9-attendance-20260921',
            'decided_at' => now(), 'decided_by_user_id' => 4,
            'decided_by_actor' => 'test', 'previous_status' => 'missing',
            'new_status' => 'attended', 'snapshot_before' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manifest = dirname(base_path()) . '/docs/incidents/2026-09-21-founder-student9-attendance-repair-manifest.json';
        $result = Artisan::call('repair:founder-student9-attendance', [
            '--manifest' => $manifest, '--execute' => true,
        ]);
        $this->assertSame(0, $result, Artisan::output());

        $this->assertSame(1, DB::table('ClassSession')->where('StudentClassID', 316)->whereDate('SessionDate', '2026-08-05')->count());
        $this->assertSame(1, DB::table('student_class_pricing_amendments')->where('student_class_id', 316)->whereNull('voided_at')->count());
    }
}
