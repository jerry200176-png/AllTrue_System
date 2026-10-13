<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Services\CourseLeaveCascadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #3914: a course can hold two lessons on one date (two contract slots on the same weekday, or a leftover duplicate).
 * Leave and undo must act on the lesson that was clicked, not on the first lesson of that date.
 */
class LeaveCascadeSameDateLessonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_mode_leave_and_undo_follow_the_selected_lesson(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');

        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $second));
        $this->assertSame(['scheduled', 'leave'], $this->statuses($first, $second));
        $secondTail = $this->tailFor($courseId, $second);
        $this->assertNotNull($secondTail, 'the second lesson gets its own tail');

        // The first lesson of the same date is still leaveable; the second's tail is not taken as proof it is done.
        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $first));
        $this->assertSame(['leave', 'leave'], $this->statuses($first, $second));
        $this->assertNotNull($this->tailFor($courseId, $first));

        // Undo the second lesson only: its own tail goes, the first lesson and its tail stay.
        DB::transaction(fn () => CourseLeaveCascadeService::undoLeaveCascade($courseId, '2026-07-01', $second));
        $this->assertSame(['leave', 'scheduled'], $this->statuses($first, $second));
        $this->assertNull($this->tailFor($courseId, $second));
        $this->assertNotNull($this->tailFor($courseId, $first));
    }

    public function test_monthly_leave_on_the_second_lesson_is_not_swallowed_by_the_first(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'date');
        DB::table('ClassSession')->where('id', $first)->update(['Status' => 'leave']);

        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $second));

        $this->assertSame(['leave', 'leave'], $this->statuses($first, $second));
    }

    public function test_calendar_start_time_names_the_lesson_only_when_it_is_unique(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');

        $this->assertSame($second, CourseLeaveCascadeService::sessionIdAt($courseId, '2026-07-01', '19:30'));
        $this->assertSame($first, CourseLeaveCascadeService::sessionIdAt($courseId, '2026-07-01', '17:00:00'));
        $this->assertNull(CourseLeaveCascadeService::sessionIdAt($courseId, '2026-07-01', null));
        $this->assertNull(CourseLeaveCascadeService::sessionIdAt($courseId, '2026-07-01', '08:00'));
    }

    /** @return array{0:int,1:int,2:int} [course, 17:00 lesson, 19:30 lesson] */
    private function courseWithTwoLessonsOn(string $date, string $mode): array
    {
        $studentId = (int) DB::table('Student')->insertGetId(['name' => 'Same-date leave', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 77622, 'by1' => 1, 'Period' => 4,
            'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 0, 'ClassType' => 'one_on_one',
            'StartDate' => $date, 'EndDate' => $mode === 'date' ? '2026-07-31' : null, 'SessionCount' => 6, 'SessionDuration' => 120,
            'RemainingSessions' => 6, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => $mode,
            'week' => 3, 'time' => '17:00:00', 'week1' => 3, 'time1' => '19:30:00',
        ]);
        $ids = [];
        foreach ([[$date, '17:00:00', '19:00:00'], [$date, '19:30:00', '21:30:00'], ['2026-07-08', '17:00:00', '19:00:00'],
            ['2026-07-08', '19:30:00', '21:30:00'], ['2026-07-15', '17:00:00', '19:00:00'], ['2026-07-15', '19:30:00', '21:30:00']] as [$d, $s, $e]) {
            $ids[] = (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $courseId, 'SessionDate' => $d, 'StartTime' => $s,
                'EndTime' => $e, 'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$courseId, $ids[0], $ids[1]];
    }

    /** @return array<int, string> */
    private function statuses(int ...$ids): array
    {
        return array_map(fn (int $id) => strtolower((string) ClassSession::query()->findOrFail($id)->Status), $ids);
    }

    private function tailFor(int $courseId, int $leaveSessionId): ?ClassSession
    {
        return ClassSession::query()->where('StudentClassID', $courseId)->where('Status', 'scheduled')
            ->get()->first(fn ($s) => (bool) preg_match('/:ls=' . $leaveSessionId . '(?!\d)/', (string) $s->Note));
    }
}
