<?php

namespace Tests\Feature;

use App\Exceptions\SlotOccupiedException;
use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Services\ClassSessionMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassSessionMaterializationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_slot_is_idempotent_on_student_class_date_and_start_time(): void
    {
        $courseId = $this->createCourse(1, 1);
        $service = app(ClassSessionMaterializationService::class);

        $slot = [
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-09-10',
            'StartTime' => '16:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
            'Note' => 'materialization-test',
        ];

        $first = $service->upsertSlot($slot);
        $second = $service->upsertSlot(array_merge($slot, [
            'StartTime' => '16:00:00',
            'Note' => 'should-not-insert-duplicate',
        ]));

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['session']->id, $second['session']->id);
        $this->assertSame('materialization-test', $second['session']->Note);

        $this->assertSame(
            1,
            DB::table('ClassSession')
                ->where('StudentClassID', $courseId)
                ->whereDate('SessionDate', '2026-09-10')
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', ['16:00'])
                ->count()
        );
    }

    public function test_upsert_slot_uses_preloaded_settlement_state_without_exists_query(): void
    {
        $courseId = $this->createCourse(1, 1);
        $studentClass = StudentClass::query()->findOrFail($courseId);
        $service = app(ClassSessionMaterializationService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $service->upsertSlot([
                '_student_class' => $studentClass,
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-06-11',
                'StartTime' => '16:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'Note' => 'backfill-from-schedules',
            ]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertTrue($result['created']);
        $settlementQueries = array_filter(
            $queries,
            static fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'select exists')
                && str_contains(strtolower((string) $entry['query']), 'studentclass')
        );

        $this->assertCount(0, $settlementQueries);
    }

    public function test_upsert_slot_rejects_overlapping_session_for_the_same_student(): void
    {
        $firstCourseId = $this->createCourse(1, 1);
        $secondCourseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 2,
            'TeacherID' => 2,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ]);
        $service = app(ClassSessionMaterializationService::class);

        $service->upsertSlot([
            'StudentClassID' => $firstCourseId,
            'SessionDate' => '2026-09-10',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
        ]);

        try {
            $service->upsertSlot([
                'StudentClassID' => $secondCourseId,
                'SessionDate' => '2026-09-10',
                'StartTime' => '17:00',
                'EndTime' => '19:00',
                'Status' => 'scheduled',
            ]);
            $this->fail('Expected overlapping student session to be rejected.');
        } catch (SlotOccupiedException $exception) {
            $this->assertSame('student_slot_conflict', $exception->toResponseArray()['code']);
            $this->assertStringContainsString('學生在此時段已有其他課程', $exception->getMessage());
        }
    }

    public function test_direct_class_session_save_also_rejects_student_overlap(): void
    {
        $firstCourseId = $this->createCourse(1, 1);
        $secondCourseId = $this->createCourse(1, 2, ['SubjectID' => 2]);

        ClassSession::create([
            'StudentClassID' => $firstCourseId,
            'SessionDate' => '2026-09-12',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
        ]);

        $this->expectException(SlotOccupiedException::class);
        $this->expectExceptionMessage('學生在此時段已有其他課程');
        ClassSession::create([
            'StudentClassID' => $secondCourseId,
            'SessionDate' => '2026-09-12',
            'StartTime' => '17:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'scheduled',
        ]);
    }

    public function test_audit_duplicates_command_outputs_json_report(): void
    {
        $courseId = $this->createCourse(1, 1);

        DB::table('ClassSession')->insert([
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-06-10',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'Note' => '',
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-06-10',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'Note' => 'duplicate',
            ],
        ]);

        $exitCode = Artisan::call('classsession:audit-duplicates', [
            '--student_class_id' => $courseId,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $report = json_decode($output, true);
        $this->assertIsArray($report);
        $this->assertArrayHasKey('intra_course_duplicates', $report);
        $this->assertArrayHasKey('cross_course_overlaps', $report);
        $this->assertArrayHasKey('projection_materialized_gaps', $report);
        $this->assertGreaterThanOrEqual(1, count($report['intra_course_duplicates']));
    }

    private function createCourse(int $studentId, int $teacherId, array $overrides = []): int
    {
        if (!DB::table('Student')->where('id', $studentId)->exists()) {
            DB::table('Student')->insert([
                'id' => $studentId,
                'name' => 'Materialize Test',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
            ]);
        }

        return (int) DB::table('StudentClass')->insertGetId(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_two',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 3,
            'UsedSessions' => 5,
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ], $overrides));
    }
}
