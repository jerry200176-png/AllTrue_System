<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
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

    public function test_repair_marks_scheduled_record_as_system_adjustment_and_it_can_be_restored(): void
    {
        [, $classId] = $this->studentAndClass();
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $classId,
            'SessionDate' => '2026-08-28',
            'StartTime' => '13:00',
            'EndTime' => '15:00',
            'Status' => 'scheduled',
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
        $result = $service->repair(4);

        $this->assertSame(1, $result['voided']);
        $this->assertSame('由已上調整狀態', DB::table('LearningRecord')->where('id', $lrId)->value('VoidReason'));

        $session = ClassSession::findOrFail($sessionId);
        $session->Status = 'attended';
        $session->save();
        app(LearningRecordBackfillService::class)->ensureRequiredForAttendanceSession($session);

        $this->assertDatabaseHas('LearningRecord', [
            'id' => $lrId,
            'VoidedAt' => null,
            'VoidReason' => null,
            'Status' => 'pending',
        ]);
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
    public function test_duplicate_count_counts_groups_and_preserves_campus_and_void_filters(): void
    {
        [$studentA, $classA] = $this->studentAndClass();
        [, $classB] = $this->studentAndClass();
        $campusA = (int) DB::table('Student')->where('id', $studentA)->value('CampusID');
        $this->assertStringStartsWith('AllTrue_test', DB::connection()->getDatabaseName());
        // Legacy duplicate rows predate the current unique index. Shadow only
        // this nonce-database connection; the real table/index stay intact.
        $definition = array_values((array) DB::selectOne('SHOW CREATE TABLE LearningRecord'))[1];
        $temporaryDefinition = str_replace(
            'CREATE TABLE `LearningRecord`', 'CREATE TEMPORARY TABLE `LearningRecord`', $definition
        );
        $this->assertNotSame($definition, $temporaryDefinition);
        // MariaDB temporary tables cannot carry foreign keys. Keep column
        // definitions/indexes; only the temporary legacy fixture omits FKs.
        $lines = array_values(array_filter(explode("\n", $temporaryDefinition),
            static fn (string $line): bool => !str_starts_with(ltrim($line), 'CONSTRAINT ')
        ));
        $lines[count($lines) - 2] = rtrim($lines[count($lines) - 2], ',');
        DB::statement(implode("\n", $lines));
        try {
            DB::statement('ALTER TABLE LearningRecord DROP INDEX learningrecord_classsessionid_unique');
            foreach ([[$classA, 2], [$classA, 3], [$classB, 4], [$classA, 1]] as $index => [$classId, $activeCount]) {
                $sessionId = DB::table('ClassSession')->insertGetId([
                    'StudentClassID' => $classId, 'SessionDate' => '2026-08-28',
                    'StartTime' => sprintf('%02d:00', 10 + $index),
                    'EndTime' => sprintf('%02d:00', 11 + $index), 'Status' => 'attended',
                ]);
                for ($i = 0; $i < $activeCount + 1; $i++) {
                    DB::table('LearningRecord')->insert([
                        'StudentClassID' => $classId, 'ClassSessionID' => $sessionId,
                        'TeacherID' => 1, 'Content' => '', 'Subject' => '數學',
                        'SessionDate' => '2026-08-28', 'StartTime' => '10:00',
                        'EndTime' => '11:00', 'Status' => 'pending',
                        'VoidedAt' => $i === $activeCount ? now() : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $service = app(AttendanceLearningRecordIntegrityService::class);
            $all = $service->scan(null, 1);
            $this->assertSame(3, $all['counts']['duplicate_active_learning_records']);
            $this->assertCount(1, $all['duplicate_active_learning_records']);
            $campus = $service->scan($campusA, 1);
            $this->assertSame(2, $campus['counts']['duplicate_active_learning_records']);
            $this->assertCount(1, $campus['duplicate_active_learning_records']);
        } finally {
            DB::statement('DROP TEMPORARY TABLE LearningRecord');
        }
        $this->assertSame(0, DB::table('LearningRecord')->count());
        $index = DB::selectOne("SHOW INDEX FROM LearningRecord WHERE Key_name = 'learningrecord_classsessionid_unique'");
        $this->assertSame(0, (int) $index->Non_unique);
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
