<?php

namespace Tests\Feature;

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
            'SessionDate' => '2026-06-10',
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
                ->whereDate('SessionDate', '2026-06-10')
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', ['16:00'])
                ->count()
        );
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
        DB::table('Student')->insert([
            'id' => $studentId,
            'name' => 'Materialize Test',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);

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
