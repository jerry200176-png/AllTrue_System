<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #3901 / in-app #393: a monthly (ScheduleMode=date) leave must stick (R136).
 * A same-day read may not bring back a "replacement" lesson on the leave date.
 */
class MonthlyCalendarLeaveNoBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_day_teacher_read_does_not_recreate_a_lesson_on_a_monthly_leave_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-a@example.com');
        $course = $this->createMonthlyCourse($teacherId);
        // The contract slot is Tue 18:00, but this lesson was moved to 19:00 on the
        // same day and then put on leave. The contract time no longer matches it.
        $leave = $this->createSession($course, '2026-10-13', '19:00', '21:00', 'leave');
        $leave->forceFill(['OriginalSessionDate' => '2026-10-13', 'OriginalStartTimeHM' => '18:00'])->save();

        $this->withHeaders(['Authorization' => "Bearer {$teacherToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions?start=2026-10-13&end=2026-10-13')
            ->assertOk();

        $onDate = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-10-13')->get();
        $this->assertCount(1, $onDate, 'A monthly leave date must not get an auto-created replacement lesson.');
        $this->assertSame((int) $leave->id, (int) $onDate->first()->id);
        $this->assertSame('leave', strtolower((string) $onDate->first()->Status));
    }

    public function test_same_day_teacher_read_still_fills_the_missing_slot_of_a_two_slot_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-b@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // Only the 13:00 contract slot exists (cancelled); the 18:00 slot is still unmaterialized.
        $this->createSession($course, '2026-10-13', '13:00', '15:00', 'cancelled');

        $this->withHeaders(['Authorization' => "Bearer {$teacherToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions?start=2026-10-13&end=2026-10-13')
            ->assertOk();

        $starts = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-10-13')
            ->orderBy('StartTime')->get()->map(fn ($s) => substr((string) $s->StartTime, 0, 5).' '.strtolower((string) $s->Status))->all();
        $this->assertSame(['13:00 cancelled', '18:00 scheduled'], $starts);
    }

    public function test_a_moved_lesson_with_independent_identity_covers_only_its_original_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-c@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // Independent occurrence identity proves 13:00 moved to 14:00; 18:00 is unmaterialized.
        $moved = $this->createSession($course, '2026-10-13', '14:00', '16:00', 'leave');
        $moved->forceFill(['OriginalSessionDate' => '2026-10-13', 'OriginalStartTimeHM' => '13:00'])->save();

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['14:00 leave', '18:00 scheduled'], $this->startsOn($course));
    }


    public static function legacyOneOffNotes(): array
    {
        return ['default add-session note' => ['系統加課'], 'custom add-session note' => ['臨時加課'], 'ambiguous moved lesson' => ['']];
    }

    /** @dataProvider legacyOneOffNotes */
    public function test_legacy_off_contract_session_without_independent_identity_does_not_hide_recurring_slots(string $note): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-extra@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        $extra = $this->createSession($course, '2026-10-13', '14:00', '16:00', 'scheduled');
        $extra->forceFill(['Note' => $note, 'OriginalSessionDate' => null, 'OriginalStartTimeHM' => null])->save();

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['13:00 scheduled', '14:00 scheduled', '18:00 scheduled'], $this->startsOn($course));
        $this->assertSame((int) $extra->id, (int) ClassSession::find($extra->id)->id);
        $this->assertNull($extra->fresh()->OriginalStartTimeHM, 'A read must not invent a legacy occurrence identity.');
    }

    /** Owner decision (#3905 thread 4256922713): a lesson added through add-session at a non-contract time hides no contract slot. */
    public function test_a_lesson_added_through_add_session_off_contract_does_not_hide_recurring_slots(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-add@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        $this->withHeaders(['Authorization' => "Bearer {$teacherToken}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/student-classes/{$course->ID}/add-session", ['session_date' => '2026-10-13', 'start_time' => '14:00', 'duration_minutes' => 120])
            ->assertSuccessful();

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['13:00 scheduled', '14:00 scheduled', '18:00 scheduled'], $this->startsOn($course));
    }

    public function test_a_lesson_moved_onto_another_contract_slot_keeps_its_original_slot_covered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-d@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // The 13:00 lesson was moved to 18:00 and put on leave; its frozen identity still says 13:00.
        $moved = $this->createSession($course, '2026-10-13', '18:00', '20:00', 'leave');
        $moved->forceFill(['OriginalSessionDate' => '2026-10-13', 'OriginalStartTimeHM' => '13:00'])->save();

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['18:00 leave'], $this->startsOn($course), 'The 13:00 slot belongs to the moved lesson; no replacement.');
    }

    public function test_an_extra_makeup_row_does_not_hide_the_contract_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-e@example.com');
        $course = $this->createMonthlyCourse($teacherId);
        Schedule::create([
            'student_id' => $course->StudentID, 'teacher_id' => $teacherId, 'subject' => 'Math', 'day_of_week' => 2,
            'start_time' => '10:00', 'end_time' => '12:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => 'cancelled', 'type' => 'extra', 'deduction' => 0, 'branch_id' => 1,
            'schedule_date' => '2026-10-13', 'student_course_id' => $course->ID,
        ]);

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['18:00 scheduled'], $this->startsOn($course));
    }

    public function test_a_leave_marker_and_its_session_count_as_one_lesson_and_a_moved_in_lesson_covers_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-f@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // 13:00 moved to 14:00 and put on leave: the normal flow leaves a Schedule marker AND the ClassSession (identity 13:00).
        $moved = $this->createSession($course, '2026-10-13', '14:00', '16:00', 'leave');
        $moved->forceFill(['OriginalSessionDate' => '2026-10-13', 'OriginalStartTimeHM' => '13:00'])->save();
        $this->extraSchedule($course, $teacherId, '14:00', 'leave', 'normal');
        // A lesson moved IN from Monday sits at 10:00 and stands for no slot of this Tuesday.
        $in = $this->createSession($course, '2026-10-13', '10:00', '12:00', 'scheduled');
        $in->forceFill(['OriginalSessionDate' => '2026-10-12', 'OriginalStartTimeHM' => '17:00'])->save();

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['10:00 scheduled', '14:00 leave', '18:00 scheduled'], $this->startsOn($course));
    }

    public function test_a_makeup_session_and_a_schedule_only_moved_leave_use_the_right_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-g@example.com');
        $course = $this->createMonthlyCourse($teacherId);
        // A makeup (extra Schedule + its ClassSession, no identity) at 10:00 must not hide the 18:00 contract slot.
        $this->createSession($course, '2026-10-13', '10:00', '12:00', 'cancelled');
        $this->extraSchedule($course, $teacherId, '10:00', 'cancelled', 'extra');

        $this->readTeacherDay($teacherToken);
        $this->assertSame(['10:00 cancelled', '18:00 scheduled'], $this->startsOn($course));

        // Schedule-only moved leave with frozen identity 18:00 (now 17:30): the 18:00 slot is covered.
        ClassSession::where('StudentClassID', $course->ID)->delete();
        Schedule::where('student_course_id', $course->ID)->delete();
        $this->extraSchedule($course, $teacherId, '17:30', 'leave', 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '18:00']);

        $this->readTeacherDay($teacherToken);
        $this->assertSame([], $this->startsOn($course));
    }

    public function test_a_legacy_session_borrows_the_frozen_identity_of_its_leave_marker(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-h@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // Mixed-version shape: the session (no identity) and its leave marker (identity 13:00) both sit at 17:30.
        $this->createSession($course, '2026-10-13', '17:30', '19:30', 'leave');
        $this->extraSchedule($course, $teacherId, '17:30', 'leave', 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '13:00']);

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['17:30 leave', '18:00 scheduled'], $this->startsOn($course), 'No ghost 13:00; the 18:00 lesson is still filled.');
    }

    public function test_a_move_of_a_move_counts_as_one_lesson_from_its_first_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-i@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // 13:00 -> 14:00 -> 15:00 through the reschedule chain; the live session at 15:00 has no identity.
        $this->createSession($course, '2026-10-13', '15:00', '17:00', 'scheduled');
        $first = $this->extraSchedule($course, $teacherId, '13:00', 'rescheduled', 'normal');
        $hop = $this->extraSchedule($course, $teacherId, '14:00', 'rescheduled', 'normal', ['original_schedule_id' => $first->id]);
        $this->extraSchedule($course, $teacherId, '15:00', 'scheduled', 'normal', ['original_schedule_id' => $hop->id]);

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['15:00 scheduled', '18:00 scheduled'], $this->startsOn($course));
    }

    public function test_a_cancelled_makeup_does_not_hide_a_regular_lesson_moved_to_its_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-j@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // A cancelled makeup at 14:00 (extra Schedule + its session) ...
        $this->createSession($course, '2026-10-13', '14:00', '16:00', 'cancelled');
        $this->extraSchedule($course, $teacherId, '14:00', 'cancelled', 'extra');
        // ... and the regular 13:00 lesson moved to 14:00 and put on leave.
        $moved = $this->createSession($course, '2026-10-13', '14:00', '16:00', 'leave');
        $moved->forceFill(['OriginalSessionDate' => '2026-10-13', 'OriginalStartTimeHM' => '13:00'])->save();
        $this->extraSchedule($course, $teacherId, '14:00', 'leave', 'normal');

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['14:00 cancelled', '14:00 leave', '18:00 scheduled'], $this->startsOn($course));
    }

    public function test_two_cancelled_same_time_lessons_from_different_slots_cover_both(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-k@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // Both contract lessons were moved to 15:00 and cancelled; their markers carry different slots.
        $this->createSession($course, '2026-10-13', '15:00', '17:00', 'cancelled');
        $this->createSession($course, '2026-10-13', '15:00', '17:00', 'cancelled');
        $this->extraSchedule($course, $teacherId, '15:00', 'cancelled', 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '13:00']);
        $this->extraSchedule($course, $teacherId, '15:00', 'cancelled', 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '18:00']);

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['15:00 cancelled', '15:00 cancelled'], $this->startsOn($course));
    }

    public function test_a_partially_materialized_same_time_group_keeps_the_unmatched_marker(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-p@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // Two cancelled lessons (13:00 and 18:00) moved to 15:00, but only one legacy session exists.
        $this->createSession($course, '2026-10-13', '15:00', '17:00', 'cancelled');
        $this->extraSchedule($course, $teacherId, '15:00', 'cancelled', 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '13:00']);
        $this->extraSchedule($course, $teacherId, '15:00', 'cancelled', 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '18:00']);

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['15:00 cancelled'], $this->startsOn($course), 'Neither cancelled lesson is replaced.');
    }

    public function test_a_marker_identity_wins_over_a_schedule_only_makeup_at_the_same_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-l@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // A makeup Schedule with no session of its own, and the regular 13:00 lesson moved onto its time.
        $this->extraSchedule($course, $teacherId, '15:00', 'scheduled', 'extra');
        $this->createSession($course, '2026-10-13', '15:00', '17:00', 'scheduled');
        $this->extraSchedule($course, $teacherId, '15:00', 'scheduled', 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '13:00']);

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['15:00 scheduled', '18:00 scheduled'], $this->startsOn($course), 'No second 13:00 lesson.');
    }

    public function test_a_reschedule_placeholder_does_not_claim_a_contract_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-m@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        $placeholder = $this->createSession($course, '2026-10-13', '17:00', '19:00', 'cancelled');
        $placeholder->forceFill(['Note' => 'moved; cancelled-duplicate-reschedule-placeholder'])->save();

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['13:00 scheduled', '17:00 cancelled', '18:00 scheduled'], $this->startsOn($course));
    }

    public function test_a_superseded_schedule_lends_no_identity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-n@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // Proven 13:00 lesson moved to 13:30; a superseded repair row at 13:30 claims 18:00.
        $moved = $this->createSession($course, '2026-10-13', '13:30', '15:30', 'leave');
        $moved->forceFill(['OriginalSessionDate' => '2026-10-13', 'OriginalStartTimeHM' => '13:00'])->save();
        $this->extraSchedule($course, $teacherId, '13:30', Schedule::STATUS_SUPERSEDED, 'normal', ['original_schedule_date' => '2026-10-13', 'original_start_time' => '18:00']);

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['13:30 leave', '18:00 scheduled'], $this->startsOn($course));
    }

    public function test_a_long_reschedule_chain_still_reaches_its_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3901-o@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // 18:00 moved 26 times on the same day, ending at 06:30 (live session, no identity).
        $prev = $this->extraSchedule($course, $teacherId, '18:00', 'rescheduled', 'normal');
        for ($i = 1; $i <= 25; $i++) {
            $prev = $this->extraSchedule($course, $teacherId, sprintf('06:%02d', $i), 'rescheduled', 'normal', ['original_schedule_id' => $prev->id]);
        }
        $this->extraSchedule($course, $teacherId, '06:30', 'scheduled', 'normal', ['original_schedule_id' => $prev->id]);
        $this->createSession($course, '2026-10-13', '06:30', '08:30', 'scheduled');

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['06:30 scheduled', '13:00 scheduled'], $this->startsOn($course), 'The 13:00 lesson must not disappear.');
    }

    /** #3970: a chain of exactly MAX_HOPS (40) links still lends the root's slot, like the backfill resolver. */
    public function test_a_reschedule_chain_of_exactly_forty_links_keeps_its_root_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', 'Asia/Taipei'));
        [$teacherId, $teacherToken] = $this->createUserWithToken('T', 'teacher-3970@example.com');
        $course = $this->createMonthlyCourse($teacherId, ['week1' => 2, 'time1' => '13:00']);
        // 18:00 moved 40 times on the same day (39 intermediate rows), ending at 06:30 (live session, no identity).
        $prev = $this->extraSchedule($course, $teacherId, '18:00', 'rescheduled', 'normal');
        for ($i = 1; $i <= 39; $i++) {
            $prev = $this->extraSchedule($course, $teacherId, sprintf('05:%02d', $i), 'rescheduled', 'normal', ['original_schedule_id' => $prev->id]);
        }
        $this->extraSchedule($course, $teacherId, '06:30', 'scheduled', 'normal', ['original_schedule_id' => $prev->id]);
        $this->createSession($course, '2026-10-13', '06:30', '08:30', 'scheduled');

        $this->readTeacherDay($teacherToken);

        $this->assertSame(['06:30 scheduled', '13:00 scheduled'], $this->startsOn($course), 'The 40th hop reaches 18:00; 13:00 must stay.');
    }

    /** @param array<string, mixed> $identity */
    private function extraSchedule(StudentClass $course, int $teacherId, string $start, string $status, string $type, array $identity = []): Schedule
    {
        return Schedule::create($identity + [
            'student_id' => $course->StudentID, 'teacher_id' => $teacherId, 'subject' => 'Math', 'day_of_week' => 2,
            'start_time' => $start, 'end_time' => '20:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => $status, 'type' => $type, 'deduction' => 0, 'branch_id' => 1,
            'schedule_date' => '2026-10-13', 'student_course_id' => $course->ID,
        ]);
    }

    private function readTeacherDay(string $token): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions?start=2026-10-13&end=2026-10-13')
            ->assertOk();
    }

    /** @return list<string> */
    private function startsOn(StudentClass $course): array
    {
        return ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-10-13')
            ->orderBy('StartTime')->orderBy('Status')->get()
            ->map(fn ($s) => substr((string) $s->StartTime, 0, 5).' '.strtolower((string) $s->Status))->all();
    }

    /** @param array<string, mixed> $extra */
    private function createMonthlyCourse(int $teacherId, array $extra = []): StudentClass
    {
        $student = Student::create([
            'name' => '月結請假不遞補', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);

        return StudentClass::create($extra + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-10-01', 'EndDate' => '2026-10-31',
            'TotalHours' => 8, 'Charge' => 4000, 'Paid' => 0, 'Rate' => 1000, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'date', 'SessionCount' => 4, 'SessionDuration' => 120, 'RemainingSessions' => 0,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'monthly_sessions' => 4, 'rate_unit' => 'session',
            'week' => 2, 'time' => '18:00',
        ]);
    }

    private function createSession(StudentClass $course, string $date, string $start, string $end, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => $start, 'EndTime' => $end, 'Status' => $status,
        ]);
    }

    /** @return array{0:int,1:string} */
    private function createUserWithToken(string $type, string $login, int $admin = 0): array
    {
        $user = User::create(['LoginName' => $login, 'Name' => '測試', 'PSW' => 'secret', 'type' => $type,
            'phone' => '0912345678', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => $admin, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDays(30)]);

        return [(int) $user->id, $token];
    }
}

