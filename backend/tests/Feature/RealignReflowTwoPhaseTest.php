<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #1163: the contract-realign reflow (remapFutureScheduledSessionsToContract)
 * remaps future scheduled sessions onto a new fixed-weekday cadence. When the
 * change compresses sessions forward, an earlier row's target is a later row's
 * not-yet-vacated slot — which 1062s under uq_class_session_slot if moved in
 * place. The two-phase fix (park to sentinel slots, then place, in a transaction)
 * must complete without a 500 and land the correct distinct final layout.
 *
 * Runs against the REAL unique index: without it the old in-place code's transient
 * duplicate resolves and the bug is invisible.
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

    public function test_weekday_removal_reflow_compresses_forward_without_1062(): void
    {
        $token = $this->createDirectorToken();

        DB::table('Student')->insert([
            'id' => self::STUDENT_ID, 'name' => 'Realign Two-Phase Test',
            'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => self::STUDENT_ID, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-03-01', 'TotalHours' => 20,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 4, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
            'week' => 6, 'time' => '13:00:00', 'week1' => 7, 'time1' => '13:00:00',
        ]);

        // Sat, Sun, Sat, Sun — four future scheduled sessions on the two-weekday contract.
        foreach (['2026-04-18', '2026-04-19', '2026-04-25', '2026-04-26'] as $date) {
            ClassSession::create([
                'StudentClassID' => $courseId, 'SessionDate' => $date,
                'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled',
            ]);
        }

        // Drop Sunday -> Saturday-only. The reflow must compress the four rows onto
        // consecutive Saturdays; the second row's target (04-25) is the third row's
        // current slot, which is the collision the old in-place code hit.
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$courseId}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'days_of_week' => [6],
            'start_time' => '13:00',
            'day_time_slots' => [['day' => 6, 'start_time' => '13:00']],
            'payment_type' => 'session',
        ]);

        $res->assertOk();

        $future = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')
            ->get();

        // Count preserved, all Saturdays, on consecutive weekly Saturdays, distinct slots.
        $this->assertCount(4, $future);
        $dates = $future->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->all();
        $this->assertSame(['2026-04-18', '2026-04-25', '2026-05-02', '2026-05-09'], $dates);
        foreach ($future as $s) {
            $this->assertSame(6, (int) Carbon::parse($s->SessionDate)->dayOfWeekIso, 'all on Saturday');
        }
        $slots = $future->map(fn ($s) => substr((string) $s->SessionDate, 0, 10) . ' ' . substr((string) $s->StartTime, 0, 5));
        $this->assertSame($slots->count(), $slots->unique()->count(), 'no duplicate active slots');
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

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'dir-realign-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return $tok;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->uniqueIndexExists()) {
            DB::statement('ALTER TABLE ClassSession DROP INDEX uq_class_session_slot');
        }
        $cid = DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->value('ID');
        if ($cid) {
            DB::table('ClassSession')->where('StudentClassID', $cid)->delete();
            DB::table('schedules')->where('student_course_id', $cid)->delete();
        }
        DB::table('StudentClass')->where('StudentID', self::STUDENT_ID)->delete();
        DB::table('Student')->where('id', self::STUDENT_ID)->delete();
        // Director User/UserCampus/AuthToken rows are unique per run and harmless
        // (RefreshDatabase re-migrates per class); intentionally not deleted here.
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
