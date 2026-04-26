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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 月結課程 end_date + days_of_week 自動排課測試。
 *
 * P0: 傳入 end_date + days_of_week → 後端自動生成全期 ClassSession，
 * 不再依賴前端手動 future_dates。
 */
class EnrollmentServiceMonthlyRecurringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-23 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-monthly-' . uniqid() . '@test.com',
            'Name'      => '主任',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 900000000,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function createTeacher(int $campusId = 1): int
    {
        $user = User::create([
            'LoginName' => 'teacher-monthly-' . uniqid() . '@test.com',
            'Name'      => '老師',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 911000000,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID'   => $user->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);
        return $user->id;
    }

    private function createStudent(int $campusId = 1): Student
    {
        return Student::create([
            'name'         => '月結學生' . uniqid(),
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    /**
     * FR-002: end_date + days_of_week → 後端自動生成全期 ClassSession。
     * 每週四 18:00, 2026/05/07 ~ 2026/07/31 → 13 個週四。
     */
    public function test_monthly_with_end_date_auto_generates_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'days_of_week'     => [4],
            'start_time'       => '18:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type'     => 'monthly',
            'settlement_day'   => 5,
            'end_date'         => '2026-07-31',
            'course_start_date' => '2026-05-07',
            'confirmed_dates'  => [],
            'future_dates'     => [],
        ]);

        $response->assertCreated();

        $studentClassId = $response->json('student_class_id');
        $this->assertNotNull($studentClassId);

        $sessions = ClassSession::where('StudentClassID', $studentClassId)
            ->whereNotIn('Status', ['cancelled'])
            ->orderBy('SessionDate')
            ->get();

        $this->assertEquals(13, $sessions->count(), 'Should generate 13 Thursday sessions from 2026/05/07 to 2026/07/31');

        foreach ($sessions as $session) {
            $dow = (int) Carbon::parse($session->SessionDate)->dayOfWeekIso;
            $this->assertEquals(4, $dow, "Session {$session->SessionDate} should be Thursday (ISO 4)");
            $this->assertEquals('18:00:00', $session->StartTime);
            $this->assertEquals('20:00:00', $session->EndTime);
        }

        $sc = StudentClass::find($studentClassId);
        $this->assertEquals('date', $sc->ScheduleMode);
        $this->assertEquals('2026-07-31', substr($sc->EndDate, 0, 10));
    }

    /**
     * FR-002: 多個星期幾 → 每週生成多堂。
     * 週一+週四, 2026/05/01 ~ 2026/05/31 → 週一有 4 個 (5,12,19,26), 週四有 5 個 (1,8,15,22,29) = 共 9 堂。
     */
    public function test_monthly_with_multiple_weekdays(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'days_of_week'     => [1, 4],
            'start_time'       => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 600,
            'payment_type'     => 'monthly',
            'settlement_day'   => 10,
            'end_date'         => '2026-05-31',
            'course_start_date' => '2026-05-01',
            'confirmed_dates'  => [],
            'future_dates'     => [],
        ]);

        $response->assertCreated();
        $scId = $response->json('student_class_id');

        $sessions = ClassSession::where('StudentClassID', $scId)
            ->whereNotIn('Status', ['cancelled'])
            ->get();

        $this->assertEquals(8, $sessions->count(), 'Mon(4) + Thu(4) = 8 sessions in May 2026');
    }

    /**
     * Explicit session_plan is the source of truth when the monthly creation
     * calendar has been manually adjusted before submit.
     */
    public function test_monthly_with_end_date_and_session_plan_uses_explicit_dates(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'days_of_week'     => [4],
            'start_time'       => '18:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type'     => 'monthly',
            'settlement_day'   => 5,
            'monthly_sessions' => 2,
            'end_date'         => '2026-05-31',
            'course_start_date' => '2026-05-01',
            'confirmed_dates'  => [],
            'future_dates'     => [],
            'session_plan'     => [
                ['session_date' => '2026-05-07', 'start_time' => '18:00', 'kind' => 'future', 'subject' => 'Math'],
                ['session_date' => '2026-05-12', 'start_time' => '18:00', 'kind' => 'future', 'subject' => 'Math'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('created_sessions', 2)
            ->assertJsonPath('created_future_sessions', 2);

        $scId = $response->json('student_class_id');
        $sessions = ClassSession::where('StudentClassID', $scId)
            ->orderBy('SessionDate')
            ->pluck('SessionDate')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->values()
            ->all();

        $this->assertSame(['2026-05-07', '2026-05-12'], $sessions);

        $sc = StudentClass::find($scId);
        $this->assertSame('date', (string) $sc->ScheduleMode);
        $this->assertSame(2, (int) $sc->monthly_sessions);
        $this->assertSame('2026-05-31', substr((string) $sc->EndDate, 0, 10));
    }

    /**
     * Edge: end_date 等於 start_date 且當天是指定星期 → 生成 1 堂。
     * 2026-05-07 is Thursday (ISO 4).
     */
    public function test_same_day_start_end_matching_weekday(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'days_of_week'     => [4],
            'start_time'       => '18:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type'     => 'monthly',
            'settlement_day'   => 5,
            'end_date'         => '2026-05-07',
            'course_start_date' => '2026-05-07',
            'confirmed_dates'  => [],
            'future_dates'     => [],
        ]);

        $response->assertCreated();
        $scId = $response->json('student_class_id');
        $count = ClassSession::where('StudentClassID', $scId)->count();
        $this->assertEquals(1, $count);
    }

    /**
     * NFR-004: end_date 超過 2 年 → 422。
     */
    public function test_end_date_exceeding_two_years_returns_422(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'days_of_week'     => [4],
            'start_time'       => '18:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type'     => 'monthly',
            'settlement_day'   => 5,
            'end_date'         => '2028-12-31',
            'course_start_date' => '2026-05-01',
            'confirmed_dates'  => [],
            'future_dates'     => [],
        ]);

        $response->assertStatus(422);
    }

    /**
     * NFR-005: 舊月結路徑（無 end_date，有 future_dates）行為不變。
     * Uses dates within the same month to satisfy existing cross-month restriction.
     */
    public function test_legacy_monthly_without_end_date_still_works(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'days_of_week'     => [4],
            'start_time'       => '18:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type'     => 'monthly',
            'settlement_day'   => 5,
            'monthly_sessions' => 4,
            'confirmed_dates'  => [],
            'future_dates'     => [
                '2026-05-07',
                '2026-05-14',
                '2026-05-21',
                '2026-05-28',
            ],
        ]);

        $response->assertCreated();
        $scId = $response->json('student_class_id');
        $count = ClassSession::where('StudentClassID', $scId)->count();
        $this->assertEquals(4, $count, 'Legacy path should still create 4 sessions from future_dates');
    }

    /**
     * end_date < course_start_date → 後端 422。
     */
    public function test_end_date_before_start_date_returns_422(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = $this->createTeacher();
        $student = $this->createStudent();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacherId,
            'subject'          => 'Math',
            'class_type'       => 'one_on_one',
            'days_of_week'     => [4],
            'start_time'       => '18:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type'     => 'monthly',
            'settlement_day'   => 5,
            'end_date'         => '2026-04-01',
            'course_start_date' => '2026-05-01',
            'confirmed_dates'  => [],
            'future_dates'     => [],
        ]);

        $response->assertStatus(422);
    }
}
