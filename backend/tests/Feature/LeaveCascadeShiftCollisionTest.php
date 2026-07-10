<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Services\CourseLeaveCascadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression for the leave-cascade 1062 incident (2026-07-10, production).
 *
 * CourseLeaveCascadeService::shiftAndAppendAfterLeave shifted future sessions
 * forward in ASCENDING date order, moving an earlier session onto the slot of a
 * later session that had not been shifted yet. With the active-only unique index
 * uq_class_session_slot in place, that transient collision surfaces as a raw
 * SQLSTATE[23000] 1062 (500 to the director) instead of the pre-index silent
 * duplicate. The fix shifts latest-first so each target slot is vacated before
 * anything moves onto it.
 *
 * This test applies the real unique index (mirroring ClassSessionD1ActiveOnlyIndexTest)
 * because the bug only manifests as an exception mid-loop while the index is
 * enforced; without the index the intermediate duplicate resolves harmlessly and
 * the final state looks correct, masking the regression.
 */
class LeaveCascadeShiftCollisionTest extends TestCase
{
    use RefreshDatabase;

    /** DDL cannot run inside the RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php';
    private const STUDENT_ID = 77601;
    private const TEACHER_ID = 77602;

    public function test_forward_cascade_onto_consecutive_slots_does_not_collide_under_unique_index(): void
    {
        $this->enableUniqueIndex();
        $courseId = $this->createCourse();

        // Weekly (Friday) course: four consecutive same-weekday scheduled slots.
        // Leaving the first forces the following three to shift forward one week
        // each, i.e. each moves onto the next one's current slot.
        foreach (['2026-07-17', '2026-07-24', '2026-07-31', '2026-08-07'] as $date) {
            $this->insertSession($courseId, $date);
        }

        DB::transaction(function () use ($courseId) {
            CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-17');
        });

        $active = ClassSession::where('StudentClassID', $courseId)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled', 'voided')")
            ->get();

        // No two active sessions share a slot (the DB index guarantees it; assert
        // the final layout explicitly too).
        $slots = $active->map(fn ($s) => substr((string) $s->SessionDate, 0, 10) . ' ' . substr((string) $s->StartTime, 0, 5));
        $this->assertSame($slots->count(), $slots->unique()->count(), 'No duplicate active slots after cascade');

        // Count preserved: 4 originals (one now leave) + 1 appended = 5 active rows.
        $this->assertSame(5, $active->count());
        $this->assertSame(1, $active->filter(fn ($s) => strtolower((string) $s->Status) === 'leave')->count());

        // The leave stayed put; the tail shifted forward and one slot was appended.
        $dates = $active->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->sort()->values()->all();
        $this->assertSame(
            ['2026-07-17', '2026-07-31', '2026-08-07', '2026-08-14', '2026-08-21'],
            $dates
        );
    }

    private function enableUniqueIndex(): void
    {
        if ($this->uniqueIndexExists()) {
            DB::statement('ALTER TABLE ClassSession DROP INDEX uq_class_session_slot');
        }
        DB::table('migrations')
            ->where('migration', '2026_07_09_100000_add_unique_class_session_slot_index')
            ->delete();
        putenv('APPLY_CLASS_SESSION_UNIQUE_INDEX=1');
        $_ENV['APPLY_CLASS_SESSION_UNIQUE_INDEX'] = '1';
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRATION]));
        $this->assertTrue($this->uniqueIndexExists());
    }

    private function insertSession(int $courseId, string $date): void
    {
        DB::table('ClassSession')->insert([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '20:00:00',
            'EndTime' => '22:00:00',
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->uniqueIndexExists()) {
            DB::statement('ALTER TABLE ClassSession DROP INDEX uq_class_session_slot');
        }
        $courseId = DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->value('ID');
        if ($courseId) {
            DB::table('ClassSession')->where('StudentClassID', $courseId)->delete();
        }
        DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->delete();
        DB::table('Student')->where('id', self::STUDENT_ID)->delete();

        parent::tearDown();
    }

    private function uniqueIndexExists(): bool
    {
        if (!Schema::hasTable('ClassSession')) {
            return false;
        }
        return count(DB::select("SHOW INDEX FROM ClassSession WHERE Key_name = 'uq_class_session_slot'")) > 0;
    }

    private function createCourse(): int
    {
        DB::table('Student')->insert([
            'id' => self::STUDENT_ID,
            'name' => 'Leave Cascade Collision Test',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);

        return (int) DB::table('StudentClass')->insertGetId([
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
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ]);
    }
}
