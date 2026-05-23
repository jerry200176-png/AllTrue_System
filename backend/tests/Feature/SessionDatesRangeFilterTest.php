<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: POST /api/v1/student-classes/session-dates must respect
 * range_start / range_end and NOT return historical ClassSession dates
 * outside the requested window.
 *
 * Without the fix, the handler fetches ALL ClassSessions (no date filter),
 * causing the frontend to receive historical dates and render them as gray
 * synthetic chips even when real sessions exist in the current window.
 */
class SessionDatesRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'sd-range-test@example.com',
            'Name' => 'SD Range Tester',
            'PSW' => bcrypt('x'),
            'type' => 'A',
            'status' => 'active',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::firstOrCreate(
                ['UserID' => $user->id, 'CampusID' => $cid],
                ['Admin' => 1, 'Approved' => 1]
            );
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token'   => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function makeCourse(int $studentId, int $campusId, array $extra = []): int
    {
        return DB::table('StudentClass')->insertGetId(array_merge([
            'StudentID'       => $studentId,
            'GradeID'         => 1,
            'SubjectID'       => 1,
            'TeacherID'       => 1,
            'by1'             => 1,
            'Period'          => 4,
            'TotalHours'      => 0,
            'Charge'          => 0,
            'Pay'             => 0,
            'Paid'            => 0,
            'Rate'            => 0,
            'ClassType'       => 'one_on_one',
            'StartDate'       => now()->subMonths(6)->toDateTimeString(),
            'RoomID'          => '',
            'SessionCount'    => 20,
            'SessionDuration' => 60,
            'RemainingSessions' => 14,
            'UsedSessions'    => 6,
            'Stop'            => 0,
        ], $extra));
    }

    /**
     * The session-dates API should only return ClassSession dates within the
     * requested range_start / range_end window.
     *
     * Before the fix: ALL sessions (including historical > 2 months ago) are
     * returned, causing gray synthetic chips for old dates in the UI.
     *
     * After the fix: only sessions in [range_start, range_end] are returned.
     */
    public function test_session_dates_only_returns_sessions_within_range(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => '測試學生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'SchoolName' => 'Test',
        ]);
        $courseId = $this->makeCourse($student->id, 1);

        $rangeStart = '2026-03-01';
        $rangeEnd   = '2026-07-31';

        // Historical sessions BEFORE range_start (should NOT appear)
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2025-10-03',
            'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2025-12-05',
            'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended',
        ]);

        // Sessions WITHIN range (should appear)
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-03-07',
            'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-05-16',
            'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', [
            'branch_id'   => 1,
            'range_start' => $rangeStart,
            'range_end'   => $rangeEnd,
            'courses'     => [
                [
                    'id'                => $courseId,
                    'first_class_date'  => '2025-10-03',
                    'sessions_purchased' => 20,
                    'days_of_week'      => [5],
                ],
            ],
        ]);

        $res->assertOk();
        $dates = $res->json("{$courseId}") ?? [];

        $this->assertIsArray($dates, 'session-dates must return an array for the course');

        // Within-range dates MUST be present
        $this->assertContains('2026-03-07', $dates, 'In-range attended session must appear');
        $this->assertContains('2026-05-16', $dates, 'In-range scheduled session must appear');

        // Historical dates MUST NOT appear
        $this->assertNotContains(
            '2025-10-03', $dates,
            'Historical session before range_start must NOT appear — causes gray chips in UI'
        );
        $this->assertNotContains(
            '2025-12-05', $dates,
            'Historical session before range_start must NOT appear — causes gray chips in UI'
        );
    }

    /**
     * Cancelled sessions within range should also be excluded from the result
     * (matching the existing skip logic for cancelled/leave statuses).
     */
    public function test_cancelled_sessions_excluded_even_within_range(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => '取消測試', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'SchoolName' => 'Test',
        ]);
        $courseId = $this->makeCourse($student->id, 1);

        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-04-11',
            'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'cancelled',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-04-18',
            'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', [
            'branch_id'   => 1,
            'range_start' => '2026-03-01',
            'range_end'   => '2026-07-31',
            'courses'     => [
                ['id' => $courseId, 'first_class_date' => '2026-03-01',
                 'sessions_purchased' => 20, 'days_of_week' => [5]],
            ],
        ]);

        $res->assertOk();
        $dates = $res->json("{$courseId}") ?? [];
        $this->assertNotContains('2026-04-11', $dates, 'Cancelled session must not appear');
        $this->assertContains('2026-04-18', $dates, 'Attended session must appear');
    }

    public function test_count_mode_returns_future_contract_dates_when_only_history_exists(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => '堂數制測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'SchoolName' => 'Test',
        ]);

        $courseId = $this->makeCourse($student->id, 1, [
            'StartDate' => '2026-04-22 00:00:00',
            'SessionCount' => 8,
            'ScheduleMode' => 'count',
            'week' => 3,
            'time' => '19:00:00',
            'week1' => 6,
            'time1' => '13:00:00',
            'RemainingSessions' => 5,
            'UsedSessions' => 3,
        ]);

        // Only historical rows exist; future scheduled rows are missing.
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-04-22',
            'StartTime' => '19:00',
            'EndTime' => '21:00',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-04-29',
            'StartTime' => '19:00',
            'EndTime' => '21:00',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-05-06',
            'StartTime' => '19:00',
            'EndTime' => '21:00',
            'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', [
            'branch_id' => 1,
            'range_start' => '2026-04-01',
            'range_end' => '2026-07-31',
            'courses' => [[
                'id' => $courseId,
                'first_class_date' => '2026-04-22',
                'sessions_purchased' => 8,
                'days_of_week' => [3, 6],
            ]],
        ]);

        $res->assertOk();
        $dates = $res->json((string) $courseId) ?? [];

        $hasFutureContractDate = collect($dates)->contains(function ($date) {
            return $date > '2026-05-06';
        });
        $this->assertTrue($hasFutureContractDate, 'Future contract date should be present');
        $this->assertCount(8, $dates, 'Count-mode list should still align with purchased sessions');
    }

    public function test_count_mode_can_fallback_to_package_sibling_weekdays_when_current_course_has_none(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => '方案同步測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'SchoolName' => 'Test',
        ]);

        $seedCourseId = $this->makeCourse($student->id, 1, [
            'StartDate' => '2026-04-22 00:00:00',
            'SessionCount' => 8,
            'ScheduleMode' => 'count',
            'PackageID' => 99,
            'week' => 3,
            'time' => '19:00:00',
            'week1' => 6,
            'time1' => '13:00:00',
        ]);

        $targetCourseId = $this->makeCourse($student->id, 1, [
            'StartDate' => '2026-04-22 00:00:00',
            'SessionCount' => 8,
            'ScheduleMode' => 'count',
            'PackageID' => 99,
            'week' => null,
            'week1' => null,
            'time' => null,
            'time1' => null,
        ]);

        // Target course has only attended history and no contract weekdays.
        ClassSession::create([
            'StudentClassID' => $targetCourseId,
            'SessionDate' => '2026-04-22',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', [
            'branch_id' => 1,
            'range_start' => '2026-04-01',
            'range_end' => '2026-07-31',
            'courses' => [[
                'id' => $targetCourseId,
                'first_class_date' => '2026-04-22',
                'sessions_purchased' => 8,
                'days_of_week' => [],
            ]],
        ]);

        $res->assertOk();
        $dates = $res->json((string) $targetCourseId) ?? [];

        $hasFallbackSaturday = collect($dates)->contains(function ($date) {
            return $date > '2026-04-22' && \Carbon\Carbon::parse($date)->dayOfWeekIso === 6;
        });
        $hasFallbackWednesday = collect($dates)->contains(function ($date) {
            return $date > '2026-04-22' && \Carbon\Carbon::parse($date)->dayOfWeekIso === 3;
        });
        $this->assertTrue($hasFallbackSaturday, 'Fallback should include package sibling weekday');
        $this->assertTrue($hasFallbackWednesday, 'Fallback should include package sibling weekday');
        $this->assertCount(8, $dates);
    }
}
