<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Bug Fix regression: GET /api/v1/attendance date-range support.
 *
 * Before fix: the endpoint only supported a single `date` param.
 * Without any date param, it returned ALL records (no time window).
 * `start_date` / `end_date` were silently ignored.
 *
 * After fix:
 *   - `date`  → single-day filter (backward compat)
 *   - `start_date` + `end_date` → range filter
 *   - neither → default last 7 days
 */
class AttendanceRangeTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS_ID = 1;

    private string $token;
    private int    $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token     = $this->makeDirectorToken();
        $this->studentId = $this->makeStudent();
    }

    // ── AC-001: no date params → default last 7 days ────────────────────────

    /** @test */
    public function test_no_date_params_excludes_records_older_than_seven_days(): void
    {
        // old record: 10 days ago — should NOT appear in default view
        $this->makeSignIn(Carbon::now()->subDays(10)->toDateTimeString());

        // recent record: 3 days ago — should appear
        $this->makeSignIn(Carbon::now()->subDays(3)->toDateTimeString());

        $res = $this->getJson('/api/v1/attendance?per_page=50', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $res->assertOk();

        $signInDTs = collect($res->json('data'))->pluck('SignInDT')->all();

        // The 3-day-ago record should be present
        $threeDaysAgoDate = Carbon::now()->subDays(3)->toDateString();
        $hasRecent = collect($signInDTs)->contains(
            fn($dt) => str_starts_with((string) $dt, $threeDaysAgoDate)
        );
        $this->assertTrue($hasRecent, 'Recent record (3 days ago) should appear in default 7-day window');

        // The 10-day-old record should be excluded
        $tenDaysAgoDate = Carbon::now()->subDays(10)->toDateString();
        $hasOld = collect($signInDTs)->contains(
            fn($dt) => str_starts_with((string) $dt, $tenDaysAgoDate)
        );
        $this->assertFalse($hasOld, 'Old record (10 days ago) must NOT appear in default 7-day window');
    }

    // ── AC-002: start_date + end_date → only records in range ───────────────

    /** @test */
    public function test_start_date_end_date_returns_only_records_in_range(): void
    {
        $inRange   = Carbon::now()->subDays(4)->toDateTimeString(); // inside range
        $outOfRange = Carbon::now()->subDays(8)->toDateTimeString(); // outside range

        $this->makeSignIn($inRange);
        $this->makeSignIn($outOfRange);

        $startDate = Carbon::now()->subDays(6)->toDateString();
        $endDate   = Carbon::now()->subDays(2)->toDateString();

        $res = $this->getJson(
            "/api/v1/attendance?start_date={$startDate}&end_date={$endDate}&per_page=50",
            ['Authorization' => "Bearer {$this->token}"]
        );

        $res->assertOk();

        $signInDTs = collect($res->json('data'))->pluck('SignInDT')->all();

        $inRangeDate  = Carbon::now()->subDays(4)->toDateString();
        $outRangeDate = Carbon::now()->subDays(8)->toDateString();

        $hasIn  = collect($signInDTs)->contains(fn($dt) => str_starts_with((string) $dt, $inRangeDate));
        $hasOut = collect($signInDTs)->contains(fn($dt) => str_starts_with((string) $dt, $outRangeDate));

        $this->assertTrue($hasIn,   'Record within start_date–end_date range must appear');
        $this->assertFalse($hasOut, 'Record outside start_date–end_date range must NOT appear');
    }

    // ── AC-003: single `date` still works (backward compat) ─────────────────

    /** @test */
    public function test_single_date_param_backward_compat(): void
    {
        $targetDate = Carbon::now()->subDays(2)->toDateString();
        $this->makeSignIn($targetDate . ' 10:00:00');
        $this->makeSignIn(Carbon::now()->subDays(5)->toDateTimeString()); // different day

        $res = $this->getJson(
            "/api/v1/attendance?date={$targetDate}&per_page=50",
            ['Authorization' => "Bearer {$this->token}"]
        );

        $res->assertOk();

        $signInDTs = collect($res->json('data'))->pluck('SignInDT')->all();

        $otherDate = Carbon::now()->subDays(5)->toDateString();
        $hasOther  = collect($signInDTs)->contains(fn($dt) => str_starts_with((string) $dt, $otherDate));

        $this->assertFalse($hasOther, 'Single date= query must not return records from other days');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeSignIn(string $signInDT): StudentSignIn
    {
        return StudentSignIn::create([
            'StudentID' => $this->studentId,
            'SignInDT'  => $signInDT,
            'CampusID'  => self::CAMPUS_ID,
            'Status'    => 'present',
            'MDT'       => now(),
        ]);
    }

    private function makeDirectorToken(): string
    {
        $user = User::create([
            'LoginName'          => 'dir-range@example.com',
            'Name'               => '主任區間測試',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0933111222',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => self::CAMPUS_ID,
            'UserID'   => $user->id,
            'Admin'    => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function makeStudent(): int
    {
        $student = Student::create([
            'name'       => '測試學生甲',
            'CampusID'   => self::CAMPUS_ID,
            'ClassID'    => 1,
            'enable'     => 1,
            'MDT'        => now(),
            'Notify_Token' => '',
        ]);

        return (int) $student->id;
    }
}
