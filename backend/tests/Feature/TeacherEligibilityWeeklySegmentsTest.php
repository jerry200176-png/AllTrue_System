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

        DB::table('fulltime_salary_profiles')->insert([
            'teacher_id' => $teacher->id,
            'branch_id' => $otherCampus->id,
            'base_salary' => 33000,
            'status' => 'approved',
            'effective_from' => '2026-08-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        // 33000 base + 1500 cash + 4000 (the only week in view is full) + 200 一對三獎金.
        $response->assertJsonPath('teachers.0.settlement.calculated_payout', 38700);
        $response->assertJsonPath('teachers.0.settlement.calculation_status', 'calculated');
        $response->assertJsonPath('teachers.0.components.cash_adjustments.amount', 1500);
        $response->assertJsonPath('teachers.0.settlement.adjustments.1.label', '完整結算路徑測試');

        $monthResponse = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1');
        $monthResponse->assertOk();
        // The month has four more weeks and none of them is full: no 16段課獎金.
        $monthResponse->assertJsonPath('teachers.0.settlement.calculated_payout', 34700);
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
        $short->assertJsonPath('teachers.0.settlement.calculated_payout', 34700);
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

        foreach ([1, $otherCampus->id] as $campusId) {
            $response = $this->withHeaders($this->auth($director['token']))
                ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=' . $campusId)
                ->assertOk();
            $this->assertEqualsWithDelta(46.5, $response->json('teachers.0.settlement.regular_subject_count'), 0.0001);
            $this->assertEqualsWithDelta(5.8125, $response->json('teachers.0.settlement.payroll_subject_count'), 0.0001);
            $this->assertEqualsWithDelta(5.8125, $response->json('branch_subject_total'), 0.0001);
        }
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
