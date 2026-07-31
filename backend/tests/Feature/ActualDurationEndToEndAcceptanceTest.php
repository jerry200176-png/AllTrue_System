<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Scheduling\DeductionBasis;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING — the whole thing, through real HTTP.
 *
 * The unit and slice tests each prove one piece. This one walks the founding case
 * end to end without reaching into the database to set anything up:
 *
 *   建課 (POST /api/v1/class-sessions/batch)
 *     → 點名 x6 (POST /api/v1/attendance)
 *       → 餘額 (GET /api/v1/student-classes)
 *
 * The case: a student takes two 3-hour sessions a week, but the course was sold in
 * 2-hour units. Buy 8 units of 120 minutes, schedule six 180-minute sessions.
 * Under the old rules this could not even be created.
 */
class ActualDurationEndToEndAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $studentId;
    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfflags.actual_duration_deduction_enabled' => true]);

        $this->teacherId = $this->mkUser('T', 'e2e-t' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $this->teacherId, 'Admin' => 0, 'Approved' => 1]);
        $dirId = $this->mkUser('A', 'e2e-d' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $this->token, 'expires_at' => now()->addDay()]);

        $this->studentId = (int) Student::create([
            'name' => '端到端' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
    }

    /**
     * Buy 8 x 120 = 960 minutes, schedule six 180-minute sessions = 1080 minutes,
     * then actually teach all six.
     *
     * Each session consumes its real 180 minutes, not one whole lesson. The sixth
     * has only 60 minutes of entitlement left — and is still allowed to happen.
     */
    public function test_eight_two_hour_units_spent_on_six_three_hour_sessions(): void
    {
        // ── 建課 ────────────────────────────────────────────────────────
        $this->api()->postJson('/api/v1/class-sessions/batch', $this->payload())
            ->assertCreated();

        $sc = StudentClass::where('StudentID', $this->studentId)->firstOrFail();

        $this->assertSame(8, (int) $sc->SessionCount, '買了 8 個標準單位');
        $this->assertSame(120, (int) $sc->standard_lesson_minutes, '這門課的標準一堂是 120 分鐘');
        $this->assertSame(DeductionBasis::ACTUAL_DURATION, $sc->deduction_basis);
        $this->assertSame(960, (int) $sc->PurchasedMinutes, '8 x 120 = 960 分鐘');
        $this->assertSame(960, (int) $sc->RemainingMinutes);

        $sessions = ClassSession::where('StudentClassID', $sc->ID)
            ->orderBy('SessionDate')->orderBy('StartTime')->get();
        $this->assertCount(6, $sessions, '排了 6 次，不是 8 次');

        // ── 點名 x6，每次扣掉真實的 180 分鐘 ─────────────────────────────
        $expected = [780, 600, 420, 240, 60, 0];

        foreach ($sessions as $i => $session) {
            $this->elapse($session, 60 - $i);
            $this->attend($sc->ID, (int) $session->id)
                ->assertCreated("第 " . ($i + 1) . " 次點名必須成功");

            $this->assertSame(
                $expected[$i],
                (int) $sc->fresh()->RemainingMinutes,
                "第 " . ($i + 1) . " 次點名後的剩餘分鐘"
            );
        }

        // 第 6 次上課時只剩 60 分鐘額度，但那堂課照樣上、照樣點名成功（D5）。
        // 額度歸零，ledger 仍記錄真實發生的 180 分鐘。
        $this->assertSame(0, (int) $sc->fresh()->RemainingMinutes, '額度用盡，不會變負數');
        $this->assertSame(
            1080,
            (int) DB::table('session_deduction_ledger')
                ->where('student_class_id', $sc->ID)
                ->where('event_type', 'deduct')
                ->sum('minutes'),
            'ledger 記的是真正發生的 6 x 180 = 1080 分鐘，不是被額度截斷的 960'
        );
    }

    /** 額度用盡之後，第 7 次上課依然點得到名 —— 這是 D5 最重要的一條。 */
    public function test_attendance_still_works_after_the_balance_is_exhausted(): void
    {
        $this->api()->postJson('/api/v1/class-sessions/batch', $this->payload())->assertCreated();
        $sc = StudentClass::where('StudentID', $this->studentId)->firstOrFail();

        foreach (ClassSession::where('StudentClassID', $sc->ID)->orderBy('SessionDate')->get() as $i => $s) {
            $this->elapse($s, 60 - $i);
            $this->attend($sc->ID, (int) $s->id)->assertCreated();
        }
        $this->assertSame(0, (int) $sc->fresh()->RemainingMinutes);

        // 再補一堂課，額度已經是 0
        $extra = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => Carbon::now()->subDay()->toDateString(),
            'StartTime' => '14:00:00', 'EndTime' => '17:00:00', 'Status' => 'scheduled',
        ]);

        $this->attend($sc->ID, (int) $extra->id)
            ->assertCreated('超額不得擋住點名 —— 學生已經來上課了');

        $this->assertSame(0, (int) $sc->fresh()->RemainingMinutes, '仍然停在 0，不會變負');
    }

    /** 課程列表要能表達「還剩半堂」，而不是四捨五入成整數。 */
    public function test_course_list_reports_a_fractional_balance(): void
    {
        $this->api()->postJson('/api/v1/class-sessions/batch', $this->payload())->assertCreated();
        $sc = StudentClass::where('StudentID', $this->studentId)->firstOrFail();

        // 上兩次 180 分鐘 → 960 - 360 = 600 分鐘 = 這門課的 5.00 堂
        $twoSessions = ClassSession::where('StudentClassID', $sc->ID)
            ->orderBy('SessionDate')->limit(2)->get();
        foreach ($twoSessions as $i => $s) {
            $this->elapse($s, 60 - $i);
            $this->attend($sc->ID, (int) $s->id)->assertCreated();
        }

        $row = $this->courseRow($sc->ID);

        $this->assertSame(600, (int) $row['remaining_minutes']);
        $this->assertSame('10.00', $row['remaining_hours']);
        $this->assertSame('5.00', $row['remaining_lesson_equivalent'], '600 ÷ 120 = 5.00 堂');
        $this->assertSame('3.00', $row['used_lesson_equivalent'], '360 ÷ 120 = 3.00 堂');
        $this->assertSame(120, (int) $row['standard_lesson_minutes']);
        $this->assertSame(DeductionBasis::ACTUAL_DURATION, $row['deduction_basis']);

        // 半堂餘額：再上一次 90 分鐘的課 → 600 - 90 = 510 = 4.25 堂
        $half = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => Carbon::now()->subDay()->toDateString(),
            'StartTime' => '09:00:00', 'EndTime' => '10:30:00', 'Status' => 'scheduled',
        ]);
        $this->attend($sc->ID, (int) $half->id)->assertCreated();

        $row = $this->courseRow($sc->ID);
        $this->assertSame(510, (int) $row['remaining_minutes']);
        $this->assertSame(
            '4.25',
            $row['remaining_lesson_equivalent'],
            '整數 remaining_sessions 表達不了的 4.25 堂，正是本 RFC 要消除的誤解'
        );
        $this->assertIsString($row['remaining_lesson_equivalent'], '堂數必須是字串，不得是 binary float');
    }

    /** 契約在第一筆扣堂之後就鎖住 —— 後端才是權威，不是前端把欄位變灰。 */
    public function test_the_contract_locks_once_a_deduction_exists(): void
    {
        $this->api()->postJson('/api/v1/class-sessions/batch', $this->payload())->assertCreated();
        $sc = StudentClass::where('StudentID', $this->studentId)->firstOrFail();

        $first = ClassSession::where('StudentClassID', $sc->ID)->orderBy('SessionDate')->firstOrFail();
        $this->elapse($first, 60);
        $this->attend($sc->ID, (int) $first->id)->assertCreated();

        $this->api()->putJson("/api/v1/student-classes/{$sc->ID}", [
            'standard_lesson_minutes' => 180,
        ])->assertStatus(422);

        $this->assertSame(
            120,
            (int) $sc->fresh()->standard_lesson_minutes,
            '標準堂長沒有被改動 —— 改了等於重新解釋已經扣過的歷史'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function courseRow(int $scId): array
    {
        $res = $this->api()->getJson('/api/v1/student-classes?student_id=' . $this->studentId);
        $res->assertOk();

        foreach ((array) ($res->json('data') ?? $res->json()) as $row) {
            if ((int) ($row['ID'] ?? $row['id'] ?? 0) === $scId) {
                return $row;
            }
        }

        $this->fail("course {$scId} not present in the list response");
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        // Scheduled forward, as a real course is. `confirmed` would mean the session
        // already happened, and creation would back-fill attendance for all six at
        // once — which is not the walk this test is trying to observe.
        $plan = [];
        for ($i = 1; $i <= 6; $i++) {
            $plan[] = [
                'session_date' => Carbon::now()->addDays($i * 7)->toDateString(),
                'start_time' => '14:00',
                'kind' => 'future',
                'duration_minutes' => 180,
            ];
        }

        return [
            'branch_id' => 1,
            'confirmed_dates' => [],
            'future_dates' => [],
            'student_id' => $this->studentId,
            'teacher_id' => $this->teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'payment_type' => 'session',
            'price_per_session' => 500,
            'duration_minutes' => 180,
            'start_time' => '14:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'course_start_date' => Carbon::now()->subDays(45)->toDateString(),
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
            'session_plan' => $plan,
            'overage_confirmed' => true, // 6 x 180 = 1080 > 8 x 120 = 960
        ];
    }

    /**
     * Let the clock reach this session. Attendance is refused for a session that has
     * not ended yet, so a forward-scheduled course cannot be attended without time
     * passing. Moving the row back is the test's stand-in for that — it does not
     * touch any balance, ledger row, or deduction.
     */
    private function elapse(ClassSession $session, int $daysAgo): ClassSession
    {
        $session->SessionDate = Carbon::now()->subDays($daysAgo)->toDateString();
        $session->save();

        return $session;
    }

    private function attend(int $scId, int $csId)
    {
        return $this->api()->postJson('/api/v1/attendance', [
            'StudentID' => $this->studentId,
            'StudentClassID' => $scId,
            'ClassSessionID' => $csId,
            'Status' => 'present',
        ]);
    }

    private function api(): self
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ]);
    }

    private function mkUser(string $type, string $login): int
    {
        return (int) User::create([
            'LoginName' => $login, 'Name' => $type, 'PSW' => 'secret',
            'type' => $type, 'phone' => '0900000000', 'MustChangePassword' => false,
        ])->id;
    }
}
