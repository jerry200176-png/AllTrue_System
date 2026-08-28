<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Services\AttendanceLearningRecordIntegrityService;
use App\Services\LearningRecordBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceLearningRecordIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_and_repair_creates_missing_record_for_attended_session(): void
    {
        [$studentId, $classId] = $this->studentAndClass();
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $classId,
            'SessionDate' => '2026-08-28',
            'StartTime' => '13:00',
            'EndTime' => '15:00',
            'Status' => 'attended',
        ]);

        $service = app(AttendanceLearningRecordIntegrityService::class);
        $before = $service->scan();
        $this->assertSame(1, $before['counts']['missing_learning_records']);

        $result = $service->repair(4);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['after']['counts']['missing_learning_records']);
        $this->assertSame(1, DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->count());
        $this->assertSame($studentId, (int) DB::table('StudentClass')->where('ID', $classId)->value('StudentID'));
    }

    public function test_repair_voids_record_on_non_attendance_session_and_is_idempotent(): void
    {
        [, $classId] = $this->studentAndClass();
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $classId,
            'SessionDate' => '2026-08-28',
            'StartTime' => '13:00',
            'EndTime' => '15:00',
            'Status' => 'leave',
        ]);
        $lrId = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $classId,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'Content' => '',
            'Subject' => '數學',
            'SessionDate' => '2026-08-28',
            'StartTime' => '13:00',
            'EndTime' => '15:00',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(AttendanceLearningRecordIntegrityService::class);
        $first = $service->repair(4);
        $firstVoidedAt = DB::table('LearningRecord')->where('id', $lrId)->value('VoidedAt');
        $second = $service->repair(4);

        $this->assertSame(1, $first['voided']);
        $this->assertNotNull($firstVoidedAt);
        $this->assertSame(0, $second['voided']);
        $this->assertSame($firstVoidedAt, DB::table('LearningRecord')->where('id', $lrId)->value('VoidedAt'));
    }

    public function test_strict_attendance_ensure_fails_if_no_active_record_can_be_restored(): void
    {
        [, $classId] = $this->studentAndClass();
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $classId,
            'SessionDate' => '2026-08-28',
            'StartTime' => '13:00',
            'EndTime' => '15:00',
            'Status' => 'attended',
        ]);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $classId,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'Content' => '',
            'Subject' => '數學',
            'SessionDate' => '2026-08-28',
            'StartTime' => '13:00',
            'EndTime' => '15:00',
            'Status' => 'pending',
            'VoidedAt' => now(),
            'VoidReason' => '人工決策作廢',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attendance_learning_record_integrity_failed');
        app(LearningRecordBackfillService::class)->ensureRequiredForAttendanceSession(
            \App\Models\ClassSession::findOrFail($sessionId)
        );
    }

    /** @return array{0:int,1:int} */
    private function studentAndClass(): array
    {
        $campusId = Campus::factory()->create()->id;
        $studentId = DB::table('Student')->insertGetId([
            'name' => '完整性測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $classId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 66,
            'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 8, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'UsedSessions' => 0, 'RemainingSessions' => 8,
        ]);

        return [$studentId, $classId];
    }
}
