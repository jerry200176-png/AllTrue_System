<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClassSessionD1UniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    /** DDL in test (DROP/ADD INDEX) cannot run inside RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php';

    public function test_d1_cleanup_then_unique_index_blocks_duplicate_slots(): void
    {
        if ($this->uniqueIndexExists()) {
            DB::statement('ALTER TABLE ClassSession DROP INDEX uq_class_session_slot');
        }

        $courseId = $this->createCourse(1, 1);

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
        $duplicateId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-10',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
            'Note' => 'dup',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('classsession:cleanup-intra-duplicates', [
            '--execute' => true,
            '--student_class_id' => $courseId,
        ]));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $keeperId)->value('Status'));
        $this->assertNull(DB::table('ClassSession')->where('id', $duplicateId)->first());

        DB::table('migrations')
            ->where('migration', '2026_07_09_100000_add_unique_class_session_slot_index')
            ->delete();
        putenv('APPLY_CLASS_SESSION_UNIQUE_INDEX=1');
        $_ENV['APPLY_CLASS_SESSION_UNIQUE_INDEX'] = '1';
        Artisan::call('migrate', ['--path' => self::MIGRATION]);
        $this->assertTrue($this->uniqueIndexExists());

        try {
            DB::table('ClassSession')->insert([
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-06-10',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'Note' => 'should-fail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected duplicate slot insert to fail');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('uq_class_session_slot', $e->getMessage());
        }

        if ($this->uniqueIndexExists()) {
            DB::statement('ALTER TABLE ClassSession DROP INDEX uq_class_session_slot');
        }
    }

    private function uniqueIndexExists(): bool
    {
        if (!Schema::hasTable('ClassSession')) {
            return false;
        }
        $indexes = DB::select("SHOW INDEX FROM ClassSession WHERE Key_name = 'uq_class_session_slot'");

        return count($indexes) > 0;
    }

    private function createCourse(int $studentId, int $teacherId): int
    {
        DB::table('Student')->insert([
            'id' => $studentId,
            'name' => 'D1 Test ' . $studentId,
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
