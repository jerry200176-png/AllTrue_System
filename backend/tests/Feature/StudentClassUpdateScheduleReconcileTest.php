<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassUpdateScheduleReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // seedCourseWithHistory() 把「未來」sessions 放在 2026-04-19 起，
        // 若執行日期已經跨過 4/19，那筆 session 會被 sync 視為過去而不更新，
        // 導致 scheduled 集合裡仍出現舊時間。統一凍結到 4/12。
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * When immutable history exists and future sessions ARE scheduled,
     * syncFutureScheduledSessionTimes should update them, and reconcile
     * should run normally (times match the new value).
     */
    public function test_update_time_slot_syncs_future_scheduled_sessions(): void
    {
        [$token, $student, $course] = $this->seedCourseWithHistory();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'days_of_week' => [7],
            'start_time' => '13:00',
            'day_time_slots' => [['day' => 7, 'start_time' => '13:00']],
            'sessions_purchased' => 8,
            'remaining_sessions' => 4,
            'payment_type' => 'session',
        ]);

        $res->assertOk();
        $sync = $res->json('session_sync');
        $this->assertSame('history_exists', $sync['reason'] ?? '');
        $this->assertGreaterThan(0, (int) ($sync['updated_future_sessions'] ?? 0));
        $this->assertArrayNotHasKey('reconcile_skipped', $sync);

        $course->refresh();
        $this->assertSame('13:00:00', (string) $course->time);

        $futureSessions = ClassSession::where('StudentClassID', $course->ID)
            ->where('Status', 'scheduled')
            ->get();
        foreach ($futureSessions as $session) {
            $this->assertStringStartsWith('13:00', (string) $session->StartTime);
        }
    }

    /**
     * When immutable history exists and ALL future sessions are locked
     * (non-scheduled status), syncFuture updates 0 rows. The fix must
     * NOT let reconcile overwrite the user's new time with the old
     * ClassSession times.
     */
    public function test_update_time_slot_skips_reconcile_when_no_future_sessions_updated(): void
    {
        [$token, $student, $course] = $this->seedCourseWithHistory(allFutureCompleted: true);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'days_of_week' => [7],
            'start_time' => '13:00',
            'day_time_slots' => [['day' => 7, 'start_time' => '13:00']],
            'payment_type' => 'session',
        ]);

        $res->assertOk();
        $sync = $res->json('session_sync');
        $this->assertSame('history_exists', $sync['reason'] ?? '');
        $this->assertSame(0, (int) ($sync['updated_future_sessions'] ?? -1));
        $this->assertTrue($sync['reconcile_skipped'] ?? false);
        $this->assertNotEmpty($sync['warning'] ?? '');

        $course->refresh();
        $this->assertSame('13:00:00', (string) $course->time,
            'StudentClass.time must keep the user-submitted value, not be overwritten by old ClassSession times');
    }

    /**
     * Real-world scenario: frontend always sends first_class_date, which puts
     * StartDate into $mapped.  When start date is unchanged but time changed,
     * syncFutureScheduledSessionTimes must still run.
     */
    public function test_update_time_with_first_class_date_syncs_future_sessions(): void
    {
        [$token, $student, $course] = $this->seedCourseWithHistory();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'days_of_week' => [7],
            'start_time' => '17:00',
            'day_time_slots' => [['day' => 7, 'start_time' => '17:00']],
            'sessions_purchased' => 8,
            'remaining_sessions' => 4,
            'payment_type' => 'session',
            'first_class_date' => '2026-03-01',
        ]);

        $res->assertOk();
        $sync = $res->json('session_sync');
        $this->assertSame('history_exists', $sync['reason'] ?? '');
        $this->assertGreaterThan(0, (int) ($sync['updated_future_sessions'] ?? 0),
            'Future scheduled sessions must be updated even when first_class_date is present but unchanged');
        $this->assertArrayNotHasKey('reconcile_skipped', $sync);

        $course->refresh();
        $this->assertSame('17:00:00', (string) $course->time,
            'StudentClass.time must reflect the new 17:00 value, not be overwritten by old 15:00');

        $futureSessions = ClassSession::where('StudentClassID', $course->ID)
            ->where('Status', 'scheduled')
            ->get();
        foreach ($futureSessions as $session) {
            $this->assertStringStartsWith('17:00', (string) $session->StartTime);
            $this->assertStringStartsWith('19:00', (string) $session->EndTime);
        }
    }

    /**
     * When all sessions are in the past (no future scheduled), reconcile must NOT
     * rebuild week/time from attended/completed history — otherwise a one-off
     * Tuesday substitute pollutes the contract after the user removes Tuesday.
     */
    public function test_update_removed_weekday_not_restored_from_history_when_no_future_scheduled(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name'       => '移除週二測試',
                'CampusID'   => 1,
                'ClassID'    => 1,
                'enable'     => 1,
                'MDT'        => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID'   => $student->id,
                'GradeID'     => 1,
                'SubjectID'   => 1,
                'TeacherID'   => 99,
                'by1'         => 1,
                'Period'      => 4,
                'StartDate'   => '2026-03-01',
                'TotalHours'  => 20,
                'Charge'      => 0,
                'Paid'        => 0,
                'Rate'        => 500,
                'MDate'       => now(),
                'Stop'        => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 180,
                'RemainingSessions' => 0,
                'UsedSessions'      => 8,
                'ClassType'     => 'one_on_one',
                'week'          => 1,
                'time'          => '16:30:00',
                'week1'         => 2,
                'time1'         => '16:30:00',
                'duration1'     => 120,
                'week2'         => 4,
                'time2'         => '16:30:00',
            ]);

            $tueSession = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate'    => '2026-04-07',
                'StartTime'      => '16:30:00',
                'EndTime'        => '18:30:00',
                'Status'         => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID'      => $student->id,
                'TeacherID'      => 99,
                'GradeID'        => 1,
                'SubjectID'      => 1,
                'CampusID'       => 1,
                'SignInDT'       => '2026-04-07 16:30:00',
                'MDT'            => now(),
                'ClassSessionID' => $tueSession->id,
                'Status'         => 'present',
                'SessionDeducted' => 1,
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject'            => 'Math',
                'class_type'         => 'one_on_one',
                'duration_hours'     => 3,
                'sessions_purchased' => 8,
                'remaining_sessions' => 0,
                'payment_type'       => 'session',
                'days_of_week'       => [1, 4],
                'start_time'         => '16:30',
                'day_time_slots'     => [
                    ['day' => 1, 'start_time' => '16:30', 'duration_minutes' => 180],
                    ['day' => 4, 'start_time' => '16:30', 'duration_minutes' => 180],
                ],
                'first_class_date'   => '2026-03-01',
            ]);

            $res->assertOk();

            $course->refresh();
            $this->assertSame(1, (int) $course->week);
            $this->assertSame(4, (int) $course->week1, 'Second contract slot must be Thursday, not Tuesday from history');
            $this->assertNull($course->week2);
            $this->assertNull($course->time2);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * When no schedule fields change (e.g. only Memo), reconcile should
     * still run normally regardless of history.
     */
    public function test_non_schedule_update_still_reconciles(): void
    {
        [$token, $student, $course] = $this->seedCourseWithHistory();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'Memo' => 'test memo only',
        ]);

        $res->assertOk();
        $sync = $res->json('session_sync');
        $this->assertArrayNotHasKey('reconcile_skipped', $sync);

        $course->refresh();
        $this->assertSame('15:00:00', (string) $course->time,
            'When no schedule fields change, reconcile should keep times from ClassSession');
    }

    /**
     * Memo-only update on a course with no future scheduled sessions must not
     * let reconcile rebuild week/time from past attended rows on other weekdays.
     */
    public function test_memo_only_update_keeps_contract_when_no_future_scheduled(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name'         => '備註不汙染契約',
                'CampusID'     => 1,
                'ClassID'      => 1,
                'enable'       => 1,
                'MDT'          => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID'        => $student->id,
                'GradeID'          => 1,
                'SubjectID'        => 1,
                'TeacherID'        => 99,
                'by1'              => 1,
                'Period'           => 4,
                'StartDate'        => '2026-03-01',
                'TotalHours'       => 20,
                'Charge'           => 0,
                'Paid'             => 0,
                'Rate'             => 500,
                'MDate'            => now(),
                'Stop'             => 0,
                'ScheduleMode'     => 'count',
                'SessionCount'     => 8,
                'SessionDuration'  => 180,
                'RemainingSessions' => 0,
                'UsedSessions'     => 8,
                'ClassType'        => 'one_on_one',
                'week'             => 1,
                'time'             => '16:30:00',
                'week1'            => 4,
                'time1'            => '16:30:00',
            ]);

            $tue = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate'    => '2026-04-07',
                'StartTime'      => '16:30:00',
                'EndTime'        => '18:30:00',
                'Status'         => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID'      => $student->id,
                'TeacherID'      => 99,
                'GradeID'        => 1,
                'SubjectID'      => 1,
                'CampusID'       => 1,
                'SignInDT'       => '2026-04-07 16:30:00',
                'MDT'            => now(),
                'ClassSessionID' => $tue->id,
                'Status'         => 'present',
                'SessionDeducted' => 1,
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'Memo' => '僅改備註',
            ]);

            $res->assertOk();

            $course->refresh();
            $this->assertSame(1, (int) $course->week);
            $this->assertSame(4, (int) $course->week1, 'Contract must stay Mon+Thu; past Tuesday substitute must not overwrite via reconcile');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 契約從「週六」改為「週日」時，未來預排若仍留在週六，必須整串改到週日（不可只改主檔文字）。
     */
    public function test_update_weekday_remaps_future_sessions_from_saturday_to_sunday(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '改星期測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-01',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 4,
                'UsedSessions' => 4,
                'ClassType' => 'one_on_one',
                'week' => 6,
                'time' => '13:00:00',
            ]);

            $pastSession = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-03-14',
                'StartTime' => '13:00:00',
                'EndTime' => '15:00:00',
                'Status' => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID' => $student->id,
                'TeacherID' => 99,
                'GradeID' => 1,
                'SubjectID' => 1,
                'CampusID' => 1,
                'SignInDT' => '2026-03-14 13:00:00',
                'MDT' => now(),
                'ClassSessionID' => $pastSession->id,
                'Status' => 'present',
                'SessionDeducted' => 1,
            ]);

            foreach (['2026-04-18', '2026-04-25', '2026-05-02', '2026-05-09'] as $date) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '13:00:00',
                    'EndTime' => '15:00:00',
                    'Status' => 'scheduled',
                ]);
            }

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'duration_hours' => 2,
                'days_of_week' => [7],
                'start_time' => '10:00',
                'day_time_slots' => [['day' => 7, 'start_time' => '10:00']],
                'payment_type' => 'session',
            ]);

            $res->assertOk();
            $sync = $res->json('session_sync');
            $this->assertGreaterThan(0, (int) ($sync['updated_future_sessions'] ?? 0));

            $future = ClassSession::where('StudentClassID', $course->ID)
                ->where('Status', 'scheduled')
                ->orderBy('SessionDate')
                ->get();
            $this->assertCount(4, $future);
            $expected = ['2026-04-19', '2026-04-26', '2026-05-03', '2026-05-10'];
            foreach ($future as $i => $session) {
                $this->assertSame($expected[$i], substr((string) $session->SessionDate, 0, 10));
                $this->assertSame(7, (int) Carbon::parse($session->SessionDate)->dayOfWeekIso);
                $this->assertStringStartsWith('10:00', (string) $session->StartTime);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_update_schedule_backfills_missing_slot_for_selected_weekday(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '選星期補齊測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-04-01',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 8,
                'ClassType' => 'one_on_one',
                'week' => 3,
                'time' => '16:00:00',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'duration_hours' => 2,
                'days_of_week' => [3, 7],
                'start_time' => '16:00',
                // Regression: frontend can temporarily have only the original slot.
                'day_time_slots' => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120],
                ],
                'payment_type' => 'session',
            ]);

            $res->assertOk();

            $course->refresh();
            $this->assertSame(3, (int) $course->week);
            $this->assertSame('16:00:00', (string) $course->time);
            $this->assertSame(7, (int) $course->week1, 'Selected Sunday must be persisted even when day_time_slots omitted it');
            $this->assertSame('16:00:00', (string) $course->time1);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Adding a new weekday (Mon+Tue → Mon+Tue+Thu) must trigger remap so
     * existing future sessions are redistributed onto the new cadence.
     */
    public function test_adding_weekday_remaps_future_sessions_to_new_cadence(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '加星期測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-16',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 1500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 11,
                'SessionDuration' => 120,
                'RemainingSessions' => 5,
                'UsedSessions' => 6,
                'ClassType' => 'one_on_two',
                'week' => 1,
                'time' => '16:30:00',
                'week1' => 2,
                'time1' => '16:30:00',
            ]);

            $pastSession = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-03-16',
                'StartTime' => '16:30:00',
                'EndTime' => '18:30:00',
                'Status' => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID' => $student->id,
                'TeacherID' => 99,
                'GradeID' => 1,
                'SubjectID' => 1,
                'CampusID' => 1,
                'SignInDT' => '2026-03-16 16:30:00',
                'MDT' => now(),
                'ClassSessionID' => $pastSession->id,
                'Status' => 'present',
                'SessionDeducted' => 1,
            ]);

            foreach ([
                ['2026-04-13', 1], // Mon
                ['2026-04-14', 2], // Tue
                ['2026-04-20', 1], // Mon
                ['2026-04-21', 2], // Tue
                ['2026-04-27', 1], // Mon
            ] as [$date, $dow]) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '16:30:00',
                    'EndTime' => '18:30:00',
                    'Status' => 'scheduled',
                ]);
            }

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'Math',
                'class_type' => 'one_on_two',
                'duration_hours' => 2,
                'days_of_week' => [1, 2, 4],
                'start_time' => '16:30',
                'day_time_slots' => [
                    ['day' => 1, 'start_time' => '16:30'],
                    ['day' => 2, 'start_time' => '16:30'],
                    ['day' => 4, 'start_time' => '16:30'],
                ],
                'payment_type' => 'session',
            ]);

            $res->assertOk();
            $sync = $res->json('session_sync');
            $this->assertGreaterThan(0, (int) ($sync['updated_future_sessions'] ?? 0),
                'Sessions must be remapped when a new weekday is added to the contract');

            $course->refresh();
            $this->assertSame(1, (int) $course->week, 'Contract must have Monday');
            $this->assertSame(2, (int) $course->week1, 'Contract must have Tuesday');
            $this->assertSame(4, (int) $course->week2, 'Contract must have Thursday');

            $future = ClassSession::where('StudentClassID', $course->ID)
                ->where('Status', 'scheduled')
                ->orderBy('SessionDate')
                ->get();

            $futureWeekdays = $future->map(fn ($s) => (int) Carbon::parse($s->SessionDate)->dayOfWeekIso)->unique()->sort()->values()->all();
            $this->assertContains(4, $futureWeekdays, 'Future sessions must include Thursday after remap');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Editing a course from one weekly slot to same-day multi-slot should
     * redistribute future sessions across the submitted fixed schedule.
     */
    public function test_adding_same_day_time_slot_remaps_future_sessions_to_multi_slot_cadence(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '同日多時段測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-14',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 1500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 4,
                'UsedSessions' => 4,
                'ClassType' => 'one_on_one',
                'week' => 6,
                'time' => '13:00:00',
            ]);

            $pastSession = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-03-14',
                'StartTime' => '13:00:00',
                'EndTime' => '15:00:00',
                'Status' => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID' => $student->id,
                'TeacherID' => 99,
                'GradeID' => 1,
                'SubjectID' => 1,
                'CampusID' => 1,
                'SignInDT' => '2026-03-14 13:00:00',
                'MDT' => now(),
                'ClassSessionID' => $pastSession->id,
                'Status' => 'present',
                'SessionDeducted' => 1,
            ]);

            foreach (['2026-04-18', '2026-04-25', '2026-05-02', '2026-05-09'] as $date) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '13:00:00',
                    'EndTime' => '15:00:00',
                    'Status' => 'scheduled',
                ]);
            }

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'Math',
                'teacher_id' => 100,
                'class_type' => 'one_on_one',
                'duration_hours' => 2,
                'days_of_week' => [6],
                'start_time' => '13:00',
                'day_time_slots' => [
                    ['day' => 6, 'start_time' => '13:00'],
                    ['day' => 6, 'start_time' => '17:00'],
                ],
                'payment_type' => 'session',
            ]);

            $res->assertOk();
            $sync = $res->json('session_sync');
            $this->assertGreaterThan(0, (int) ($sync['updated_future_sessions'] ?? 0),
                'Adding a second same-day slot must remap future sessions, not leave one slot per week');

            $course->refresh();
            $this->assertSame(100, (int) $course->TeacherID, 'Editing course teacher should update the contract teacher');
            $this->assertSame(6, (int) $course->week);
            $this->assertSame('13:00:00', (string) $course->time);
            $this->assertSame(6, (int) $course->week1);
            $this->assertSame('17:00:00', (string) $course->time1);

            $future = ClassSession::where('StudentClassID', $course->ID)
                ->where('Status', 'scheduled')
                ->orderBy('SessionDate')
                ->orderBy('StartTime')
                ->get();

            $this->assertCount(4, $future);
            $byDate = $future->groupBy(fn ($s) => Carbon::parse($s->SessionDate)->toDateString());
            $this->assertSame(['13:00', '17:00'], $byDate->first()->pluck('StartTime')->map(fn ($t) => substr((string) $t, 0, 5))->values()->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_update_weekday_remap_from_sunday_to_saturday_keeps_immediate_next_saturday(): void
    {
        Carbon::setTestNow('2026-05-08 10:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '常態改週六不跳過測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-01',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 4,
                'SessionDuration' => 120,
                'RemainingSessions' => 2,
                'UsedSessions' => 2,
                'ClassType' => 'one_on_one',
                'week' => 7,
                'time' => '18:30:00',
            ]);

            foreach (['2026-05-10', '2026-05-17'] as $date) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '18:30:00',
                    'EndTime' => '20:30:00',
                    'Status' => 'scheduled',
                ]);
            }

            $past = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-05-03',
                'StartTime' => '18:30:00',
                'EndTime' => '20:30:00',
                'Status' => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID' => $student->id,
                'TeacherID' => 99,
                'GradeID' => 1,
                'SubjectID' => 1,
                'CampusID' => 1,
                'SignInDT' => '2026-05-03 18:30:00',
                'MDT' => now(),
                'ClassSessionID' => $past->id,
                'Status' => 'present',
                'SessionDeducted' => 1,
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'duration_hours' => 2,
                'days_of_week' => [6],
                'start_time' => '18:30',
                'day_time_slots' => [['day' => 6, 'start_time' => '18:30']],
                'payment_type' => 'session',
            ]);

            $res->assertOk();

            $future = ClassSession::where('StudentClassID', $course->ID)
                ->where('Status', 'scheduled')
                ->orderBy('SessionDate')
                ->get();
            $this->assertCount(2, $future);
            $this->assertSame('2026-05-09', substr((string) $future[0]->SessionDate, 0, 10));
            $this->assertSame('2026-05-16', substr((string) $future[1]->SessionDate, 0, 10));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_teacher_change_syncs_future_schedule_teacher_and_clears_stale_substitute_rows(): void
    {
        Carbon::setTestNow('2026-05-08 10:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '換老師同步測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 91,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-01',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 4,
                'UsedSessions' => 4,
                'ClassType' => 'one_on_one',
                'week' => 6,
                'time' => '15:00:00',
            ]);

            $futureSession = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-05-10',
                'StartTime' => '15:00:00',
                'EndTime' => '17:00:00',
                'Status' => 'scheduled',
            ]);

            // Base scheduled row still on old teacher.
            Schedule::create([
                'student_id' => $student->id,
                'teacher_id' => 91,
                'day_of_week' => 7,
                'start_time' => '15:00',
                'end_time' => '17:00',
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-05-10',
                'student_course_id' => $course->ID,
            ]);

            // Stale substitute chain that points to old teacher and should be cleared.
            $anchor = Schedule::create([
                'student_id' => $student->id,
                'teacher_id' => 92,
                'day_of_week' => 7,
                'start_time' => '15:00',
                'end_time' => '17:00',
                'status' => 'rescheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-05-10',
                'student_course_id' => $course->ID,
            ]);
            Schedule::create([
                'student_id' => $student->id,
                'teacher_id' => 91,
                'day_of_week' => 7,
                'start_time' => '15:00',
                'end_time' => '17:00',
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-05-10',
                'student_course_id' => $course->ID,
                'original_schedule_id' => $anchor->id,
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'teacher_id' => 92,
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'duration_hours' => 2,
                'days_of_week' => [6],
                'start_time' => '15:00',
                'day_time_slots' => [['day' => 6, 'start_time' => '15:00']],
                'payment_type' => 'session',
            ]);

            $res->assertOk();

            $baseRows = Schedule::where('student_course_id', $course->ID)
                ->where('status', 'scheduled')
                ->whereDate('schedule_date', '2026-05-10')
                ->whereNull('original_schedule_id')
                ->get();
            $this->assertNotEmpty($baseRows);
            $this->assertSame([92], $baseRows->pluck('teacher_id')->unique()->values()->all());

            $this->assertDatabaseMissing('schedules', [
                'student_course_id' => $course->ID,
                'status' => 'scheduled',
                'original_schedule_id' => $anchor->id,
            ]);
            $this->assertDatabaseMissing('schedules', [
                'id' => $anchor->id,
                'status' => 'rescheduled',
            ]);

            $course->refresh();
            $this->assertSame(92, (int) $course->TeacherID);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * force_partial_rebuild must NOT let reconcile overwrite contract when
     * all future sessions are locked (completed) and sync returns 0.
     */
    public function test_force_partial_rebuild_preserves_contract_when_zero_synced(): void
    {
        [$token, $student, $course] = $this->seedCourseWithHistory(allFutureCompleted: true);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'days_of_week' => [7],
            'start_time' => '13:00',
            'day_time_slots' => [['day' => 7, 'start_time' => '13:00']],
            'payment_type' => 'session',
        ]);

        $res->assertOk();

        $course->refresh();
        $this->assertSame('13:00:00', (string) $course->time,
            'Contract time must be 13:00 after first PUT');

        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'force_partial_rebuild' => true,
        ]);

        $res2->assertOk();
        $sync2 = $res2->json('session_sync');
        $this->assertTrue($sync2['reconcile_skipped'] ?? false,
            'Reconcile must be skipped when force_partial_rebuild syncs 0 sessions');

        $course->refresh();
        $this->assertSame('13:00:00', (string) $course->time,
            'Contract time must NOT be overwritten by old ClassSession times via reconcile');
    }

    public function test_schedule_update_preserves_new_sunday_when_start_date_mismatch_has_history(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '週日新增不覆蓋測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-04-01',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 4,
                'UsedSessions' => 4,
                'ClassType' => 'one_on_one',
                'week' => 3,
                'time' => '16:00:00',
            ]);

            $pastSession = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-04-02',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID' => $student->id,
                'TeacherID' => 99,
                'GradeID' => 1,
                'SubjectID' => 1,
                'CampusID' => 1,
                'SignInDT' => '2026-04-02 16:00:00',
                'MDT' => now(),
                'ClassSessionID' => $pastSession->id,
                'Status' => 'present',
                'SessionDeducted' => 1,
            ]);

            // Future rows still only have Wednesday. Reconcile must not treat
            // those stale rows as the source of truth after the director adds Sunday.
            foreach (['2026-04-15', '2026-04-22', '2026-04-29'] as $date) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '16:00:00',
                    'EndTime' => '18:00:00',
                    'Status' => 'scheduled',
                ]);
            }

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'duration_hours' => 2,
                'days_of_week' => [3, 7],
                'start_time' => '16:00',
                'day_time_slots' => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120],
                    ['day' => 7, 'start_time' => '16:00', 'duration_minutes' => 120],
                ],
                'payment_type' => 'session',
                'first_class_date' => '2026-04-01',
                'force_rebuild_if_mismatch' => true,
            ]);

            $res->assertOk();

            $course->refresh();
            $this->assertSame(3, (int) $course->week);
            $this->assertSame('16:00:00', (string) $course->time);
            $this->assertSame(7, (int) $course->week1,
                'Explicitly added Sunday must remain in the contract even when stale future sessions only contain Wednesday');
            $this->assertSame('16:00:00', (string) $course->time1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_teacher_change_preserves_cross_day_fixed_slots_even_with_single_future_session(): void
    {
        Carbon::setTestNow('2026-04-12 12:00:00');
        try {
            $token = $this->createDirectorToken([1]);

            $student = Student::create([
                'name' => '改老師保留週日測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-04-01',
                'TotalHours' => 20,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 1,
                'UsedSessions' => 7,
                'ClassType' => 'one_on_one',
                'week' => 3,
                'time' => '16:00:00',
                'week1' => 7,
                'time1' => '16:00:00',
            ]);

            $pastSession = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-04-02',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID' => $student->id,
                'TeacherID' => 99,
                'GradeID' => 1,
                'SubjectID' => 1,
                'CampusID' => 1,
                'SignInDT' => '2026-04-02 16:00:00',
                'MDT' => now(),
                'ClassSessionID' => $pastSession->id,
                'Status' => 'present',
                'SessionDeducted' => 1,
            ]);

            // Real-world failure: only one stale future Wednesday remains, so
            // reverse-reconciling from ClassSession would erase the Sunday contract.
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-04-15',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'Math',
                'teacher_id' => 100,
                'class_type' => 'one_on_one',
                'duration_hours' => 2,
                'days_of_week' => [3, 7],
                'start_time' => '16:00',
                'day_time_slots' => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120],
                    ['day' => 7, 'start_time' => '16:00', 'duration_minutes' => 120],
                ],
                'payment_type' => 'session',
                'first_class_date' => '2026-04-01',
                'force_rebuild_if_mismatch' => true,
            ]);

            $res->assertOk();

            $course->refresh();
            $this->assertSame(100, (int) $course->TeacherID, 'Teacher edit must still update the contract teacher');
            $this->assertSame(3, (int) $course->week);
            $this->assertSame(7, (int) $course->week1,
                'Changing the formal teacher must not let stale Wednesday-only ClassSession rows erase Sunday');
            $this->assertSame('16:00:00', (string) $course->time1);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: string, 1: Student, 2: StudentClass}
     */
    private function seedCourseWithHistory(bool $allFutureCompleted = false): array
    {
        $token = $this->createDirectorToken([1]);

        $student = Student::create([
            'name' => '時段測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-03-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 4,
            'ClassType' => 'one_on_one',
            'week' => 7,
            'time' => '15:00:00',
        ]);

        // Past session (attended) + sign-in to create immutable history
        $pastSession = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-03-08',
            'StartTime' => '15:00:00',
            'EndTime' => '17:00:00',
            'Status' => 'attended',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $course->ID,
            'StudentID' => $student->id,
            'TeacherID' => 99,
            'GradeID' => 1,
            'SubjectID' => 1,
            'CampusID' => 1,
            'SignInDT' => '2026-03-08 15:00:00',
            'MDT' => now(),
            'ClassSessionID' => $pastSession->id,
            'Status' => 'present',
            'SessionDeducted' => 1,
        ]);

        // Future sessions
        $futureDates = ['2026-04-19', '2026-04-26', '2026-05-03', '2026-05-10'];
        foreach ($futureDates as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '15:00:00',
                'EndTime' => '17:00:00',
                'Status' => $allFutureCompleted ? 'completed' : 'scheduled',
            ]);
        }

        return [$token, $student, $course];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-reconcile-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $tok = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $tok,
            'expires_at' => now()->addDay(),
        ]);

        return $tok;
    }
}
