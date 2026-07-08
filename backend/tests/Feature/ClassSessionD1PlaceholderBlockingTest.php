<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration fail-fast when cancelled placeholders block unique index (Type B).
 */
class ClassSessionD1PlaceholderBlockingTest extends TestCase
{
    use RefreshDatabase;

    /** DDL cannot run inside RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php';

    public function test_migration_blocks_when_only_placeholder_collisions_remain(): void
    {
        if ($this->uniqueIndexExists()) {
            DB::statement('ALTER TABLE ClassSession DROP INDEX uq_class_session_slot');
        }

        $courseId = $this->createCourse(77003, 77003);

        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-12',
            'StartTime' => '14:00:00',
            'EndTime' => '16:00:00',
            'Status' => 'attended',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-12',
            'StartTime' => '14:00:00',
            'EndTime' => '16:00:00',
            'Status' => 'cancelled',
            'Note' => 'placeholder',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('migrations')
            ->where('migration', '2026_07_09_100000_add_unique_class_session_slot_index')
            ->delete();
        putenv('APPLY_CLASS_SESSION_UNIQUE_INDEX=1');
        $_ENV['APPLY_CLASS_SESSION_UNIQUE_INDEX'] = '1';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('placeholder');

        Artisan::call('migrate', ['--path' => self::MIGRATION]);
    }

    protected function tearDown(): void
    {
        DB::table('ClassSession')->where('StudentClassID', DB::table('StudentClass')->where('StudentID', 77003)->value('ID') ?? 0)->delete();
        DB::table('StudentClass')->where('StudentID', 77003)->delete();
        DB::table('Student')->where('id', 77003)->delete();

        parent::tearDown();
    }

    private function uniqueIndexExists(): bool
    {
        $indexes = DB::select("SHOW INDEX FROM ClassSession WHERE Key_name = 'uq_class_session_slot'");

        return count($indexes) > 0;
    }

    private function createCourse(int $studentId, int $teacherId): int
    {
        DB::table('Student')->insert([
            'id' => $studentId,
            'name' => 'Placeholder Block Test ' . $studentId,
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
