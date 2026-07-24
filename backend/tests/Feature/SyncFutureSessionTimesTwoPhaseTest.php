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
 * Regression: Sentry PHP-LARAVEL-25 / #1384
 *
 * syncFutureScheduledSessionTimes used to update StartTime in place. Under
 * uq_class_session_slot, shifting an earlier row onto a later sibling's
 * not-yet-vacated time 1062s. Two-phase park/place must complete cleanly.
 */
class SyncFutureSessionTimesTwoPhaseTest extends TestCase
{
    use RefreshDatabase;

    /** DDL cannot run inside the RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php';
    private const STUDENT_ID = 77902;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00', 'Asia/Taipei'));
        $this->enableUniqueIndex();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
        $controller = app(StudentClassController::class);
        $method = new \ReflectionMethod($controller, 'syncFutureScheduledSessionTimes');
        $method->setAccessible(true);
        return (int) $method->invoke($controller, $courseId, $slots, $durationMinutes);
    }

    private function seedCourse(): int
    {
        if (!DB::table('Student')->where('id', self::STUDENT_ID)->exists()) {
            DB::table('Student')->insert([
                'id' => self::STUDENT_ID,
                'name' => 'Sync1384',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);
        }

        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => self::STUDENT_ID,
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
        putenv('APPLY_CLASS_SESSION_UNIQUE_INDEX=1');
        $_ENV['APPLY_CLASS_SESSION_UNIQUE_INDEX'] = '1';
        $_SERVER['APPLY_CLASS_SESSION_UNIQUE_INDEX'] = '1';
        if (count(DB::select("SHOW INDEX FROM ClassSession WHERE Key_name = 'uq_class_session_slot'")) === 0) {
            Artisan::call('migrate', [
                '--path' => self::MIGRATION,
                '--force' => true,
            ]);
        }
    }
}
