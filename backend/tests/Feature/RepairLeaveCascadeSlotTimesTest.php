<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairLeaveCascadeSlotTimesTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENT_ID = 77621;
    private const TEACHER_ID = 77622;

    protected function tearDown(): void
    {
        $courseId = DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->value('ID');
        if ($courseId) {
            DB::table('ClassSession')->where('StudentClassID', $courseId)->delete();
            DB::table('StudentClass')->where('ID', $courseId)->delete();
        }
        DB::table('Student')->where('id', self::STUDENT_ID)->delete();
        parent::tearDown();
    }

    public function test_dry_run_lists_foreign_weekday_clock_drift_and_execute_realigns(): void
    {
        $courseId = $this->createWedSatCourse();
        // Corrupted Sat row carrying Wed 17:00–19:00 (leave-cascade signature)
        $csId = (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-04', // Saturday
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Healthy Sat — must not appear
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-11',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('repair:leave-cascade-slot-times', [
            '--course-id' => $courseId,
            '--dry-run' => true,
        ]);
        $this->assertSame(0, $exit);
        $out = Artisan::output();
        $this->assertStringContainsString("cs={$csId}", $out);
        $this->assertStringContainsString('17:00-19:00 -> 10:00-12:00', $out);

        putenv('ALLOW_PROD_REPAIR=1');
        $_ENV['ALLOW_PROD_REPAIR'] = '1';
        $exit = Artisan::call('repair:leave-cascade-slot-times', [
            '--course-id' => $courseId,
            '--execute' => true,
            '--force' => true,
            '--session-ids' => (string) $csId,
        ]);
        $this->assertSame(0, $exit);

        $row = DB::table('ClassSession')->where('id', $csId)->first();
        $this->assertSame('10:00:00', (string) $row->StartTime);
        $this->assertSame('12:00:00', (string) $row->EndTime);

        // Idempotent second pass
        $exit = Artisan::call('repair:leave-cascade-slot-times', [
            '--course-id' => $courseId,
            '--dry-run' => true,
        ]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('candidates=0', Artisan::output());
    }

    public function test_dry_run_classifies_leave_sibling_as_high_confidence_and_exports_csv(): void
    {
        $courseId = $this->createWedSatCourse();
        $leaveId = (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-01',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'leave',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $satId = (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-04',
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $csv = sys_get_temp_dir() . '/leave-slot-review-' . uniqid() . '.csv';
        $exit = Artisan::call('repair:leave-cascade-slot-times', [
            '--course-id' => $courseId,
            '--dry-run' => true,
            '--export-csv' => $csv,
        ]);
        $this->assertSame(0, $exit);
        $out = Artisan::output();
        $this->assertStringContainsString('high_confidence=', $out);
        $this->assertStringContainsString("cs={$satId}", $out);
        $this->assertStringContainsString('confidence=high_confidence', $out);
        $this->assertFileExists($csv);
        $body = file_get_contents($csv);
        $this->assertStringContainsString('selected,confidence,class_session_id', $body);
        $this->assertStringContainsString((string) $leaveId, $body);
        $this->assertStringContainsString((string) $satId, $body);
        // Director default: every data row starts selected=0 (never pre-checked).
        foreach (array_slice(explode("\n", trim($body)), 1) as $line) {
            if ($line === '') {
                continue;
            }
            $this->assertStringStartsWith('0,', $line, 'selected must default to 0');
        }
        @unlink($csv);
    }

    public function test_execute_without_session_ids_is_rejected(): void
    {
        putenv('ALLOW_PROD_REPAIR=1');
        $_ENV['ALLOW_PROD_REPAIR'] = '1';
        $exit = Artisan::call('repair:leave-cascade-slot-times', [
            '--execute' => true,
            '--force' => true,
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--session-ids', Artisan::output());
    }

    public function test_bundle_exit_gate_fails_when_before_state_drifted(): void
    {
        $courseId = $this->createWedSatCourse();
        $csId = (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-04',
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bundlePath = sys_get_temp_dir() . '/leave-bundle-' . uniqid() . '.json';
        file_put_contents($bundlePath, json_encode([
            'schema_version' => 1,
            'kind' => 'leave_cascade_slot_times_repair_bundle',
            'ok' => true,
            'execution_audit_id' => 'test-audit',
            'campus_id' => 1,
            'approval_count' => 1,
            'approved_session_ids' => [$csId],
            // Wrong before state → fail closed
            'expected_before_state' => [(string) $csId => '10:00-12:00'],
            'expected_after_state' => [(string) $csId => '10:00-12:00'],
            'pre_exec_gates' => ['errors' => []],
        ], JSON_UNESCAPED_UNICODE));

        $exit = Artisan::call('repair:leave-cascade-slot-times', [
            '--course-id' => $courseId,
            '--dry-run' => true,
            '--session-ids' => (string) $csId,
            '--bundle' => $bundlePath,
            '--verify-exit-gate' => true,
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('EXIT_GATE_JSON', Artisan::output());
        $this->assertStringContainsString('before_state_changed', Artisan::output());
        @unlink($bundlePath);
    }

    private function createWedSatCourse(): int
    {
        DB::table('Student')->insert([
            'id' => self::STUDENT_ID,
            'name' => 'Repair Slot Times',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);

        $payload = [
            'StudentID' => self::STUDENT_ID,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => self::TEACHER_ID,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_one',
            'StartDate' => '2026-07-01',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 6,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'week' => 3,
            'time' => '17:00:00',
            'week1' => 6,
            'time1' => '10:00:00',
        ];
        if (Schema::hasColumn('StudentClass', 'duration1')) {
            $payload['duration1'] = 120;
        }

        return (int) DB::table('StudentClass')->insertGetId($payload);
    }
}
