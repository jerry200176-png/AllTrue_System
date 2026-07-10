<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1130 P1-ghost: cross-SC duplicate repair planner — deterministic ghost-shell
 * cancellation with LR/Stop guards; p2-list stays read-only.
 */
class RepairCrossScGhostTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT = ['SessionDate' => '2026-06-10', 'StartTime' => '17:00:00', 'EndTime' => '18:00:00'];

    protected function setUp(): void
    {
        parent::setUp();
        // The first Artisan::call after RefreshDatabase's migrate:fresh loses its
        // output buffer (first-test-in-process quirk); absorb it with a no-op so
        // the real calls below capture reliably regardless of test order.
        Artisan::call('env');
        Artisan::output();
    }

    public function test_ghost_pair_is_planned_and_executed(): void
    {
        $studentId = 79001;
        $realSc = $this->createCourse($studentId, sessionCount: 8);
        $ghostSc = $this->createCourse($studentId, sessionCount: 0);
        $keepCs = $this->createSession($realSc, 'attended');
        $ghostCs = $this->createSession($ghostSc, 'attended');

        // Effect-based assertions (this test's in-process output capture is
        // environment-flaky; the exact plan lines are covered by tests below
        // and were verified against a real subprocess run).
        $this->assertSame(0, Artisan::call('repair:duplicate-sessions', ['--case' => 'p1-ghost']));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $ghostCs)->value('Status'), 'dry-run must not write');

        $snapshot = storage_path('app/repair-snapshots/test-p1-ghost.json');
        $this->assertSame(0, Artisan::call('repair:duplicate-sessions', [
            '--case' => 'p1-ghost', '--execute' => true,
            '--snapshot' => $snapshot,
        ]));
        $this->assertFileExists($snapshot);

        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $ghostCs)->value('Status'));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $keepCs)->value('Status'));
        $this->assertSame(1, (int) DB::table('StudentClass')->where('ID', $ghostSc)->value('Stop'));
        $this->assertSame(0, (int) DB::table('StudentClass')->where('ID', $realSc)->value('Stop'));
    }

    public function test_group_is_skipped_when_learning_record_sits_on_ghost_side(): void
    {
        $studentId = 79002;
        $realSc = $this->createCourse($studentId, sessionCount: 8);
        $ghostSc = $this->createCourse($studentId, sessionCount: 0);
        $this->createSession($realSc, 'attended');
        $ghostCs = $this->createSession($ghostSc, 'attended');
        $this->createLearningRecord($ghostSc, $ghostCs);

        Artisan::call('repair:duplicate-sessions', ['--case' => 'p1-ghost']);
        $out = Artisan::output();
        $this->assertStringContainsString('評量掛在幽靈側', $out);
        $this->assertStringNotContainsString("WOULD cancel ClassSession id={$ghostCs}", $out);
        $this->assertStringContainsString('actions=0', $out);
    }

    public function test_stop_is_skipped_when_ghost_shell_has_other_live_sessions(): void
    {
        $studentId = 79003;
        $realSc = $this->createCourse($studentId, sessionCount: 8);
        $ghostSc = $this->createCourse($studentId, sessionCount: 0);
        $this->createSession($realSc, 'attended');
        $ghostCs = $this->createSession($ghostSc, 'attended');
        // unrelated live session on the shell (different day, no duplicate)
        $this->createSession($ghostSc, 'scheduled', '2026-06-17');

        Artisan::call('repair:duplicate-sessions', ['--case' => 'p1-ghost']);
        $out = Artisan::output();
        $this->assertStringContainsString("WOULD cancel ClassSession id={$ghostCs}", $out);
        $this->assertStringContainsString("SKIP Stop=1 for ghost SC{$ghostSc}", $out);
        $this->assertStringNotContainsString("WOULD StudentClass id={$ghostSc} Stop=1", $out);
    }

    public function test_p2_list_is_read_only_and_excludes_ghost_pairs(): void
    {
        $studentId = 79004;
        // non-ghost pair: both contracts real (SessionCount > 0) → P2 scope
        $scA = $this->createCourse($studentId, sessionCount: 8);
        $scB = $this->createCourse($studentId, sessionCount: 4);
        $csA = $this->createSession($scA, 'attended');
        $csB = $this->createSession($scB, 'attended');

        $this->assertSame(0, Artisan::call('repair:duplicate-sessions', ['--case' => 'p2-list', '--execute' => true, '--force' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('read-only', $out);
        $this->assertStringContainsString('p2_review_groups=1', $out);
        $this->assertStringContainsString("student={$studentId}", $out);

        // nothing was written
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $csA)->value('Status'));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $csB)->value('Status'));
    }

    private function createCourse(int $studentId, int $sessionCount): int
    {
        if (!DB::table('Student')->where('id', $studentId)->exists()) {
            DB::table('Student')->insert([
                'id' => $studentId,
                'name' => 'CrossSC Test ' . $studentId,
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
            ]);
        }

        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_two',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionCount' => $sessionCount,
            'SessionDuration' => 60,
            'RemainingSessions' => max(0, $sessionCount - 1),
            'UsedSessions' => $sessionCount > 0 ? 1 : 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ]);
    }

    private function createSession(int $scId, string $status, ?string $date = null): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => $date ?? self::SLOT['SessionDate'],
            'StartTime' => self::SLOT['StartTime'],
            'EndTime' => self::SLOT['EndTime'],
            'Status' => $status,
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLearningRecord(int $scId, int $csId): void
    {
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $scId,
            'ClassSessionID' => $csId,
            'TeacherID' => 1,
            'Content' => '',
            'Subject' => 'Test',
            'SessionDate' => self::SLOT['SessionDate'],
            'StartTime' => self::SLOT['StartTime'],
            'EndTime' => self::SLOT['EndTime'],
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
