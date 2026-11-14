<?php

namespace Tests\Feature;

use App\Services\Scheduling\ContractSessionSchedule;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WithClassSessionIndexFlag;
use Tests\TestCase;

/**
 * Regression: Sentry PHP-LARAVEL-25 / #1384
 *
 * syncFutureScheduledSessionTimes used to update StartTime in place. Under
 * uq_class_session_slot, shifting an earlier row onto a later sibling's
 * not-yet-vacated time 1062s. Two-phase park/place must complete cleanly.
 */
class SyncFutureSessionTimesTwoPhaseTest extends TestCase
{
    use RefreshDatabase, WithClassSessionIndexFlag;

    /** DDL cannot run inside the RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php';
    private const STUDENT_ID = 77902;

    private static int $studentSerial = 0;

    /** @var list<int> Students this test inserted outside any transaction (connectionsToTransact is empty), so it must delete them itself. */
    private array $ownedStudentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00', 'Asia/Taipei'));
        $this->enableUniqueIndex();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->removeOwnedRows();
        parent::tearDown();
    }

    /**
     * Nightly random-order run (#5154): without a wrapping transaction these rows outlive the test and
     * count as unpaid, week/time-committed courses in every later test (PrepaidHorizonPhase0ReportTest,
     * OutboundRemindersResolverTest). Delete exactly what this test inserted.
     */
    private function removeOwnedRows(): void
    {
        $courseIds = DB::table('StudentClass')->whereIn('StudentID', $this->ownedStudentIds)->pluck('ID')->all();
        DB::table('LearningRecord')->whereIn('StudentClassID', $courseIds)->delete();
        DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->delete();
        DB::table('StudentClass')->whereIn('ID', $courseIds)->delete();
        DB::table('Student')->whereIn('id', $this->ownedStudentIds)->delete();
        $this->ownedStudentIds = [];
    }

    public function test_cleanup_removes_every_row_the_test_committed(): void
    {
        $courseId = $this->seedCourse();
        $session = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-07-24',
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $courseId, 'ClassSessionID' => $session->id, 'TeacherID' => 99, 'Subject' => 'Math',
            'Content' => 'leak probe', 'SessionDate' => '2026-07-24', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'pending',
        ]);
        $this->assertSame(1, DB::table('StudentClass')->where('ID', $courseId)->count());

        $this->removeOwnedRows();

        $this->assertSame(0, DB::table('StudentClass')->where('ID', $courseId)->count());
        $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $courseId)->count());
        $this->assertSame(0, DB::table('LearningRecord')->where('StudentClassID', $courseId)->count());
        $this->assertSame(0, DB::table('Student')->where('name', 'Sync1384')->count());
    }

    public function test_same_day_time_shift_does_not_1062(): void
    {
        $courseId = $this->seedCourse();

        // Two future sessions same Friday: 16:00 and 18:00.
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-07-24',
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled',
        ]);
        $later = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-07-24',
            'StartTime' => '18:00:00', 'EndTime' => '20:00:00', 'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $later->id,
            'TeacherID' => 99,
            'Subject' => 'Math',
            'Content' => 'sync side-effect',
            'SessionDate' => '2026-07-24',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'pending',
        ]);

        // Contract now: single Friday slot at 18:00. Naive in-place update of the
        // 16:00 row onto 18:00 collides with the still-present sibling → 1062.
        // Soft sync must skip the colliding move (no 500) and keep one live 18:00.
        $slots = [['weekday' => 5, 'time' => '18:00', 'duration_minutes' => 120]];
        $updated = $this->invokeSync($courseId, $slots, 120);

        $this->assertSame(0, $updated, 'occupied target must be skipped, not force-written');

        $starts = ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', '2026-07-24')
            ->where('Status', 'scheduled')
            ->orderBy('StartTime')
            ->pluck('StartTime')
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->all();
        $this->assertSame(['16:00', '18:00'], $starts);
        $this->assertSame(
            1,
            ClassSession::where('StudentClassID', $courseId)
                ->where('SessionDate', '2026-07-24')
                ->where('StartTime', '18:00:00')
                ->where('Status', 'scheduled')
                ->count()
        );
    }

    public function test_same_day_shift_forward_uses_two_phase(): void
    {
        $courseId = $this->seedCourse();

        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-07-24',
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-07-24',
            'StartTime' => '18:00:00', 'EndTime' => '20:00:00', 'Status' => 'scheduled',
        ]);

        // Shift both forward: 16→18 and 18→20. In-place update of the first
        // row onto 18:00 1062s while the sibling still sits there.
        $slots = [
            ['weekday' => 5, 'time' => '18:00', 'duration_minutes' => 120],
            ['weekday' => 5, 'time' => '20:00', 'duration_minutes' => 120],
        ];
        $updated = $this->invokeSync($courseId, $slots, 120);
        $this->assertSame(2, $updated);

        $starts = ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', '2026-07-24')
            ->where('Status', 'scheduled')
            ->orderBy('StartTime')
            ->pluck('StartTime')
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->all();
        $this->assertSame(['18:00', '20:00'], $starts);
    }

    private function invokeSync(int $courseId, array $slots, int $durationMinutes): int
    {
        $controller = app(ContractSessionSchedule::class);
        $method = new \ReflectionMethod($controller, 'syncFutureScheduledSessionTimes');
        $method->setAccessible(true);
        return (int) $method->invoke($controller, $courseId, $slots, $durationMinutes);
    }

    private function seedCourse(): int
    {
        $studentId = self::STUDENT_ID + self::$studentSerial++;
        if (!DB::table('Student')->where('id', $studentId)->exists()) {
            $this->ownedStudentIds[] = $studentId;
            DB::table('Student')->insert([
                'id' => $studentId,
                'name' => 'Sync1384',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);
        }

        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-01',
            'TotalHours' => 8,
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Charge' => 800,
            'Pay' => 3200,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
            'week' => 5,
            'time' => '16:00:00',
        ]);
    }

    private function enableUniqueIndex(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            $this->markTestSkipped('ClassSession missing');
        }
        $this->enableClassSessionIndexFlag();
        if (count(DB::select("SHOW INDEX FROM ClassSession WHERE Key_name = 'uq_class_session_slot'")) === 0) {
            Artisan::call('migrate', [
                '--path' => self::MIGRATION,
                '--force' => true,
            ]);
        }
    }
}
