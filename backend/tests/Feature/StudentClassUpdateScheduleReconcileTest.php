<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
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
            'sessions_purchased' => 8,
            'remaining_sessions' => 4,
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
                'RoomID'      => '1',
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
                'RoomID'           => '1',
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
                'RoomID' => '1',
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
                'sessions_purchased' => 8,
                'remaining_sessions' => 4,
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
            'RoomID' => '1',
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
