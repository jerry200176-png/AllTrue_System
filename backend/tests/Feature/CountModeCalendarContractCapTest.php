<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountModeCalendarContractCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_projection_and_course_lookup_hide_the_same_capped_future_occurrence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 09:00:00', 'Asia/Taipei'));
        try {
            $token = $this->makeDirectorToken();
            $student = Student::create([
                'name' => 'Count Cap Regression',
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
                'TeacherID' => 1,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-07-30',
                'EndDate' => '2026-09-24',
                'TotalHours' => 16,
                'Charge' => 0,
                'Paid' => 1,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 0,
                'UsedSessions' => 8,
                'ClassType' => 'one_on_one',
                'week' => 4,
                'time' => '19:00:00',
            ]);

            foreach ([
                ['2026-08-06', 'attended'], ['2026-08-13', 'attended'],
                ['2026-08-24', 'attended'], ['2026-08-26', 'late'],
                ['2026-08-27', 'attended'], ['2026-08-28', 'attended'],
                ['2026-09-03', 'attended'], ['2026-09-10', 'attended'],
            ] as [$date, $status]) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '19:00:00',
                    'EndTime' => '21:00:00',
                    'Status' => $status,
                ]);
            }
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-09-17',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
            ]);

            $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
            $courseLookup = $this->withHeaders($headers)->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1,
                'range_start' => '2026-07-30',
                'range_end' => '2026-09-17',
                'courses' => [[
                    'id' => $course->ID,
                    'first_class_date' => '2026-07-30',
                    'sessions_purchased' => 8,
                    'days_of_week' => [4],
                ]],
            ]);
            $courseLookup->assertOk();
            $lookup = $courseLookup->json((string) $course->ID) ?? [];
            $lookupDates = array_merge(
                array_column($lookup['materialized'] ?? [], 'session_date'),
                array_column($lookup['projected'] ?? [], 'session_date')
            );
            $this->assertNotContains('2026-09-17', $lookupDates);

            $calendar = $this->withHeaders($headers)->getJson(
                '/api/v1/class-sessions/projection?branch_id=1&start=2026-09-17&end=2026-09-17'
            );
            $calendar->assertOk()->assertJsonPath('api_kind', 'projection');
            $calendarDates = array_merge(
                array_column($calendar->json('data') ?? [], 'session_date'),
                array_column($calendar->json('projected.by_class.' . $course->ID) ?? [], 'session_date')
            );
            $this->assertNotContains(
                '2026-09-17',
                $calendarDates,
                'Calendar must not surface a future materialized row after the same count-mode contract is full.'
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cancelled_session_replacement_date_agrees_between_session_dates_and_calendar_projection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Asia/Taipei'));
        try {
            $token = $this->makeDirectorToken();
            $student = Student::create([
                'name' => 'Cancel Replace Regression', 'CampusID' => 1, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
                'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-03', 'EndDate' => '2026-12-31',
                'TotalHours' => 6, 'Charge' => 0, 'Paid' => 1, 'Rate' => 500, 'MDate' => now(),
                'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 3, 'SessionDuration' => 120,
                'RemainingSessions' => 3, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
                'week' => 4, 'time' => '19:00:00',
            ]);
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => '2026-09-10',
                'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'cancelled',
            ]);

            $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
            $lookup = $this->withHeaders($headers)->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1, 'range_start' => '2026-09-01', 'range_end' => '2026-10-15',
                'courses' => [[
                    'id' => $course->ID, 'first_class_date' => '2026-09-03',
                    'sessions_purchased' => 3, 'days_of_week' => [4],
                ]],
            ])->assertOk()->json((string) $course->ID) ?? [];
            $lookupDates = array_column($lookup['projected'] ?? [], 'session_date');
            sort($lookupDates);

            $calendar = $this->withHeaders($headers)->getJson(
                '/api/v1/class-sessions/projection?branch_id=1&start=2026-09-01&end=2026-10-15&student_class_id=' . $course->ID
            )->assertOk();
            $calendarDates = array_column($calendar->json('projected.by_class.' . $course->ID) ?? [], 'session_date');
            sort($calendarDates);

            $this->assertContains('2026-09-24', $lookupDates);
            $this->assertNotContains('2026-09-10', $lookupDates);
            $this->assertSame($lookupDates, $calendarDates);

            // A window starting after the cancellation still sees the contract-wide shift.
            $later = $this->withHeaders($headers)->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1, 'range_start' => '2026-09-20', 'range_end' => '2026-10-15',
                'courses' => [[
                    'id' => $course->ID, 'first_class_date' => '2026-09-03',
                    'sessions_purchased' => 3, 'days_of_week' => [4],
                ]],
            ])->assertOk()->json((string) $course->ID) ?? [];
            $this->assertSame(['2026-09-24'], array_column($later['projected'] ?? [], 'session_date'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reschedule_placeholder_is_not_a_cancellation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Asia/Taipei'));
        try {
            $token = $this->makeDirectorToken();
            $student = Student::create([
                'name' => 'Placeholder Regression', 'CampusID' => 1, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
                'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-03', 'EndDate' => '2026-12-31',
                'TotalHours' => 6, 'Charge' => 0, 'Paid' => 1, 'Rate' => 500, 'MDate' => now(),
                'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 3, 'SessionDuration' => 120,
                'RemainingSessions' => 3, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
                'week' => 4, 'time' => '19:00:00',
            ]);
            // Moved lesson is live on 09-10; the auto-materialized duplicate there became a bookkeeping placeholder.
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => '2026-09-10',
                'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'scheduled',
            ]);
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => '2026-09-10',
                'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'cancelled',
                'Note' => 'placeholder', // generic same-slot collision shape
            ]);

            $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
            $lookup = $this->withHeaders($headers)->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1, 'range_start' => '2026-09-01', 'range_end' => '2026-10-15',
                'courses' => [[
                    'id' => $course->ID, 'first_class_date' => '2026-09-03',
                    'sessions_purchased' => 3, 'days_of_week' => [4],
                ]],
            ])->assertOk()->json((string) $course->ID) ?? [];
            $lookupDates = array_column($lookup['projected'] ?? [], 'session_date');
            $calendar = $this->withHeaders($headers)->getJson(
                '/api/v1/class-sessions/projection?branch_id=1&start=2026-09-01&end=2026-10-15&student_class_id=' . $course->ID
            )->assertOk();
            $calendarDates = array_column($calendar->json('projected.by_class.' . $course->ID) ?? [], 'session_date');

            $this->assertNotContains('2026-09-24', $lookupDates, 'a placeholder must not push the contract one week further');
            $this->assertNotContains('2026-09-24', $calendarDates);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_same_day_leave_keeps_schedule_only_makeup_but_cancellation_removes_it(): void
    {
        // Thursdays from 09-03, 3 sessions. Off-pattern Sat 09-12 has a leave marker AND a schedule-only make-up.
        $leave = ['2026-09-12' => true];
        $scheduled = ['2026-09-12' => true];
        $this->assertSame(
            ['2026-09-03', '2026-09-10', '2026-09-12'],
            \App\Http\Controllers\StudentClassController::computeEffectiveSessionDates('2026-09-03', 3, [4], $leave, $scheduled)
        );
        $this->assertSame(
            ['2026-09-03', '2026-09-10', '2026-09-17'],
            \App\Http\Controllers\StudentClassController::computeEffectiveSessionDates('2026-09-03', 3, [4], $leave, $scheduled, ['2026-09-12' => true])
        );
    }

    public function test_session_dates_ignore_cancellations_of_courses_outside_the_branch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Asia/Taipei'));
        try {
            $token = $this->makeDirectorToken();
            $student = Student::create([
                'name' => 'Other Campus', 'CampusID' => 2, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
                'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-03', 'EndDate' => '2026-12-31',
                'TotalHours' => 6, 'Charge' => 0, 'Paid' => 1, 'Rate' => 500, 'MDate' => now(),
                'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 3, 'SessionDuration' => 120,
                'RemainingSessions' => 3, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
                'week' => 4, 'time' => '19:00:00',
            ]);
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => '2026-09-10',
                'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'cancelled',
            ]);
            $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->postJson('/api/v1/student-classes/session-dates', [
                    'branch_id' => 1, 'range_start' => '2026-09-20', 'range_end' => '2026-10-15',
                    'courses' => [[
                        'id' => $course->ID, 'first_class_date' => '2026-09-03',
                        'sessions_purchased' => 3, 'days_of_week' => [4],
                    ]],
                ])->assertOk()->json((string) $course->ID) ?? [];
            $this->assertNotContains('2026-09-24', array_column($res['projected'] ?? [], 'session_date'),
                'campus-2 cancellations must not shift dates for a campus-1 request');

            // Room-first: the same campus-2 student in a campus-1 room belongs to branch 1, so its cancellation counts.
            $roomId = \Illuminate\Support\Facades\DB::table('rooms')->insertGetId(['campus_id' => 1, 'name' => 'R1', 'capacity' => 4, 'created_at' => now(), 'updated_at' => now()]);
            $course->update(['room_id' => $roomId]);
            $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->postJson('/api/v1/student-classes/session-dates', [
                    'branch_id' => 1, 'range_start' => '2026-09-20', 'range_end' => '2026-10-15',
                    'courses' => [[
                        'id' => $course->ID, 'first_class_date' => '2026-09-03',
                        'sessions_purchased' => 3, 'days_of_week' => [4],
                    ]],
                ])->assertOk()->json((string) $course->ID) ?? [];
            $this->assertContains('2026-09-24', array_column($res['projected'] ?? [], 'session_date'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeDirectorToken(): string
    {
        $director = User::create([
            'LoginName' => 'count-cap-director@example.com',
            'Name' => 'Count Cap Director',
            'PSW' => 'secret',
            'type' => 'A',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
