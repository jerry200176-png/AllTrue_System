<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherEligibilityWeeklySegmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campus_hours_split_one_calendar_month_by_campus_and_count_shared_slots_once(): void // Founder 2026-11-20
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $other = Campus::factory()->create();
        // 一對二: two students in the same slot are one two-hour lesson.
        $this->createAttendedSession($teacher->id, 'one_on_two', '2026-08-03', '10:00', 120);
        $this->createAttendedSession($teacher->id, 'one_on_two', '2026-08-03', '10:00', 120);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', '14:00', 120, 'completed', 'present', $other->id);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-05', '10:00', 120, 'cancelled');
        $this->schedule($teacher->id, '2026-08-31', '08:00', '10:00'); // an extra lesson on the month's last day
        // The neighbouring months stay out, though the weekly report's weeks reach into them.
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-07-31', '10:00', 120);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-09-01', '10:00', 120);

        $response = $this->withHeaders($this->auth($director['token']))->getJson('/api/v1/finance/teacher-eligibility/campus-hours?month=2026-08&branch_id=1')->assertOk();
        $row = collect($response->json('teachers'))->firstWhere('teacher_id', $teacher->id);
        $this->assertEquals(6, $row['total_hours']);
        $this->assertEquals([1 => 4, $other->id => 2], $row['by_campus']);
        $this->assertEqualsCanonicalizing([1, $other->id], array_keys($response->json('campuses')));

        $csv = $this->withHeaders($this->auth($director['token']))->get('/api/v1/finance/teacher-eligibility/campus-hours?month=2026-08&branch_id=1&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('合計時數', $csv);
        $this->assertStringContainsString($teacher->Name . ',4,2,6', $csv);
        $teacher->forceFill(['Name' => '=1+1'])->save();
        $this->assertStringContainsString("'=1+1,4,2,6", $this->withHeaders($this->auth($director['token']))->get('/api/v1/finance/teacher-eligibility/campus-hours?month=2026-08&branch_id=1&format=csv')->streamedContent());
    }

    public function test_campus_hours_list_the_lessons_of_two_campuses_that_share_clock_time(): void // Founder 2026-11-20
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $other = Campus::factory()->create();
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', '10:00', 120);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', '11:00', 120, 'completed', 'present', $other->id);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', '14:00', 120, 'completed', 'present', $other->id); // same day, clear of both
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-04', '10:00', 120);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-04', '12:00', 120, 'completed', 'present', $other->id); // back to back is not an overlap

        $row = collect($this->withHeaders($this->auth($director['token']))->getJson('/api/v1/finance/teacher-eligibility/campus-hours?month=2026-08&branch_id=1')
            ->assertOk()->json('teachers'))->firstWhere('teacher_id', $teacher->id);
        $this->assertEquals(9, $row['total_hours']); // 10–13 once, 14–16, then 10–14
        $this->assertEquals([1 => 4, $other->id => 6], $row['by_campus']);
        $this->assertCount(1, $row['overlaps']);
        $this->assertSame('2026-08-03', $row['overlaps'][0]['date']);
        $this->assertEquals([['10:00', '12:00', 1], ['11:00', '13:00', $other->id]],
            array_map(fn ($lesson) => [$lesson['start'], $lesson['end'], $lesson['campus_id']], $row['overlaps'][0]['lessons']));
        $this->assertGreaterThan(0, $row['overlaps'][0]['lessons'][0]['student_id']);
        $this->assertGreaterThan(0, $row['overlaps'][0]['lessons'][0]['course_id']);
    }

    public function test_director_sees_calendar_backed_weekly_segments(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $otherCampus = Campus::factory()->create();
        UserCampus::create(['CampusID' => $otherCampus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        // Attended sessions feed the subject counts and the payout; 段 come from the schedule below.
        foreach (['08:00', '08:10', '08:20', '08:30', '08:40', '08:50', '09:00', '09:10'] as $start) {
            $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', $start, 120, 'completed', 'present', $otherCampus->id);
        }
        $tuesday = $this->createAttendedSession($teacher->id, 'one_on_two', '2026-08-04', '10:00', 240);
        $this->createAttendedSession($teacher->id, 'one_on_three', '2026-08-05', '10:00', 240);
        foreach (['08:00', '08:10', '08:20', '08:30', '08:40'] as $start) {
            $this->createAttendedSession($teacher->id, 'trial', '2026-08-06', $start, 120, 'trial', 'trial');
        }
        $this->createAttendedSession($teacher->id, 'tutoring', '2026-08-07', '10:00', 120, 'tutoring_attend', 'tutoring');

        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-07', '14:00', 120, 'cancelled', 'present');
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-07', '16:00', 120, 'leave', 'leave');
        $voided = $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-07', '18:00', 120);
        DB::table('StudentSingIn')->where('ClassSessionID', $voided->id)->update([
            'VoidedAt' => now(),
            'VoidReason' => 'fixture',
        ]);

        // 段 come from the calendar: the sessions above (whatever their attendance) plus live schedule rows.
        // Mon 08:00-11:10 (eight overlapping lessons, 190 min) + Tue 240 + Wed 240 + Thu 08:00-10:40 (five
        // overlapping 試聽, 160) + Fri 輔導 120 and 16:00-20:00 (a student's leave and a voided sign-in still count;
        // the cancelled lesson does not, 240) + Sat 600 + Sun 130 = 1920 min = 16 段.
        $this->schedule($teacher->id, '2026-08-08', '08:00', '18:00');
        $this->schedule($teacher->id, '2026-08-08', '08:00', '10:00'); // the other half of a 一對二 pair: no extra 段
        $sundayId = $this->schedule($teacher->id, '2026-08-09', '08:00', '10:10');
        // None of these count: cancelled, moved away, replaced, admin time, another teacher's lesson.
        foreach (['cancelled', 'rescheduled', \App\Models\Schedule::STATUS_SUPERSEDED] as $status) {
            $this->schedule($teacher->id, '2026-08-09', '12:00', '18:00', ['status' => $status]);
        }
        $this->schedule($teacher->id, '2026-08-09', '12:00', '18:00', ['type' => 'admin']);
        $this->schedule($director['user_id'], '2026-08-09', '12:00', '18:00');
        // A leftover row from a second same-day move: the live lesson is at 10:00, so 16:00 is stale.
        $this->schedule($teacher->id, '2026-08-04', '16:00', '18:00', ['student_course_id' => $tuesday->StudentClassID]);

        DB::table('teacher_payroll_cash_adjustments')->insert(['teacher_id' => $teacher->id, 'branch_id' => $otherCampus->id, 'amount' => 1500, 'reason' => '完整結算路徑測試', 'status' => 'approved', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=week&start=2026-08-03&end=2026-08-09&branch_id=1');

        $response->assertOk();
        $response->assertJsonPath('teachers.0.teacher_id', $teacher->id);
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.status', 'qualifies');
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.total_segments', 16);
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.meets_16_segments', true);
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.weeks.0.metrics.daily_hours', [
            '2026-08-03' => 3.17, '2026-08-04' => 4, '2026-08-05' => 4, '2026-08-06' => 2.67,
            '2026-08-07' => 6, '2026-08-08' => 10, '2026-08-09' => 2.17,
        ]);
        // The week explains itself: it is over, and one lesson was called off (Friday 14:00) and not counted.
        $response->assertJsonPath('teachers.0.weekly.0.metrics.finished', true);
        $response->assertJsonPath('teachers.0.weekly.0.metrics.uncounted', ['cancelled' => 1, 'moved' => 0]);
        // 平日下午倍率: Mon 190 + Tue 240 + Wed 240 + Thu 160 + Fri 240 minutes before 18:00 (Friday's 18:00 lesson
        // is past the cutoff) = 8.92 段, capped at 5%.
        $response->assertJsonPath('teachers.0.weekly.0.metrics.weekday_early_segments', 8.92);
        $response->assertJsonPath('teachers.0.components.weekday_afternoon.rate', 5);
        // 假日16小時倍率: Sat 600 + Sun 130 minutes is under 16 hours, so 0%.
        $response->assertJsonPath('teachers.0.weekly.0.metrics.weekend_hours', 12.17);
        $response->assertJsonPath('teachers.0.components.holiday_16_hours.rate', 0);
        // Bonus total, no base salary: 1500 cash + 4000 (the only week in view is full) + 200 一對三獎金 x 105% = 210,
        // lifted to the 新進保底 of 2000: this is the teacher's first month and every week in view is full.
        $response->assertJsonPath('teachers.0.settlement.weighted_bonus_before_guarantee', 210);
        $response->assertJsonPath('teachers.0.settlement.new_hire_guarantee_applied', true);
        $response->assertJsonPath('teachers.0.settlement.calculated_payout', 7500);
        $response->assertJsonPath('teachers.0.settlement.calculation_status', 'calculated');
        $response->assertJsonPath('teachers.0.components.cash_adjustments.amount', 1500);
        $response->assertJsonPath('teachers.0.settlement.adjustments.1.label', '完整結算路徑測試');

        $monthResponse = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1');
        $monthResponse->assertOk();
        // The month has four more weeks and none of them is full: no 16段課獎金. They have no lessons before 18:00
        // either, so the 平日下午 rate averages to (5 + 0 + 0 + 0 + 0) / 5 = 1%: 1500 + 200 x 101%.
        $monthResponse->assertJsonPath('teachers.0.components.weekday_afternoon.rate', 1);
        // ...and a month with a short week has no 新進保底.
        $monthResponse->assertJsonPath('teachers.0.components.new_hire_guarantee.amount', 0);
        $monthResponse->assertJsonPath('teachers.0.settlement.calculated_payout', 1702);
        $monthResponse->assertJsonPath('teachers.0.settlement.calculation_status', 'calculated');
        $monthResponse->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.week_count', 5);
        $monthResponse->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.qualifying_weeks', 1);
        $monthResponse->assertJsonPath('teachers.0.components.weekly_16_segments.amount', 0);

        // Control: without Sunday's lesson the week is under 16 段 and the bonus goes.
        DB::table('schedules')->where('id', $sundayId)->update(['status' => 'cancelled']);
        $short = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=week&start=2026-08-03&end=2026-08-09&branch_id=1')->assertOk();
        $short->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.total_segments', 14.92);
        $short->assertJsonPath('teachers.0.components.weekly_16_segments.status', 'not_qualifies');
        $short->assertJsonPath('teachers.0.settlement.calculated_payout', 1710);
    }

    /** A week crossing a month end is judged whole in its Monday's month; the 4,000 needs every week full. */
    public function test_month_bonus_needs_every_week_full_and_cross_month_week_belongs_to_its_monday(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        // Week of 2026-07-27: 4h x 5 weekdays + 6h on Sat 08-01 and Sun 08-02 = 32h = 16 段, all ordinary
        // ClassSession bookings with no schedules row.
        foreach (['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31'] as $date) {
            $this->createAttendedSession($teacher->id, 'one_on_one', $date, '14:00', 240);
        }
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-01', '09:00', 360);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-02', '09:00', 360);

        $weekly = fn (string $start, string $end) => $this->withHeaders($this->auth($director['token']))
            ->getJson("/api/v1/finance/teacher-eligibility?period=month&start={$start}&end={$end}&branch_id=1")
            ->assertOk()->json('teachers.0.components.weekly_16_segments');

        $july = $weekly('2026-07-01', '2026-07-31');
        $this->assertSame(4, $july['metrics']['week_count']);
        $this->assertSame(1, $july['metrics']['qualifying_weeks']);
        $this->assertSame(0, $july['amount'], 'one full week out of four pays nothing');
        $this->assertSame('2026-07-27', $july['metrics']['weeks'][3]['metrics']['week_start']);
        $this->assertEquals(16, $july['metrics']['weeks'][3]['metrics']['total_segments']);

        $august = $weekly('2026-08-01', '2026-08-31');
        $this->assertSame(0, $august['metrics']['qualifying_weeks']);
        $this->assertSame(0, $august['amount']);
        $this->assertSame('2026-08-03', $august['metrics']['weeks'][0]['metrics']['week_start']);

        // A hand-entered holiday event must not waive a week (one director can create and approve it)...
        DB::table('teacher_payroll_events')->insert(['teacher_id' => null, 'branch_id' => 1, 'event_date' => '2026-08-19', 'event_type' => 'holiday', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        // ...but a day on which every lesson in the company was called off does: that week counts as full.
        foreach (range(1, 10) as $n) {
            $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-12', '10:00', 120, 'cancelled', 'present');
        }
        $closed = $weekly('2026-08-01', '2026-08-31');
        $this->assertSame(1, $closed['metrics']['qualifying_weeks']);
        $this->assertSame(['2026-08-12'], $closed['metrics']['weeks'][1]['metrics']['closure_dates']);
        $this->assertSame([], $closed['metrics']['weeks'][1]['metrics']['near_closures']);
        $this->assertSame([], $closed['metrics']['weeks'][2]['metrics']['closure_dates']);
        // A lesson that still runs that day (a makeup that lives only in `schedules`) means the day was not a closure.
        $makeupId = $this->schedule($teacher->id, '2026-08-12', '13:00', '15:00');
        $open = $weekly('2026-08-01', '2026-08-31');
        $this->assertSame(0, $open['metrics']['qualifying_weeks']);
        $this->assertSame([], $open['metrics']['weeks'][1]['metrics']['closure_dates']);
        // One lesson that still runs among the called-off ones: not a closure, and the week says why it was not waived.
        $ran = $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-12', '16:00', 120);
        $this->assertSame([['date' => '2026-08-12', 'total' => 11, 'open' => 1]], $weekly('2026-08-01', '2026-08-31')['metrics']['weeks'][1]['metrics']['near_closures']);
        DB::table('ClassSession')->where('id', $ran->id)->delete();
        DB::table('schedules')->where('id', $makeupId)->delete();

        // Fill the other three July weeks (4 days x 8h = 16 段 each): now every week is full.
        foreach (['2026-07-06', '2026-07-13', '2026-07-20'] as $monday) {
            foreach (range(0, 3) as $offset) {
                $this->createAttendedSession($teacher->id, 'one_on_one', date('Y-m-d', strtotime("{$monday} +{$offset} days")), '14:00', 480);
            }
        }
        $full = $weekly('2026-07-01', '2026-07-31');
        $this->assertSame(4, $full['metrics']['qualifying_weeks']);
        $this->assertSame(4000, $full['amount']);
    }

    /** A week still running is shown but not judged, and it is not a waived week either. */
    public function test_weeks_still_running_are_not_judged_and_do_not_count_as_waived(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-08-05 12:00:00', 'Asia/Taipei'));
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', '10:00', 120);

        $row = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1')
            ->assertOk()->json('teachers.0');

        $this->assertFalse($row['weekly'][0]['metrics']['finished']);
        $this->assertSame(5, $row['components']['weekly_16_segments']['metrics']['unfinished_weeks']);

        // A running week that is already fully scheduled is still a week that has not finished.
        foreach (range(0, 3) as $offset) {
            $this->createAttendedSession($teacher->id, 'one_on_one', date('Y-m-d', strtotime("2026-08-10 +{$offset} days")), '14:00', 480);
        }
        $row = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1')
            ->assertOk()->json('teachers.0');
        $this->assertTrue($row['weekly'][1]['metrics']['meets_16_segments']);
        $this->assertSame(5, $row['components']['weekly_16_segments']['metrics']['unfinished_weeks']);
        $this->assertSame('還有 5 週沒結束，結束後才判定。', $row['components']['weekly_16_segments']['reason']);
        // No finished week and no closure: nothing to judge the 假日 rate on, so it is 0, not the full 10%.
        $this->assertEquals(0, $row['components']['holiday_16_hours']['rate']);
        $this->assertEquals(0, $row['components']['holiday_16_hours']['metrics']['waived_weeks']);
    }

    /** The totals are company-wide, so a teacher who belongs to two campuses is settled in one of them only. */
    public function test_teacher_in_two_campuses_is_settled_in_one(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $other = Campus::factory()->create();
        UserCampus::create(['CampusID' => $other->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        UserCampus::create(['CampusID' => $other->id, 'UserID' => $director['user_id'], 'Admin' => 1, 'Approved' => 1]);
        // Three students in the same two hours here are two hours of the teacher's time, not six.
        foreach (range(1, 3) as $student) {
            $this->createAttendedSession($teacher->id, 'one_on_three', '2026-08-03', '10:00', 120);
        }
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-04', '10:00', 240, 'completed', 'present', $other->id);
        $view = fn (int $campusId) => $this->withHeaders($this->auth($director['token']))
            ->getJson("/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id={$campusId}")
            ->assertOk()->json();

        // More lesson time in the other campus: it settles the teacher, this one only says where.
        $here = $view(1);
        $this->assertSame([], $here['teachers']);
        $this->assertSame([$teacher->id, $other->id], [$here['settled_elsewhere'][0]['teacher_id'], $here['settled_elsewhere'][0]['campus_id']]);
        $there = $view($other->id);
        $this->assertSame([$teacher->id], array_column($there['teachers'], 'teacher_id'));
        $this->assertSame([], $there['settled_elsewhere']);

        // This campus locks the month without the teacher. Lessons added here afterwards cannot bring the teacher
        // back into a month that is already locked: the other campus still settles the teacher.
        $tryLock = fn (int $campusId) => $this->withHeaders($this->auth($director['token']))
            ->postJson('/api/v1/finance/teacher-eligibility/lock', ['month' => '2026-08', 'branch_id' => $campusId]);
        $lock = fn (int $campusId) => $tryLock($campusId)->assertOk();
        // A record this campus entered for the teacher and has not finished approving would be stranded by its lock.
        $cashId = DB::table('teacher_payroll_cash_adjustments')->insertGetId(['teacher_id' => $teacher->id, 'branch_id' => 1, 'amount' => 500, 'reason' => 'r', 'status' => 'pending', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, $view(1)['settled_elsewhere'][0]['pending_inputs']);
        $tryLock(1)->assertStatus(422);
        DB::table('teacher_payroll_cash_adjustments')->where('id', $cashId)->update(['status' => 'withdrawn']);
        $lock(1);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-05', '10:00', 480);
        $this->assertSame([$teacher->id], array_column($view($other->id)['teachers'], 'teacher_id'));

        // Once the other campus has locked the month with the teacher in it, the teacher stays there.
        $lock($other->id);
        $this->assertSame(1, DB::table('fulltime_payroll_run_teachers')->where('teacher_id', $teacher->id)->count());
        // ...and a record entered for that month through another campus would never be paid, so it is refused.
        DB::table('fulltime_payroll_runs')->where('branch_id', 1)->update(['status' => 'reopened']);
        $this->withHeaders($this->auth($director['token']))->postJson('/api/v1/finance/teacher-eligibility/cash-adjustments', [
            'teacher_id' => $teacher->id, 'branch_id' => 1, 'amount' => 300, 'reason' => '結算分校鎖定後補登', 'starts_on' => '2026-08-01',
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('teacher_payroll_cash_adjustments')->where('reason', '結算分校鎖定後補登')->count());

        // With nothing locked the rule follows the lesson time: now this campus has more.
        DB::table('fulltime_payroll_runs')->update(['status' => 'reopened']);
        $this->assertSame([$teacher->id], array_column($view(1)['teachers'], 'teacher_id'));
        $this->assertSame([], $view($other->id)['teachers']);
    }

    /** A campus link nobody approved must not decide where a teacher is paid. */
    public function test_unapproved_campus_link_does_not_decide_the_settlement_campus(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $other = Campus::factory()->create();
        UserCampus::create(['CampusID' => $other->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 0]);
        UserCampus::create(['CampusID' => $other->id, 'UserID' => $director['user_id'], 'Admin' => 1, 'Approved' => 1]);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', '10:00', 120);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-04', '10:00', 240, 'completed', 'present', $other->id);
        $view = fn (int $campusId) => $this->withHeaders($this->auth($director['token']))
            ->getJson("/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id={$campusId}")
            ->assertOk()->json();

        $this->assertSame([$teacher->id], array_column($view(1)['teachers'], 'teacher_id'));
        $there = $view($other->id);
        $this->assertSame([], $there['teachers']);
        $this->assertSame(1, $there['settled_elsewhere'][0]['campus_id']);

        // With no approved link at all the teacher still lands in exactly one campus (here the one with more lessons).
        DB::table('UserCampus')->where('UserID', $teacher->id)->update(['Approved' => 0]);
        $this->assertSame([], $view(1)['teachers']);
        $this->assertSame([$teacher->id], array_column($view($other->id)['teachers'], 'teacher_id'));

        // Both links approved and more lessons here: this campus settles the teacher...
        DB::table('UserCampus')->where('UserID', $teacher->id)->update(['Approved' => 1]);
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-06', '10:00', 480);
        $this->assertSame([$teacher->id], array_column($view(1)['teachers'], 'teacher_id'));

        // ...unless it has been switched off: such a campus cannot be opened to lock or export, so it settles nobody.
        if (\Illuminate\Support\Facades\Schema::hasColumn('Campus', 'active')) {
            DB::table('Campus')->where('id', 1)->update(['active' => false]);
            $this->assertSame([$teacher->id], array_column($view($other->id)['teachers'], 'teacher_id'));
        }
    }

    /** A legacy campus link with no approval value counts as approved, as everywhere else in the product. */
    public function test_legacy_campus_link_without_an_approval_value_is_approved(): void
    {
        $this->assertTrue(UserCampus::isApproved(null));
        $this->assertTrue(UserCampus::isApproved(1));
        $this->assertFalse(UserCampus::isApproved(0));
    }

    /** 假日16小時倍率: every judged week needs 16 scheduled hours across Saturday and Sunday. */
    public function test_holiday_rate_follows_weekend_hours_on_the_calendar(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-08', '09:00', 480);
        $sunday = $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-09', '09:00', 480);
        $holiday = fn () => $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=week&start=2026-08-03&end=2026-08-09&branch_id=1')
            ->assertOk()->json('teachers.0.components.holiday_16_hours');

        $this->assertEquals(10, $holiday()['rate']);
        $this->assertEquals([16], $holiday()['metrics']['weekly_weekend_hours']);

        // A cancelled Sunday lesson leaves eight hours: the rate drops to 0.
        DB::table('ClassSession')->where('id', $sunday->id)->update(['Status' => 'cancelled']);
        $this->assertEquals(0, $holiday()['rate']);
        $this->assertSame('not_qualifies', $holiday()['status']);
    }

    /** 新進保底 counts from the first lesson signed in under the teacher, whoever the course belongs to now. */
    public function test_new_hire_guarantee_counts_from_the_first_lesson_under_the_teacher(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $row = fn () => $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=week&start=2026-08-03&end=2026-08-09&branch_id=1')
            ->assertOk()->json('teachers.0');

        // No sign-in under the teacher yet: the first lesson is the first one in their own course. A course taken
        // over does not bring the former teacher's lessons along (they are signed in under that teacher).
        $ahead = $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-10', '10:00', 120, 'scheduled');
        DB::table('StudentSingIn')->where('ClassSessionID', $ahead->id)->update(['VoidedAt' => now(), 'VoidReason' => 'fixture']);
        $inherited = $this->createAttendedSession($teacher->id, 'one_on_one', '2026-04-13', '10:00', 120);
        DB::table('StudentSingIn')->where('ClassSessionID', $inherited->id)->update(['TeacherID' => $director['user_id']]);
        $this->assertSame('2026-08-10', $row()['components']['new_hire_guarantee']['metrics']['first_lesson_date']);

        // A lesson signed in under them while covering someone else's course dates the first lesson.
        $covered = $this->createAttendedSession($director['user_id'], 'one_on_one', '2026-08-01', '10:00', 120);
        DB::table('StudentSingIn')->where('ClassSessionID', $covered->id)->update(['TeacherID' => $teacher->id]);
        $this->assertSame('2026-08-01', $row()['components']['new_hire_guarantee']['metrics']['first_lesson_date']);

        foreach (range(0, 3) as $offset) {
            $this->createAttendedSession($teacher->id, 'one_on_one', date('Y-m-d', strtotime("2026-08-03 +{$offset} days")), '14:00', 480);
        }
        $new = $row();
        $this->assertSame('2026-08-01', $new['components']['new_hire_guarantee']['metrics']['first_lesson_date']);
        $this->assertEquals(2000, $new['components']['new_hire_guarantee']['amount']);
        $this->assertTrue($new['settlement']['new_hire_guarantee_applied']);
        $this->assertEquals(2000, $new['settlement']['weighted_bonus_amount']);

        // An older lesson in their own course with no sign-in at all is not proof of anything, and missing records
        // must never make a teacher look newer: it dates the first lesson back (month 6, no guarantee).
        $unsigned = $this->createAttendedSession($teacher->id, 'one_on_one', '2026-03-02', '10:00', 120);
        DB::table('StudentSingIn')->where('ClassSessionID', $unsigned->id)->update(['VoidedAt' => now(), 'VoidReason' => 'fixture']);
        $old = $row();
        $this->assertSame('2026-03-02', $old['components']['new_hire_guarantee']['metrics']['first_lesson_date']);
        $this->assertSame(6, $old['components']['new_hire_guarantee']['metrics']['guarantee_month']);
        $this->assertEquals(0, $old['components']['new_hire_guarantee']['amount']);
        $this->assertFalse($old['settlement']['new_hire_guarantee_applied']);

        // A lesson signed in under them months earlier, in a course that belongs to someone else, counts as well.
        $earlier = $this->createAttendedSession($director['user_id'], 'one_on_one', '2026-02-02', '10:00', 120);
        DB::table('StudentSingIn')->where('ClassSessionID', $earlier->id)->update(['TeacherID' => $teacher->id]);
        $this->assertSame('2026-02-02', $row()['components']['new_hire_guarantee']['metrics']['first_lesson_date']);
    }

    /** A student's leave on a substituted lesson keeps the substitute's hours in the payroll view only. */
    public function test_substitute_keeps_the_lesson_when_the_student_takes_leave(): void
    {
        $substituteId = $this->createDirector()['user_id'];
        $session = $this->createAttendedSession($this->createTeacher()->id, 'one_on_one', '2026-08-03', '10:00', 120, 'leave', 'leave');
        $this->schedule($substituteId, '2026-08-03', '10:00', '12:00', ['student_course_id' => $session->StudentClassID, 'original_schedule_id' => 1]);

        $payroll = \App\Services\TeacherClassCalendar::load('2026-08-03', '2026-08-03', null, null, true);
        $this->assertSame([$substituteId], array_keys($payroll));
        $this->assertSame('12:00', $payroll[$substituteId]['2026-08-03'][0]['end']);
        $this->assertSame([], \App\Services\TeacherClassCalendar::load('2026-08-03', '2026-08-03', null));
    }

    public function test_same_teacher_payroll_subject_count_is_global_when_switching_campus(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $otherCampus = Campus::factory()->create();
        UserCampus::create(['CampusID' => $otherCampus->id, 'UserID' => $director['user_id'], 'Admin' => 1, 'Approved' => 1]);
        UserCampus::create(['CampusID' => $otherCampus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        // Campus 1 contributes 40.5 raw units and campus B contributes 6.
        // The payroll row must use the company-level 46.5 / 8 in either view.
        foreach (range(1, 9) as $day) {
            $this->createAttendedSession($teacher->id, 'one_on_one', sprintf('2026-08-%02d', $day), '08:00', 180, 'completed', 'present', 1);
        }
        foreach ([20, 21] as $day) {
            $this->createAttendedSession($teacher->id, 'one_on_one', sprintf('2026-08-%02d', $day), '08:00', 120, 'completed', 'present', $otherCampus->id);
        }

        $view = fn (int $campusId) => $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=' . $campusId)
            ->assertOk();

        // Campus 1 has more of the teacher's lesson time, so it settles the teacher, with the company-wide count.
        $settling = $view(1);
        $this->assertEqualsWithDelta(46.5, $settling->json('teachers.0.settlement.regular_subject_count'), 0.0001);
        $this->assertEqualsWithDelta(5.8125, $settling->json('teachers.0.settlement.payroll_subject_count'), 0.0001);
        $this->assertEqualsWithDelta(5.8125, $settling->json('branch_subject_total'), 0.0001);

        // The other campus does not list the teacher again; it only says where the teacher is settled.
        $other = $view($otherCampus->id);
        $this->assertSame([], $other->json('teachers'));
        $this->assertSame(1, $other->json('settled_elsewhere.0.campus_id'));
        $this->assertEqualsWithDelta(0, $other->json('branch_subject_total'), 0.0001);
    }

    /** End to end: the "20科 +5%" reaches the settlement from 正課 alone, never from 輔導／試聽. */
    public function test_twenty_subject_rate_follows_regular_subjects_only(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();
        $settlement = fn () => $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1')
            ->assertOk()->json('teachers.0.settlement');
        $rate = fn (array $row) => collect($row['multiplier_parts'])->firstWhere('key', 'subject_count_threshold')['pct'];

        // 10 x 10h one-on-one = 150 raw = 18.75 正課; 3 x 10h tutoring = 15 raw lifts 核薪總 to 20.625.
        foreach (range(10, 19) as $day) {
            $this->createAttendedSession($teacher->id, 'one_on_one', "2026-08-{$day}", '08:00', 600);
        }
        foreach ([21, 22, 23] as $day) {
            $this->createAttendedSession($teacher->id, 'tutoring', "2026-08-{$day}", '08:00', 600, 'tutoring_attend', 'tutoring');
        }
        $before = $settlement();
        $this->assertEqualsWithDelta(150, $before['regular_subject_count'], 0.0001);
        $this->assertEqualsWithDelta(20.625, $before['payroll_subject_count'], 0.0001);
        $this->assertEqualsWithDelta(0, $rate($before), 0.0001);

        // One more 10h one-on-one: 正課 = 165 raw = 20.625.
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-20', '08:00', 600);
        $this->assertEqualsWithDelta(5, $rate($settlement()), 0.0001);
    }

    private function createDirector(): array
    {
        $user = User::create([
            'LoginName' => 'weekly-director@test.com', 'Name' => '薪資主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['user_id' => (int) $user->id, 'token' => $token];
    }

    private function createTeacher(): User
    {
        $teacher = User::create([
            'LoginName' => 'weekly-teacher@test.com', 'Name' => '每週段數老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000', 'MustChangePassword' => false,
            'employment_type' => 'full_time',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return $teacher;
    }

    private function createAttendedSession(
        int $teacherId,
        string $classType,
        string $date,
        string $start,
        int $minutes,
        string $sessionStatus = 'completed',
        string $attendanceStatus = 'present',
        int $campusId = 1
    ): ClassSession {
        $student = Student::create([
            'name' => '段數案例學生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId,
            'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => $classType, 'by1' => 1,
            'ScheduleMode' => 'count', 'SessionCount' => 20, 'RemainingSessions' => 20,
            'Rate' => 500, 'Charge' => 5000, 'Paid' => 0, 'Period' => 4,
            'SessionDuration' => $minutes, 'TotalHours' => 40,
            'StartDate' => '2026-08-01', 'EndDate' => '2026-08-31',
            'RoomID' => '', 'MDate' => now(),
        ]);
        $end = date('H:i', strtotime($start . ':00 +' . $minutes . ' minutes'));
        $session = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => $start . ':00', 'EndTime' => $end . ':00',
            'Status' => $sessionStatus, 'Note' => '',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => $student->id,
            'TeacherID' => $teacherId, 'ClassSessionID' => $session->id,
            'Status' => $attendanceStatus, 'SignInDT' => $date . ' ' . $start . ':00',
            'SignOutDT' => $date . ' ' . $end . ':00',
        ]);
        return $session;
    }

    private function schedule(int $teacherId, string $date, string $start, string $end, array $extra = []): int
    {
        return (int) DB::table('schedules')->insertGetId($extra + [
            'student_id' => 1, 'day_of_week' => (int) date('N', strtotime($date)), 'type' => 'normal', 'deduction' => 1,
            'branch_id' => 1, 'schedule_date' => $date, 'teacher_id' => $teacherId, 'status' => 'scheduled',
            'start_time' => $start . ':00', 'end_time' => $end . ':00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
