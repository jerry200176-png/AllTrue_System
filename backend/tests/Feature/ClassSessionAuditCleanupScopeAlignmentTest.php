<?php

namespace Tests\Feature;

use App\Services\ClassSessionIntraDuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: audit Type-A scope must equal cleanup execution scope.
 */
class ClassSessionAuditCleanupScopeAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_and_cleanup_report_same_active_duplicate_group_count(): void
    {
        $courseId = $this->createCourse(77001, 77001);

        $keeperId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-10',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'attended',
            'Note' => 'keeper',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $activeDupId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-10',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
            'Note' => 'active-dup',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-11',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'attended',
            'Note' => 'placeholder-slot keeper',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-11',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'cancelled',
            'Note' => 'placeholder',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $finder = app(ClassSessionIntraDuplicateFinder::class);
        $auditGroups = $finder->findActiveDuplicateGroups($courseId);
        $this->assertCount(1, $auditGroups);
        $this->assertCount(2, $auditGroups[0]['session_ids']);

        $auditPath = storage_path('app/audit-scope-test-' . $courseId . '.json');
        $exitCode = Artisan::call('classsession:audit-duplicates', [
            '--student_class_id' => $courseId,
            '--output' => $auditPath,
        ]);
        $this->assertSame(0, $exitCode);
        $auditJson = json_decode((string) file_get_contents($auditPath), true);
        $this->assertIsArray($auditJson);
        $this->assertSame(count($auditGroups), $auditJson['summary']['intra_course_duplicate_groups']);
        $this->assertSame(1, $auditJson['summary']['cancelled_placeholder_collision_groups']);

        $expectedDeletions = 0;
        foreach ($auditGroups as $group) {
            $expectedDeletions += count($group['session_ids']) - 1;
        }

        $beforeCount = DB::table('ClassSession')->where('StudentClassID', $courseId)->count();
        $exitCode = Artisan::call('classsession:cleanup-intra-duplicates', ['--student_class_id' => $courseId]);
        $this->assertSame(0, $exitCode);
        $this->assertSame($beforeCount, DB::table('ClassSession')->where('StudentClassID', $courseId)->count());
        $this->assertNotNull(DB::table('ClassSession')->where('id', $activeDupId)->first());

        $cancelledStillExists = DB::table('ClassSession')
            ->where('StudentClassID', $courseId)
            ->where('Status', 'cancelled')
            ->exists();
        $this->assertTrue($cancelledStillExists);

        unset($keeperId);
    }

    public function test_execute_cleanup_deletions_match_audit_group_scope(): void
    {
        $courseId = $this->createCourse(77004, 77004);

        DB::table('ClassSession')->insert([
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-06-10',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'attended',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-06-10',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'Note' => 'dup',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $finder = app(ClassSessionIntraDuplicateFinder::class);
        $auditCount = count($finder->findActiveDuplicateGroups($courseId));
        $beforeRows = DB::table('ClassSession')->where('StudentClassID', $courseId)->count();

        Artisan::call('classsession:cleanup-intra-duplicates', [
            '--execute' => true,
            '--student_class_id' => $courseId,
        ]);

        $afterRows = DB::table('ClassSession')->where('StudentClassID', $courseId)->count();
        $this->assertSame($auditCount, $beforeRows - $afterRows);
        $this->assertSame(0, count($finder->findActiveDuplicateGroups($courseId)));
    }

    public function test_finder_active_groups_match_cleanup_action_count_in_dry_run(): void
    {
        $courseId = $this->createCourse(77002, 77002);

        foreach ([7701, 7702] as $suffix) {
            DB::table('ClassSession')->insert([
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-06-11',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => $suffix === 7701 ? 'attended' : 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $finder = app(ClassSessionIntraDuplicateFinder::class);
        $groups = $finder->findActiveDuplicateGroups($courseId);
        $expectedDeletions = 0;
        foreach ($groups as $group) {
            $expectedDeletions += count($group['session_ids']) - 1;
        }
        $this->assertSame(1, $expectedDeletions);

        $beforeCount = DB::table('ClassSession')->where('StudentClassID', $courseId)->count();
        Artisan::call('classsession:cleanup-intra-duplicates', ['--student_class_id' => $courseId]);
        $this->assertSame($beforeCount, DB::table('ClassSession')->where('StudentClassID', $courseId)->count());
    }

    private function createCourse(int $studentId, int $teacherId): int
    {
        DB::table('Student')->insert([
            'id' => $studentId,
            'name' => 'Scope Test ' . $studentId,
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);

        return (int) DB::table('StudentClass')->insertGetId([
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
        ]);
    }
}
