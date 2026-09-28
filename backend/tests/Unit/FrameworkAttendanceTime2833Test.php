<?php

namespace Tests\Unit;

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\PendingSwipeController;
use App\Models\ClassSession;
use App\Services\LeaveAttendanceService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** #2833: preserve the Carbon 2 whole-minute attendance contract in Carbon 3. */
class FrameworkAttendanceTime2833Test extends TestCase
{
    public function test_completed_attendance_keeps_hours_for_forward_and_reverse_windows(): void
    {
        $controller = new AttendanceController(new LeaveAttendanceService());
        $session = $this->session('14:00:00');
        $session->setAttribute('EndTime', '16:00:00');
        self::assertSame(2, $this->invoke($controller, 'resolveTimes', [], $session)[2]);
        foreach ([['14:00:00', '15:00:59', 1], ['14:00:00', '15:01:00', 2], ['16:00:00', '14:00:00', 2]] as [$start, $end, $hours]) {
            $result = $this->invoke($controller, 'resolveTimes', [
                'SignInDT' => '2026-09-01 '.$start,
                'SignOutDT' => '2026-09-01 '.$end,
            ], $session);
            self::assertSame($hours, $result[2]);
        }
        self::assertNull($this->invoke($controller, 'resolveTimes', [
            'SignInDT' => '2026-09-01 14:00:00',
        ], $session)[2]);
    }

    public function test_swipe_window_uses_completed_minutes_on_both_sides(): void
    {
        $controller = new AttendanceController(new LeaveAttendanceService());
        $session = $this->session('10:00:00');
        foreach (['09:29:30', '10:30:30'] as $time) {
            [$matched, $reason] = $this->invoke($controller, 'matchClosestSession', [$session], Carbon::parse('2026-09-01 '.$time), 30);
            self::assertSame($session, $matched);
            self::assertSame('matched', $reason);
        }
        self::assertSame([null, 'no_match_in_window'], $this->invoke(
            $controller, 'matchClosestSession', [$session], Carbon::parse('2026-09-01 10:31:00'), 30
        ));
    }

    public function test_same_whole_minute_matches_remain_ambiguous(): void
    {
        $controller = new AttendanceController(new LeaveAttendanceService());
        self::assertSame([null, 'ambiguous_session'], $this->invoke(
            $controller, 'matchClosestSession', [$this->session('10:00:00'), $this->session('10:01:00')],
            Carbon::parse('2026-09-01 10:00:40'), 30
        ));
    }

    public function test_pending_swipe_preserves_first_match_in_a_whole_minute_tie(): void
    {
        $first = $this->session('10:00:00');
        self::assertSame($first, $this->invoke(
            new PendingSwipeController(), 'pickClosestSession', [$first, $this->session('10:01:00')],
            Carbon::parse('2026-09-01 10:00:40')
        ));
    }

    private function session(string $start): ClassSession
    {
        return new ClassSession(['SessionDate' => '2026-09-01', 'StartTime' => $start]);
    }

    private function invoke(object $target, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
    }
}
