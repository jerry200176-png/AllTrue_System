<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentClassController;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #1163: the contract-realign reflow (StudentClassController::remapFutureScheduledSessionsToContract)
 * remaps future scheduled sessions onto a new fixed-weekday cadence. When the
 * change compresses sessions forward, an earlier row's target is a later row's
 * not-yet-vacated slot — which 1062s under uq_class_session_slot if moved in
 * place. The two-phase fix (park to sentinel slots, then place, in a transaction)
 * must complete without a 1062 and land the correct distinct final layout.
 *
 * The method is exercised DIRECTLY (reflection) to isolate the reflow from the
 * course-update endpoint's rebuild/reconcile flow, and runs against the REAL
 * unique index — without it the old in-place code's transient duplicate resolves
 * and the bug is invisible.
 */
class RealignReflowTwoPhaseTest extends TestCase
{
    use RefreshDatabase;

    /** DDL cannot run inside the RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php';
    private const STUDENT_ID = 77901;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00', 'Asia/Taipei'));
        $this->enableUniqueIndex();
    }

    public function test_forward_compression_reflow_does_not_1062_and_lands_distinct_slots(): void
    {
        $courseId = $this->seedCourse();

        // Sat, Sun, Sat, Sun — four future scheduled sessions on a two-weekday contract.
        $sessionsByDate = [];
        foreach (['2026-04-18', '2026-04-19', '2026-04-25', '2026-04-26'] as $date) {
            $sessionsByDate[$date] = ClassSession::create([
                'StudentClassID' => $courseId, 'SessionDate' => $date,
                'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled',
            ]);
        }

        $learningRecord = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $sessionsByDate['2026-04-19']->id,
            'TeacherID' => 99,
            'Subject' => 'Math',
            'Content' => 'reflow side-effect contract',
            'SessionDate' => '2026-04-19',
            'StartTime' => '13:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'pending',
        ]);
        $scheduleId = (int) DB::table('schedules')->insertGetId([
            'student_id' => self::STUDENT_ID,
            'student_course_id' => $courseId,
            'teacher_id' => 99,
            'day_of_week' => 7,
            'schedule_date' => '2026-04-19',
            'start_time' => '13:00',
            'end_time' => '15:00',
            'status' => 'scheduled',
            'type' => 'course',
            'branch_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reflow onto Saturday-only. buildSessionsForCount yields 04-18, 04-25, 05-02, 05-09,
        // so the Sunday rows compress forward onto slots still held by later Saturday rows —
        // the exact in-place collision. Two-phase must handle it.
        $unlocked = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->get();
        $slots = [['weekday' => 6, 'time' => '13:00', 'duration_minutes' => 120]];

        $moved = $this->invokeReflow($unlocked, $slots, 120);

        // Three rows moved (the first Saturday already sits on its contract slot).
        $this->assertSame(3, $moved);

        $final = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')
            ->get();

        $this->assertCount(4, $final);
        $this->assertSame(
            ['2026-04-18', '2026-04-25', '2026-05-02', '2026-05-09'],
            $final->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->all()
        );
        foreach ($final as $s) {
            $this->assertSame(6, (int) Carbon::parse($s->SessionDate)->dayOfWeekIso, 'all on Saturday');
        }
        $slotsSeen = $final->map(fn ($s) => substr((string) $s->SessionDate, 0, 10) . ' ' . substr((string) $s->StartTime, 0, 5));
        $this->assertSame($slotsSeen->count(), $slotsSeen->unique()->count(), 'no duplicate active slots');

        $learningRecord->refresh();
        $this->assertSame('2026-04-25', substr((string) $learningRecord->SessionDate, 0, 10));
        $this->assertSame('13:00', substr((string) $learningRecord->StartTime, 0, 5));
        $schedule = DB::table('schedules')->where('id', $scheduleId)->first();
        $this->assertSame('2026-04-25', substr((string) $schedule->schedule_date, 0, 10));
        $this->assertSame(6, (int) $schedule->day_of_week);
    }

    public function test_reflow_onto_external_locked_occupant_throws_slot_occupied(): void
    {
        $courseId = $this->seedCourse();

        // Two future scheduled Sunday rows to reflow onto Saturdays 04-18, 04-25.
        foreach (['2026-04-19', '2026-04-26'] as $date) {
            ClassSession::create([
                'StudentClassID' => $courseId, 'SessionDate' => $date,
                'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled',
            ]);
        }
        // A LOCKED (attended) session already occupies a reflow target slot (Sat 04-25 13:00),
        // and is NOT part of the unlocked reflow set -> must surface a clean SlotOccupiedException.
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-04-25',
            'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended',
        ]);

        $unlocked = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')->orderBy('id')
            ->get();
        $slots = [['weekday' => 6, 'time' => '13:00', 'duration_minutes' => 120]];

        $this->expectException(\App\Exceptions\SlotOccupiedException::class);
        $this->invokeReflow($unlocked, $slots, 120);
    }

    // ── helpers ──

    private function invokeReflow($unlockedSorted, array $slots, int $durationMinutes): int
    {
        $controller = app(StudentClassController::class);
        $method = new \ReflectionMethod(StudentClassController::class, 'remapFutureScheduledSessionsToContract');
        $method->setAccessible(true);
        return (int) $method->invoke($controller, $unlockedSorted, $slots, $durationMinutes);
    }

    private function seedCourse(): int
    {
        DB::table('Student')->insert([
            'id' => self::STUDENT_ID, 'name' => 'Realign Two-Phase Test',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => self::STUDENT_ID, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-03-01', 'TotalHours' => 20,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 4, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
            'week' => 6, 'time' => '13:00:00', 'week1' => 7, 'time1' => '13:00:00',
        ]);
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->uniqueIndexExists()) {
            DB::statement('ALTER TABLE ClassSession DROP INDEX uq_class_session_slot');
        }
        $cid = DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->value('ID');
        if ($cid) {
            DB::table('LearningRecord')->where('StudentClassID', $cid)->delete();
            DB::table('ClassSession')->where('StudentClassID', $cid)->delete();
            DB::table('schedules')->where('student_course_id', $cid)->delete();
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
}
