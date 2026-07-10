<?php

namespace Tests\Feature;

use App\Services\ClassSessionIntraDuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Characterization tests for ClassSessionIntraDuplicateFinder (#957).
 *
 * This class is load-bearing: on 2026-07-09 a scope mismatch between "audit"
 * and "cleanup" (21 vs 806 groups) triggered a production stop-the-line before
 * the semantics were unified here. These tests lock the three scopes so that
 * distinction can never silently drift again:
 *   - Type A (findActiveDuplicateGroups): 2+ NON-cancelled rows in a slot.
 *   - Type B (findCancelledPlaceholderCollisions): 2+ total, ≤1 non-cancelled.
 *   - Index-blocking (findUniqueIndexBlockingSlots): active-only (== Type A),
 *     because the production unique index is active-only (ActiveSlotFlag, #1118).
 */
class ClassSessionIntraDuplicateFinderTest extends TestCase
{
    use RefreshDatabase;

    private ClassSessionIntraDuplicateFinder $finder;
    private int $sc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = app(ClassSessionIntraDuplicateFinder::class);
        $this->sc = $this->makeCourse(90100);
    }

    public function test_type_a_counts_only_non_cancelled_duplicates(): void
    {
        // Two active rows in one slot → Type A conflict.
        $this->insertSession('2026-06-10', '16:00:00', 'attended');
        $this->insertSession('2026-06-10', '16:00:00', 'scheduled');
        // A cancelled row in a different slot must not register anywhere.
        $this->insertSession('2026-06-11', '16:00:00', 'cancelled');

        $active = $this->finder->findActiveDuplicateGroups($this->sc);
        $this->assertCount(1, $active);
        $this->assertSame(2, $active[0]['row_count']);
        $this->assertSame('16:00', $active[0]['start_time']);
    }

    public function test_cancelled_placeholder_is_type_b_not_type_a(): void
    {
        // One live + one cancelled in the same slot = placeholder collision, NOT a conflict.
        $this->insertSession('2026-06-12', '14:00:00', 'attended');
        $this->insertSession('2026-06-12', '14:00:00', 'cancelled');

        $this->assertCount(0, $this->finder->findActiveDuplicateGroups($this->sc), 'placeholder must not be Type A');

        $typeB = $this->finder->findCancelledPlaceholderCollisions($this->sc);
        $this->assertCount(1, $typeB);
        $this->assertSame(2, $typeB[0]['total_rows']);
        $this->assertSame(1, $typeB[0]['active_rows']);
    }

    public function test_index_blocking_is_active_only_and_matches_type_a(): void
    {
        // Slot X: 2 active → blocks the active-only index.
        $this->insertSession('2026-06-13', '10:00:00', 'attended');
        $this->insertSession('2026-06-13', '10:00:00', 'late');
        // Slot Y: 1 active + 2 cancelled → does NOT block (index is active-only, #1118).
        $this->insertSession('2026-06-14', '10:00:00', 'attended');
        $this->insertSession('2026-06-14', '10:00:00', 'cancelled');
        $this->insertSession('2026-06-14', '10:00:00', 'cancelled');

        $blocking = $this->finder->findUniqueIndexBlockingSlots($this->sc);
        $active = $this->finder->findActiveDuplicateGroups($this->sc);

        $this->assertCount(1, $blocking, 'only the 2-active slot blocks the active-only index');
        $this->assertSame('10:00', $blocking[0]['start_time']);
        $this->assertSame(
            array_column($active, 'session_date'),
            array_column($blocking, 'session_date'),
            'index-blocking scope must equal Type A (the 2026-07-09 scope-alignment guarantee)'
        );
    }

    public function test_time_grouping_is_by_hh_mm_ignoring_seconds(): void
    {
        // Same HH:MM, different seconds → still one slot (normalizeTimeForStorage parity).
        $this->insertSession('2026-06-15', '18:00:00', 'attended');
        $this->insertSession('2026-06-15', '18:00:30', 'scheduled');

        $active = $this->finder->findActiveDuplicateGroups($this->sc);
        $this->assertCount(1, $active);
        $this->assertSame(2, $active[0]['row_count']);
    }

    private function insertSession(string $date, string $start, string $status): void
    {
        DB::table('ClassSession')->insert([
            'StudentClassID' => $this->sc,
            'SessionDate' => $date,
            'StartTime' => $start,
            'EndTime' => '20:00:00',
            'Status' => $status,
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCourse(int $studentId): int
    {
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'Finder Test ' . $studentId,
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);

        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Rate' => 0, 'ClassType' => 'one_on_two',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 3, 'UsedSessions' => 5, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
    }
}
