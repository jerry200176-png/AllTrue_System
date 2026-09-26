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
