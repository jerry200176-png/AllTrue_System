<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\User;
use App\Models\UserCampus;
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
        $this->assertSame('19:30', substr((string) $secondTail->StartTime, 0, 5), 'the tail keeps the left lesson\'s own slot');

        // The first lesson of the same date is still leaveable; the second's tail is not taken as proof it is done.
        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $first));
        $this->assertSame(['leave', 'leave'], $this->statuses($first, $second));
        $this->assertNotNull($this->tailFor($courseId, $first));
        $this->assertSame('17:00', substr((string) $this->tailFor($courseId, $first)->StartTime, 0, 5), 'each tail keeps its own lesson\'s slot');

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

    /** #3914 review: with two lessons on the date, a caller that does not name one is refused instead of hitting the first. */
    public function test_an_unnamed_leave_or_undo_on_a_two_lesson_date_is_refused(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');
        foreach ([fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01'), fn () => CourseLeaveCascadeService::undoLeaveCascade($courseId, '2026-07-01')] as $call) {
            try {
                DB::transaction($call);
                $this->fail('an unnamed lesson must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('兩堂以上', $e->getMessage());
            }
        }
        $this->assertSame(['scheduled', 'scheduled'], $this->statuses($first, $second));
    }

    /** #3914 review: each lesson keeps its own calendar marker; undo removes only its own; a leave row is never moved in place. */
    public function test_leave_markers_follow_each_lesson(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-12-02', 'count');
        $h = ['Authorization' => 'Bearer ' . $this->directorToken(), 'Accept' => 'application/json'];
        $markers = fn () => Schedule::query()->where('student_course_id', $courseId)->where('status', 'leave')->get()
            ->map(fn ($m) => substr((string) $m->start_time, 0, 5))->sort()->values()->all();
        foreach ([$first, $second] as $id) {
            $this->postJson('/api/v1/schedules/leave-by-session', ['class_session_id' => $id], $h)->assertOk();
        }
        $this->assertSame(['17:00', '19:30'], $markers());
        $this->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $second], $h)->assertOk();
        $this->assertSame(['17:00'], $markers());
        $this->assertSame(['leave', 'scheduled'], $this->statuses($first, $second));

        $row = Schedule::query()->where('student_course_id', $courseId)->where('status', 'leave')->firstOrFail();
        foreach (['leave', 'scheduled'] as $newStatus) { // moving it is refused whatever the new status
            $this->putJson("/api/v1/schedules/{$row->id}", ['status' => $newStatus, 'start_time' => '19:30', 'end_time' => '21:30'], $h)
                ->assertStatus(422)->assertJsonPath('code', 'leave_row_retarget_forbidden');
        }
        // a date-only retro leave on a two-lesson date is refused rather than resolved to the first lesson
        $this->postJson('/api/v1/schedules/retro-leave', ['student_course_id' => $courseId, 'session_date' => '2026-12-02'], $h)
            ->assertStatus(422)->assertJsonPath('code', 'lesson_required');
        $this->assertSame(['leave', 'scheduled'], $this->statuses($first, $second));
    }

    /** #3914 review: a closure day marks both lessons, each marker at its own time. */
    public function test_bulk_leave_records_each_lesson_at_its_own_time(): void
    {
        [$courseId] = $this->courseWithTwoLessonsOn('2026-12-02', 'count');
        $this->postJson('/api/v1/schedules/bulk-leave', ['branch_id' => 1, 'start_date' => '2026-12-02', 'end_date' => '2026-12-02'],
            ['Authorization' => 'Bearer ' . $this->directorToken(), 'Accept' => 'application/json'])->assertOk();
        $times = Schedule::query()->where('student_course_id', $courseId)->where('status', 'leave')->get()->map(fn ($m) => substr((string) $m->start_time, 0, 5))->sort()->values()->all();
        $this->assertSame(['17:00', '19:30'], $times);
    }

    /** #3914 review: the attendance endpoint leaves only the selected lesson, with sign-in and tail for that lesson alone. */
    public function test_attendance_leave_on_the_second_lesson_touches_only_that_lesson(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-12-02', 'count');
        $studentId = (int) DB::table('StudentClass')->where('ID', $courseId)->value('StudentID');
        $this->postJson('/api/v1/attendance', ['StudentID' => $studentId, 'StudentClassID' => $courseId, 'ClassSessionID' => $second, 'Status' => 'leave', 'mark_mode' => 'arrival'],
            ['Authorization' => 'Bearer ' . $this->directorToken(), 'Accept' => 'application/json'])->assertSuccessful();

        $this->assertSame(['scheduled', 'leave'], $this->statuses($first, $second));
        $this->assertSame(0, \App\Models\StudentSignIn::query()->where('ClassSessionID', $first)->count(), 'the first lesson gets no attendance artifact');
        $this->assertGreaterThan(0, \App\Models\StudentSignIn::query()->where('ClassSessionID', $second)->count());
        $this->assertSame('19:30', substr((string) $this->tailFor($courseId, $second)?->StartTime, 0, 5));
        $this->assertNull($this->tailFor($courseId, $first));
    }

    /** #4019: both lessons of a date left in two steps share the next contract date, each in its own slot; preview agrees. */
    public function test_both_lessons_left_share_one_next_contract_date(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');

        $this->assertSame('2026-07-22', $this->previewAppend($courseId, $second));
        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $second));
        $this->assertSame(['2026-07-22', '19:30'], $this->tailSlot($courseId, $second));

        $this->assertSame('2026-07-22', $this->previewAppend($courseId, $first), 'the preview shows the shared date that is written');
        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $first));
        $this->assertSame(['2026-07-22', '17:00'], $this->tailSlot($courseId, $first));
        $this->assertSame('2026-07-22', $this->endDate($courseId), 'two leaves on one date extend the course by one week');

        DB::transaction(fn () => CourseLeaveCascadeService::undoLeaveCascade($courseId, '2026-07-01', $second));
        $this->assertNull($this->tailFor($courseId, $second));
        $this->assertSame(['2026-07-22', '17:00'], $this->tailSlot($courseId, $first));
        $this->assertSame('2026-07-22', $this->endDate($courseId));

        DB::transaction(fn () => CourseLeaveCascadeService::undoLeaveCascade($courseId, '2026-07-01', $first));
        $this->assertNull($this->tailFor($courseId, $first));
        $this->assertSame('2026-07-15', $this->endDate($courseId));
    }

    /** #4019: a cancelled row in the shared slot is not handed back as the tail; a live scheduled tail is created. */
    public function test_shared_tail_over_a_cancelled_row_is_scheduled(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');
        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $second));
        $cancelled = (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $courseId, 'SessionDate' => '2026-07-22',
            'StartTime' => '17:00:00', 'EndTime' => '19:00:00', 'Status' => 'cancelled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);

        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $first));

        $tail = $this->tailFor($courseId, $first);
        $this->assertNotNull($tail);
        $this->assertNotSame($cancelled, (int) $tail->id);
        $this->assertSame(['2026-07-22', '17:00'], $this->tailSlot($courseId, $first));
        $this->assertSame(['cancelled'], $this->statuses($cancelled));
    }

    /** #4019: monthly courses never append, so neither leave moves EndDate and the preview has no tail. */
    public function test_monthly_two_lesson_leaves_append_nothing(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'date');
        foreach ([$second, $first] as $id) {
            $this->assertNull($this->previewAppend($courseId, $id));
            DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $id));
        }
        $this->assertSame(['leave', 'leave'], $this->statuses($first, $second));
        $this->assertNull($this->tailFor($courseId, $first));
        $this->assertNull($this->tailFor($courseId, $second));
        $this->assertSame('2026-07-31', $this->endDate($courseId));
    }

    /** #4019: a latest date that holds only a makeup at a non-contract time is never reused for a tail. */
    public function test_a_lone_makeup_date_is_not_reused(): void
    {
        [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');
        DB::table('StudentClass')->where('ID', $courseId)->update(['SessionCount' => 7, 'RemainingSessions' => 7]);
        DB::table('ClassSession')->insert(['StudentClassID' => $courseId, 'SessionDate' => '2026-07-22', 'StartTime' => '10:00:00',
            'EndTime' => '11:00:00', 'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('2026-07-29', $this->previewAppend($courseId, $second));
        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $second));
        $this->assertSame(['2026-07-29', '19:30'], $this->tailSlot($courseId, $second));

        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $first));
        $this->assertSame(['2026-07-29', '17:00'], $this->tailSlot($courseId, $first), 'the sibling tail\'s date is shared');
        $this->assertSame('2026-07-29', $this->endDate($courseId));
    }

    /** #4043 review: on a Wed 17:00/19:30 + Sat 10:00 course ending on Saturday, a Wednesday lesson's tail goes to the next Wednesday. */
    public function test_multi_weekday_tail_never_takes_another_weekdays_date(): void
    {
        [$courseId, , $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');
        DB::table('StudentClass')->where('ID', $courseId)->update(['SessionCount' => 7, 'RemainingSessions' => 7, 'week2' => 6, 'time2' => '10:00:00']);
        DB::table('ClassSession')->insert(['StudentClassID' => $courseId, 'SessionDate' => '2026-07-18', 'StartTime' => '10:00:00',
            'EndTime' => '12:00:00', 'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('2026-07-22', $this->previewAppend($courseId, $second));
        DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $second));
        $this->assertSame(['2026-07-22', '19:30'], $this->tailSlot($courseId, $second), 'the Wednesday 19:30 tail is not written onto Saturday 07-18');
    }

    /** #4047: with only 17:00 on the final Wednesday, both leave orders give the same tails: 19:30 fills 07-15, 17:00 takes 07-22. */
    public function test_partial_final_date_gives_the_same_tails_in_either_leave_order(): void
    {
        $partial = function (int $courseId, bool $oneOffEdit = false): void {
            DB::table('StudentClass')->where('ID', $courseId)->update(['SessionCount' => 5, 'RemainingSessions' => 5]);
            $final = DB::table('ClassSession')->where('StudentClassID', $courseId)->where('SessionDate', '2026-07-15');
            (clone $final)->where('StartTime', '19:30:00')->delete();
            // a one-off edit (IsContractException) of a contract lesson is not a makeup and still anchors (#4052 review)
            (clone $final)->update(['IsContractException' => $oneOffEdit]);
        };
        $this->assertSame([['2026-07-22', '17:00'], ['2026-07-15', '19:30'], '2026-07-22'], $this->leaveInBothOrders($partial));
        $this->assertSame([['2026-07-22', '17:00'], ['2026-07-15', '19:30'], '2026-07-22'], $this->leaveInBothOrders(fn ($c) => $partial($c, true)));
    }

    /** #4052 review: on a Wed 17:00/19:30 + Sat 10:00 course whose base ends on a full Wednesday, both tails take the next Wednesday. */
    public function test_multi_weekday_full_final_date_tails_stay_on_the_lessons_weekday(): void
    {
        $this->assertSame([['2026-07-22', '17:00'], ['2026-07-22', '19:30'], '2026-07-22'], $this->leaveInBothOrders(function (int $courseId): void {
            DB::table('StudentClass')->where('ID', $courseId)->update(['SessionCount' => 7, 'RemainingSessions' => 7, 'week2' => 6, 'time2' => '10:00:00']);
            DB::table('ClassSession')->insert(['StudentClassID' => $courseId, 'SessionDate' => '2026-07-11', 'StartTime' => '10:00:00',
                'EndTime' => '12:00:00', 'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        }));
    }

    /** #4052 review: a cancelled row after the last live lesson neither moves the tails nor pushes EndDate past them. */
    public function test_a_later_cancelled_row_does_not_push_end_date(): void
    {
        $this->assertSame([['2026-07-22', '17:00'], ['2026-07-22', '19:30'], '2026-07-22'], $this->leaveInBothOrders(function (int $courseId): void {
            DB::table('ClassSession')->insert(['StudentClassID' => $courseId, 'SessionDate' => '2026-07-29', 'StartTime' => '17:00:00',
                'EndTime' => '19:00:00', 'Status' => 'cancelled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        }));
    }

    /** #4047: a confirmed makeup (exception workflow) at a contract time is not a contract lesson, so it never anchors a tail. */
    public function test_a_lone_makeup_at_a_contract_time_is_not_reused(): void
    {
        $this->assertSame([['2026-07-29', '17:00'], ['2026-07-29', '19:30'], '2026-07-29'], $this->leaveInBothOrders(function (int $courseId): void {
            DB::table('StudentClass')->where('ID', $courseId)->update(['SessionCount' => 7, 'RemainingSessions' => 7]);
            DB::table('ClassSession')->insert(['StudentClassID' => $courseId, 'SessionDate' => '2026-07-22', 'StartTime' => '17:00:00', 'EndTime' => '19:00:00',
                'Status' => 'scheduled', 'Note' => 'exception_workflow:1', 'IsContractException' => true, 'created_at' => now(), 'updated_at' => now()]);
        }));
    }

    /**
     * Leaves both 07-01 lessons of a fresh course in each order, asserting each preview equals the written date and that
     * both orders end the same.
     *
     * @return array{0:?array,1:?array,2:?string} [17:00 tail slot, 19:30 tail slot, EndDate]
     */
    private function leaveInBothOrders(callable $setup): array
    {
        $results = [];
        foreach (['17:00 first' => [0, 1], '19:30 first' => [1, 0]] as $label => $order) {
            [$courseId, $first, $second] = $this->courseWithTwoLessonsOn('2026-07-01', 'count');
            $setup($courseId);
            foreach ($order as $i) {
                $id = [$first, $second][$i];
                $preview = $this->previewAppend($courseId, $id);
                DB::transaction(fn () => CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01', $id));
                $this->assertSame($preview, $this->tailSlot($courseId, $id)[0] ?? null, "$label: the preview shows the date that is written");
            }
            $results[$label] = [$this->tailSlot($courseId, $first), $this->tailSlot($courseId, $second), $this->endDate($courseId)];
        }
        $this->assertSame($results['17:00 first'], $results['19:30 first'], 'the leave order does not change the tails');

        return $results['17:00 first'];
    }

    private function previewAppend(int $courseId, int $sessionId): ?string
    {
        return $this->postJson('/api/v1/schedules/leave-cascade-preview', ['student_course_id' => $courseId, 'schedule_date' => '2026-07-01', 'class_session_id' => $sessionId],
            ['Authorization' => 'Bearer ' . $this->directorToken(), 'Accept' => 'application/json'])->assertOk()->json('append');
    }

    /** @return array{0:string,1:string}|null */
    private function tailSlot(int $courseId, int $leaveSessionId): ?array
    {
        $tail = $this->tailFor($courseId, $leaveSessionId);

        return $tail ? [substr((string) $tail->SessionDate, 0, 10), substr((string) $tail->StartTime, 0, 5)] : null;
    }

    private function endDate(int $courseId): ?string
    {
        $end = DB::table('StudentClass')->where('ID', $courseId)->value('EndDate');

        return $end ? substr((string) $end, 0, 10) : null;
    }

    private function directorToken(): string
    {
        $user = User::create(['LoginName' => 'leave-3914-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => $token = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);

        return $token;
    }

    /** @return array{0:int,1:int,2:int} [course, 17:00 lesson, 19:30 lesson] */
    private function courseWithTwoLessonsOn(string $date, string $mode): array
    {
        $studentId = (int) DB::table('Student')->insertGetId(['name' => 'Same-date leave', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 77622, 'by1' => 1, 'Period' => 4,
            'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 0, 'ClassType' => 'one_on_one',
            'StartDate' => $date, 'EndDate' => $mode === 'date' ? date('Y-m-t', strtotime($date)) : null, 'SessionCount' => 6, 'SessionDuration' => 120,
            'RemainingSessions' => 6, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => $mode,
            'week' => 3, 'time' => '17:00:00', 'week1' => 3, 'time1' => '19:30:00',
        ]);
        $ids = [];
        [$w1, $w2] = [date('Y-m-d', strtotime($date . ' +7 days')), date('Y-m-d', strtotime($date . ' +14 days'))];
        foreach ([[$date, '17:00:00', '19:00:00'], [$date, '19:30:00', '21:30:00'], [$w1, '17:00:00', '19:00:00'],
            [$w1, '19:30:00', '21:30:00'], [$w2, '17:00:00', '19:00:00'], [$w2, '19:30:00', '21:30:00']] as [$d, $s, $e]) {
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
