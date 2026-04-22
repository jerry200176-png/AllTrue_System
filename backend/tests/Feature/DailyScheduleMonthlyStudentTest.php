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
 * 缺口 2：當日課表 API — 月結學生顯示邏輯回歸守護。
 *
 * 背景：2026-04-xx 主任回報「月結超過 2 科的學生沒有顯示在當天課表」（方廷旭、方品嬡）。
 * 根因：StudentClass.EndDate / monthly_sessions 缺失，導致未生成未來 ClassSession。
 * 此測試組確保：
 *  - DS-001：月結排課（payment_type=monthly）正確建立 ClassSession 記錄
 *  - DS-002：月結學生的 ClassSession 出現在 GET /api/v1/class-sessions 的日期篩選結果
 *  - DS-003：計次制與月結制學生同時出現在同一天的課表查詢
 *  - DS-004：超出查詢日期範圍的 ClassSession 不出現
 *  - DS-005：StudentClass.ScheduleMode 為 'date' 時的完整 API 回傳欄位
 */
class DailyScheduleMonthlyStudentTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $teacher;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        // Set to a Tuesday so nextWeekday(3) lands on Wednesday (tomorrow)
        Carbon::setTestNow(Carbon::parse('2026-04-21 09:00:00', 'Asia/Taipei'));

        $this->token   = $this->makeDirectorToken();
        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── DS-001：月結排課後正確建立 ClassSession ──────────────────────────

    public function test_monthly_billing_enrollment_creates_class_sessions(): void
    {
        $date1 = $this->nextWeekday(3);                               // next Wednesday
        $date2 = Carbon::parse($date1)->addWeek()->toDateString();    // week after

        $res = $this->postBatch([
            'payment_type'    => 'monthly',
            'settlement_day'  => 15,
            'monthly_sessions'=> 4,
            'total_classes'   => 2,
            'future_dates'    => [$date1, $date2],
            'days_of_week'    => [3],
        ]);

        $res->assertCreated();

        // StudentClass should use ScheduleMode='date'
        $sc = StudentClass::where('StudentID', $this->student->id)->first();
        $this->assertNotNull($sc, 'StudentClass 應被建立');
        $this->assertSame('date', (string) $sc->ScheduleMode, 'ScheduleMode 應為 date（月結制）');
        $this->assertSame(15, (int) $sc->settlement_day, 'settlement_day 應正確儲存');

        // Two ClassSession records should be created
        $sessions = ClassSession::where('StudentClassID', $sc->ID)->get();
        $this->assertCount(2, $sessions, '應建立 2 筆 ClassSession');

        $dates = $sessions->pluck('SessionDate')->map(fn ($d) => substr((string) $d, 0, 10))->sort()->values()->all();
        $this->assertSame([$date1, $date2], $dates, 'ClassSession 日期應與 future_dates 一致');
    }

    // ── DS-002：月結學生 ClassSession 出現在日期篩選結果 ────────────────

    public function test_monthly_billing_sessions_appear_in_daily_schedule_query(): void
    {
        $targetDate = $this->nextWeekday(3); // next Wednesday

        // Create monthly billing enrollment
        $this->postBatch([
            'payment_type'    => 'monthly',
            'settlement_day'  => 15,
            'total_classes'   => 1,
            'future_dates'    => [$targetDate],
            'days_of_week'    => [3],
        ])->assertCreated();

        // Query daily schedule for that exact date
        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/class-sessions?branch_id=1&start={$targetDate}&end={$targetDate}");

        $res->assertOk();
        $sessionDates = collect($res->json('data'))->pluck('session_date')->all();
        $this->assertContains($targetDate, $sessionDates, '月結學生的課程應出現在當日課表查詢結果');

        // Verify student info is present
        $matchingSession = collect($res->json('data'))
            ->first(fn ($s) => $s['session_date'] === $targetDate && $s['student_id'] === (int) $this->student->id);
        $this->assertNotNull($matchingSession, '月結學生應出現在課表結果中');
        $this->assertSame($this->student->name, $matchingSession['student_name'] ?? null);
    }

    // ── DS-003：計次制 + 月結制學生同時出現在同一天 ──────────────────────

    public function test_count_mode_and_monthly_mode_students_both_appear_in_daily_schedule(): void
    {
        $targetDate = $this->nextWeekday(3); // next Wednesday

        // Student A: count-mode (session-based billing) — inserted directly to bypass enrollment validation
        $studentA = $this->makeStudent('計次制學生');
        $this->createDirectSessionInDB($studentA->id, $targetDate, 'count');

        // Student B (main student): monthly billing via enrollment API
        $this->postBatch([
            'payment_type'   => 'monthly',
            'settlement_day' => 15,
            'total_classes'  => 1,
            'future_dates'   => [$targetDate],
            'days_of_week'   => [3],
        ])->assertCreated();

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/class-sessions?branch_id=1&start={$targetDate}&end={$targetDate}");

        $res->assertOk();
        $studentIds = collect($res->json('data'))->pluck('student_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $studentA->id, $studentIds, '計次制學生應出現在課表');
        $this->assertContains((int) $this->student->id, $studentIds, '月結制學生應出現在課表');
    }

    // ── DS-004：超出查詢範圍的 ClassSession 不出現 ──────────────────────

    public function test_session_outside_date_range_does_not_appear(): void
    {
        $targetDate  = $this->nextWeekday(3);
        $outsideDate = Carbon::parse($targetDate)->addWeek()->toDateString();

        $this->postBatch([
            'payment_type'   => 'monthly',
            'settlement_day' => 15,
            'total_classes'  => 2,
            'future_dates'   => [$targetDate, $outsideDate],
            'days_of_week'   => [3],
        ])->assertCreated();

        // Query only the first date
        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/class-sessions?branch_id=1&start={$targetDate}&end={$targetDate}");

        $res->assertOk();
        $sessionDates = collect($res->json('data'))->pluck('session_date')->all();

        $this->assertContains($targetDate, $sessionDates, '目標日期的課程應出現');
        $this->assertNotContains($outsideDate, $sessionDates, '範圍外的課程不應出現');
    }

    // ── DS-005：月結 StudentClass API 回傳包含必要欄位 ────────────────────

    public function test_monthly_student_class_api_returns_required_fields(): void
    {
        $targetDate = $this->nextWeekday(3); // next Wednesday

        $this->postBatch([
            'payment_type'   => 'monthly',
            'settlement_day' => 15,
            'total_classes'  => 1,
            'future_dates'   => [$targetDate],
            'days_of_week'   => [3],
        ])->assertCreated();

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/class-sessions?branch_id=1&start={$targetDate}&end={$targetDate}");

        $res->assertOk();
        $session = collect($res->json('data'))
            ->first(fn ($s) => $s['student_id'] === (int) $this->student->id);

        $this->assertNotNull($session, '月結學生的 session 應存在');

        // Verify required fields are present and well-typed
        $this->assertArrayHasKey('id', $session);
        $this->assertArrayHasKey('student_id', $session);
        $this->assertArrayHasKey('student_name', $session);
        $this->assertArrayHasKey('session_date', $session);
        $this->assertArrayHasKey('start_time', $session);
        $this->assertArrayHasKey('status', $session);
        $this->assertArrayHasKey('teacher_name', $session);
        $this->assertSame($targetDate, $session['session_date']);
        $this->assertSame('scheduled', $session['status']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function postBatch(array $overrides = [])
    {
        $wed1 = $this->nextWeekday(3);
        $wed2 = Carbon::parse($wed1)->addWeek()->toDateString();

        return $this->withHeaders($this->headers())
            ->postJson('/api/v1/class-sessions/batch', array_merge([
                'branch_id'         => 1,
                'student_id'        => $this->student->id,
                'teacher_id'        => $this->teacher->id,
                'subject'           => 'Math',
                'class_type'        => 'one_on_one',
                'total_classes'     => 2,
                'confirmed_dates'   => [],
                'future_dates'      => [$wed1, $wed2],
                'days_of_week'      => [3],
                'start_time'        => '16:00',
                'duration_minutes'  => 120,
                'price_per_session' => 800,
                'payment_type'      => 'monthly',
                'settlement_day'    => 15,
            ], $overrides));
    }

    /** 取下一個符合 ISO weekday（1=週一 … 7=週日）的未來日期 */
    private function nextWeekday(int $isoDow): string
    {
        $d = Carbon::now()->addDay();
        for ($i = 0; $i < 14; $i++) {
            if ((int) $d->dayOfWeekIso === $isoDow) {
                return $d->toDateString();
            }
            $d->addDay();
        }
        throw new \RuntimeException("Cannot find weekday {$isoDow} in next 14 days");
    }

    /** Directly insert a StudentClass + ClassSession for a given date (bypasses enrollment API). */
    private function createDirectSessionInDB(int $studentId, string $date, string $scheduleMode): void
    {
        $sc = StudentClass::create([
            'StudentID'      => $studentId,
            'GradeID'        => 1,
            'SubjectID'      => 1,
            'TeacherID'      => $this->teacher->id,
            'by1'            => 1,
            'Period'         => 4,
            'StartDate'      => now(),
            'EndDate'        => null,
            'TotalHours'     => 20,
            'Charge'         => 5000,
            'Paid'           => 0,
            'RoomID'         => 'R1',
            'MDate'          => now(),
            'Stop'           => 0,
            'ScheduleMode'   => $scheduleMode,
            'SessionCount'   => $scheduleMode === 'count' ? 10 : 0,
            'SessionDuration'=> 120,
            'RemainingSessions' => $scheduleMode === 'count' ? 5 : 0,
            'ClassType'      => 'one_on_one',
            'UsedSessions'   => 0,
        ]);

        DB::table('ClassSession')->insert([
            'StudentClassID' => $sc->ID,
            'SessionDate'    => $date,
            'StartTime'      => '16:00',
            'EndTime'        => '18:00',
            'Status'         => 'scheduled',
            'Note'           => '',
        ]);
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->token}",
            'Accept'        => 'application/json',
        ];
    }

    private function makeDirectorToken(string $login = 'dir-ds@test.com'): string
    {
        $user = User::create([
            'LoginName'          => $login,
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => 900000000,
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function makeTeacher(): User
    {
        $teacher = User::create([
            'LoginName'          => 'teacher-ds-' . uniqid() . '@test.com',
            'Name'               => '老師',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => 911000000,
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return $teacher;
    }

    private function makeStudent(string $name = '月結學生'): Student
    {
        return Student::create([
            'name'         => $name,
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }
}
