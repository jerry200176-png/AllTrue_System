<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug #497 / in-app #126：sessionDates POST 在 bodyCourses 不夾帶 days_of_week 時，
 * fallback 鏈漏讀「自身 week*」欄位 → 月度 24 堂課只回傳已實體化的 ClassSession 日期，
 * 後續週期堂次完全消失（施景媛 SC#1841）。
 */
class SessionDatesSelfWeekFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_self_week_when_request_and_sibling_empty(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => '施景媛測試', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'SchoolName' => 'Test',
        ]);

        $courseId = $this->makeCourseWithWeek($student->id, [
            'week' => 1,
            'PackageID' => 9701,
        ]);

        $res = $this->postSessionDates($token, [
            'branch_id'   => 1,
            'range_start' => '2026-05-01',
            'range_end'   => '2026-12-31',
            'courses'     => [[
                'id'                 => $courseId,
                'first_class_date'   => '2026-05-18',
                'sessions_purchased' => 24,
                'days_of_week'       => [],
            ]],
        ]);

        $res->assertOk();
        $dates = $res->json((string) $courseId) ?? [];

        $this->assertCount(24, $dates, 'self-week fallback must yield 24 weekly Monday dates');
        foreach ($dates as $d) {
            $this->assertSame(1, (int) date('N', strtotime((string) $d)), "$d should be a Monday");
        }
    }

    public function test_sibling_fallback_still_works_when_self_is_empty(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => 'Sibling Fallback', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'SchoolName' => 'Test',
        ]);

        $siblingId = $this->makeCourseWithWeek($student->id, ['week' => 2, 'PackageID' => 9702]);
        $emptyId = $this->makeCourseWithWeek($student->id, ['week' => 0, 'PackageID' => 9702]);

        $res = $this->postSessionDates($token, [
            'branch_id'   => 1,
            'range_start' => '2026-05-01',
            'range_end'   => '2026-12-31',
            'courses'     => [[
                'id'                 => $emptyId,
                'first_class_date'   => '2026-05-19',
                'sessions_purchased' => 8,
                'days_of_week'       => [],
            ]],
        ]);

        $res->assertOk();
        $dates = $res->json((string) $emptyId) ?? [];
        $this->assertCount(8, $dates, 'sibling fallback (#440) must still apply when self is empty');
        foreach ($dates as $d) {
            $this->assertSame(2, (int) date('N', strtotime((string) $d)), "$d should be a Tuesday");
        }
    }

    public function test_request_days_of_week_takes_precedence_over_self_week(): void
    {
        $token = $this->makeToken([1]);
        $student = Student::create([
            'name' => 'Request Wins', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'SchoolName' => 'Test',
        ]);
        $courseId = $this->makeCourseWithWeek($student->id, ['week' => 1, 'PackageID' => 9703]);

        $res = $this->postSessionDates($token, [
            'branch_id'   => 1,
            'range_start' => '2026-05-01',
            'range_end'   => '2026-12-31',
            'courses'     => [[
                'id'                 => $courseId,
                'first_class_date'   => '2026-05-20',
                'sessions_purchased' => 4,
                'days_of_week'       => [3],
            ]],
        ]);

        $res->assertOk();
        $split = $res->json((string) $courseId) ?? [];
        $dates = array_values(array_unique(array_merge(
            array_column($split['materialized'] ?? [], 'session_date'),
            array_column($split['projected'] ?? [], 'session_date')
        )));
        $this->assertCount(4, $dates);
        foreach ($dates as $d) {
            $this->assertSame(3, (int) date('N', strtotime((string) $d)), "$d should be a Wednesday");
        }
    }

    private function postSessionDates(string $token, array $body)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/student-classes/session-dates', $body);
    }

    private function makeToken(array $campusIds): string
    {
        static $seq = 0;
        $seq++;
        $user = User::create([
            'LoginName' => 'sd-self-week-' . $seq . '@example.com',
            'Name' => 'SD Self Week',
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
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function makeCourseWithWeek(int $studentId, array $extra = []): int
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
            'StartDate'       => '2026-05-18',
            'RoomID'          => '',
            'SessionCount'    => 24,
            'SessionDuration' => 120,
            'RemainingSessions' => 24,
            'UsedSessions'    => 0,
            'Stop'            => 0,
            'ScheduleMode'    => 'count',
        ], $extra));
    }
}
