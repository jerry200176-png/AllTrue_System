<?php

namespace Tests\Feature;

use App\Exceptions\SlotOccupiedException;
use App\Services\ClassSessionMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #1161: the shared slot-occupancy primitive that every single-row ClassSession
 * move now routes through (ClassSessionMaterializationService::findActiveSlotConflict
 * / assertSlotAvailable). Runs against the REAL active-only unique index so the
 * test proves both the app-layer detector AND the DB backstop agree:
 *   - the detector finds a genuine non-cancelled occupant and ignores cancelled ones
 *   - assertSlotAvailable throws SlotOccupiedException on conflict, is quiet when free
 *   - a raw insert that bypasses the guard is rejected by uq_class_session_slot
 */
class ClassSessionSlotConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    /** DDL cannot run inside the RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php';
    private const STUDENT_ID = 77801;
    private const TEACHER_ID = 77802;

    private ClassSessionMaterializationService $svc;
    private int $courseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ClassSessionMaterializationService::class);
        $this->enableUniqueIndex();
        $this->courseId = $this->createCourse();
    }

    public function test_detects_active_occupant_and_ignores_cancelled(): void
    {
        $this->insertSession('2026-09-05', '13:00:00', 'attended');
        $cancelledId = $this->insertSession('2026-09-05', '15:00:00', 'cancelled');

        // Occupied slot -> returns the occupant.
        $hit = $this->svc->findActiveSlotConflict($this->courseId, '2026-09-05', '13:00');
        $this->assertNotNull($hit);
        $this->assertSame('attended', strtolower((string) $hit->Status));

        // Free slot -> null.
        $this->assertNull($this->svc->findActiveSlotConflict($this->courseId, '2026-09-05', '17:00'));

        // A slot held only by a cancelled row is considered free.
        $this->assertNull($this->svc->findActiveSlotConflict($this->courseId, '2026-09-05', '15:00'));

        // Excluding the occupant itself makes its own slot free (self-move is fine).
        $this->assertNull($this->svc->findActiveSlotConflict($this->courseId, '2026-09-05', '13:00', $hit->id));
        $this->assertIsInt($cancelledId);
    }

    public function test_assert_slot_available_throws_on_conflict_and_is_quiet_when_free(): void
    {
        $occupantId = $this->insertSession('2026-09-12', '13:00:00', 'scheduled');

        try {
            $this->svc->assertSlotAvailable($this->courseId, '2026-09-12', '13:00');
            $this->fail('Expected SlotOccupiedException');
        } catch (SlotOccupiedException $e) {
            $this->assertSame($occupantId, $e->conflictSessionId);
            $this->assertSame('slot_occupied', $e->toResponseArray()['code']);
        }

        // Free slot: no throw.
        $this->svc->assertSlotAvailable($this->courseId, '2026-09-12', '17:00');
        $this->assertTrue(true);
    }

    public function test_unique_index_is_the_backstop_when_guard_is_bypassed(): void
    {
        $this->insertSession('2026-09-19', '13:00:00', 'scheduled');

        // A second non-cancelled row in the same slot (guard bypassed) must be
        // rejected by the DB index — this is why the app-layer guard exists.
        try {
            $this->insertSession('2026-09-19', '13:00:00', 'scheduled');
            $this->fail('Expected duplicate active slot insert to be rejected');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('uq_class_session_slot', $e->getMessage());
        }
    }

    // ── helpers ──

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

    private function insertSession(string $date, string $start, string $status): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $this->courseId,
            'SessionDate' => $date,
            'StartTime' => $start,
            'EndTime' => '18:00:00',
            'Status' => $status,
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
        $cid = DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->value('ID');
        if ($cid) {
            DB::table('ClassSession')->where('StudentClassID', $cid)->delete();
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
            'name' => 'Slot Conflict Service Test',
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
            'StartDate' => '2026-08-01',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ]);
    }
}
