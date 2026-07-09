<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentClassController;
use Tests\TestCase;

/**
 * GitHub #1096 (in-app #190) regression: buildSessionsFromWeeklySchedule compared
 * Carbon dayOfWeek (0=Sun…6=Sat) against slot weekdays that arrive in ISO form
 * (1=Mon…7=Sun) from DB week columns / day_time_slots. Mon–Sat agree in both
 * conventions, but ISO Sunday (7) never equals dayOfWeek Sunday (0), so every
 * Sunday-slot date-mode course generated ZERO sessions:
 *  - renew-monthly computed SessionCount=0 / Charge=0 → NT$0 invoices (洪子勛 case)
 *  - EnrollmentService monthly_recurring undercounted monthly_sessions
 *
 * The builder must now accept both conventions (0 and 7 both mean Sunday).
 */
class WeeklyScheduleSundayBuilderTest extends TestCase
{
    private function build(array $slots, string $start = '2026-06-01', string $end = '2026-06-30'): array
    {
        $controller = app(StudentClassController::class);

        return $controller->buildSessionsFromWeeklySchedule(1, $start, $end, $slots, 120);
    }

    public function test_iso_sunday_slot_generates_sunday_sessions(): void
    {
        // June 2026 Sundays: 7, 14, 21, 28
        $sessions = $this->build([['weekday' => 7, 'time' => '10:00']]);

        $this->assertSame(
            ['2026-06-07', '2026-06-14', '2026-06-21', '2026-06-28'],
            array_column($sessions, 'SessionDate'),
            'ISO Sunday (7) slots must generate all Sunday sessions in range'
        );
        $this->assertSame('10:00:00', $sessions[0]['StartTime']);
        $this->assertSame('12:00:00', $sessions[0]['EndTime']);
    }

    public function test_legacy_js_sunday_slot_still_generates_sunday_sessions(): void
    {
        $sessions = $this->build([['weekday' => 0, 'time' => '10:00']]);

        $this->assertSame(
            ['2026-06-07', '2026-06-14', '2026-06-21', '2026-06-28'],
            array_column($sessions, 'SessionDate'),
            'Legacy JS Sunday (0) slots must keep working'
        );
    }

    public function test_monday_to_saturday_slots_are_unchanged(): void
    {
        // June 2026 Mondays: 1, 8, 15, 22, 29 — Saturdays: 6, 13, 20, 27
        $monday = $this->build([['weekday' => 1, 'time' => '18:00']]);
        $saturday = $this->build([['weekday' => 6, 'time' => '09:00']]);

        $this->assertSame(
            ['2026-06-01', '2026-06-08', '2026-06-15', '2026-06-22', '2026-06-29'],
            array_column($monday, 'SessionDate')
        );
        $this->assertSame(
            ['2026-06-06', '2026-06-13', '2026-06-20', '2026-06-27'],
            array_column($saturday, 'SessionDate')
        );
    }

    public function test_mixed_sunday_and_weekday_slots(): void
    {
        $sessions = $this->build([
            ['weekday' => 3, 'time' => '16:00'],
            ['weekday' => 7, 'time' => '10:00'],
        ], '2026-06-01', '2026-06-14');

        // Wed 6/3, Sun 6/7, Wed 6/10, Sun 6/14
        $this->assertSame(
            ['2026-06-03', '2026-06-07', '2026-06-10', '2026-06-14'],
            array_column($sessions, 'SessionDate'),
            'Sunday slots must coexist with weekday slots'
        );
    }
}
