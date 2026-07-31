<?php

namespace Tests\Feature;

use App\Models\AuthToken;
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
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING D5/D6 — create flow.
 *
 * D6: session_plan decides how many occurrences exist; purchased units decide how
 * much entitlement was bought. For actual-duration courses those are two different
 * numbers and the old "they must be equal" rule no longer applies.
 * D5: an over-scheduled course needs explicit confirmation, computed server-side.
 */
class ActualDurationCreateFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $studentId;
    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfflags.actual_duration_deduction_enabled' => true]);

        $this->teacherId = $this->mkUser('T', 'acf-t' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $this->teacherId, 'Admin' => 0, 'Approved' => 1]);
        $dirId = $this->mkUser('A', 'acf-d' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $this->token, 'expires_at' => now()->addDay()]);

        $this->studentId = (int) Student::create([
            'name' => '建課' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
    }

    /**
     * The acceptance case: buy 8 standard units of 120 minutes, schedule only SIX
     * 180-minute sessions. Under the old rule this was impossible — the request was
     * rejected unless exactly 8 occurrences were scheduled.
     */
    public function test_purchased_units_no_longer_have_to_equal_occurrence_count(): void
    {
        $res = $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
            'session_plan' => $this->plan(6, 180),
            'overage_confirmed' => true, // 6x180=1080 > 8x120=960
        ]);

        $res->assertCreated();

        $sc = StudentClass::where('StudentID', $this->studentId)->firstOrFail();
        $this->assertSame(8, (int) $sc->SessionCount, 'entitlement is 8 standard units');
        $this->assertSame(120, $sc->resolvedStandardLessonMinutes());
        $this->assertTrue($sc->isActualDurationBasis());
        $this->assertSame(
            6,
            DB::table('ClassSession')->where('StudentClassID', $sc->ID)->count(),
            'six occurrences materialised, not eight'
        );
    }

    /** fixed_session courses keep the existing equality rule byte-identical. */
    public function test_fixed_session_still_requires_units_to_equal_occurrences(): void
    {
        $this->create([
            'total_classes' => 8,
            'session_plan' => $this->plan(6, 120),
        ])->assertStatus(422)
            ->assertJsonPath('errors.total_classes.0', '堂數（session_plan 或日期清單總筆數）需與購買總堂數一致');
    }

    public function test_fixed_session_with_matching_counts_still_succeeds(): void
    {
        $this->create([
            'total_classes' => 6,
            'session_plan' => $this->plan(6, 120),
        ])->assertCreated();

        $sc = StudentClass::where('StudentID', $this->studentId)->firstOrFail();
        $this->assertSame(DeductionBasis::FIXED_SESSION, $sc->deduction_basis);
        $this->assertNull($sc->standard_lesson_minutes, 'no billing standard is persisted for fixed_session');
    }

    // ── D5: overage confirmation ──────────────────────────────────────

    /** Over-scheduling without confirmation is refused, with the numbers to explain why. */
    public function test_unconfirmed_overage_is_refused_with_coverage_detail(): void
    {
        $res = $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
            'session_plan' => $this->plan(6, 180),
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('code', 'overage_confirmation_required')
            ->assertJsonPath('coverage.entitlement_minutes', 960)
            ->assertJsonPath('coverage.scheduled_minutes', 1080)
            ->assertJsonPath('coverage.fully_covered_occurrences', 5)
            ->assertJsonPath('coverage.first_partially_covered_occurrence', 6)
            ->assertJsonPath('coverage.partial_covered_minutes', 60)
            ->assertJsonPath('coverage.uncovered_minutes', 120)
            ->assertJsonPath('coverage.uncovered_lesson_equivalent', '1.00');

        $this->assertSame(0, StudentClass::where('StudentID', $this->studentId)->count(), 'nothing created');
    }

    /** A schedule that fits needs no confirmation at all. */
    public function test_fully_covered_schedule_needs_no_confirmation(): void
    {
        $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 180, // 8 x 180 = 1440 >= 6 x 180 = 1080
            'session_plan' => $this->plan(6, 180),
        ])->assertCreated();
    }

    /**
     * Raising the course's own standard turns the same schedule from an overage into
     * a surplus — proof the check uses the per-course standard, not a fixed 120.
     */
    public function test_the_same_schedule_flips_between_overage_and_surplus_by_course_standard(): void
    {
        $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 90, // 8 x 90 = 720 < 1080
            'session_plan' => $this->plan(6, 180),
        ])->assertStatus(422)->assertJsonPath('coverage.uncovered_minutes', 360);

        $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 180, // 8 x 180 = 1440 > 1080
            'session_plan' => $this->plan(6, 180),
        ])->assertCreated();
    }

    // ── contract validation at create time ────────────────────────────

    public function test_actual_duration_without_a_standard_is_refused(): void
    {
        $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'session_plan' => $this->plan(6, 180),
        ])->assertStatus(422);

        $this->assertSame(0, StudentClass::where('StudentID', $this->studentId)->count());
    }

    /** @dataProvider illegalStandardProvider */
    public function test_illegal_standard_is_refused(int $minutes): void
    {
        $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => $minutes,
            'session_plan' => $this->plan(6, 180),
            'overage_confirmed' => true,
        ])->assertStatus(422);
    }

    /** @return array<string, array{0:int}> */
    public static function illegalStandardProvider(): array
    {
        return ['too short' => [29], 'too long' => [481]];
    }

    /**
     * The environment flag is the master switch. With it off the API must refuse to
     * create an actual-duration course at all, rather than creating one that quietly
     * deducts whole lessons because the engine is not running yet.
     */
    public function test_flag_off_refuses_to_create_an_actual_duration_course(): void
    {
        config(['perfflags.actual_duration_deduction_enabled' => false]);

        $this->create([
            'total_classes' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
            'session_plan' => $this->plan(6, 180),
            'overage_confirmed' => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.deduction_basis.0', '此功能尚未啟用，請改用「每次上課扣一堂」。');

        $this->assertSame(0, StudentClass::where('StudentID', $this->studentId)->count());
    }

    /** Flag off must leave ordinary course creation completely untouched. */
    public function test_flag_off_still_creates_fixed_session_courses_normally(): void
    {
        config(['perfflags.actual_duration_deduction_enabled' => false]);

        $this->create([
            'total_classes' => 6,
            'session_plan' => $this->plan(6, 120),
        ])->assertCreated();

        $sc = StudentClass::where('StudentID', $this->studentId)->firstOrFail();
        $this->assertSame(DeductionBasis::FIXED_SESSION, $sc->deduction_basis);
        $this->assertNull($sc->standard_lesson_minutes);
    }

    public function test_monthly_courses_cannot_use_actual_duration_yet(): void
    {
        $this->create([
            'payment_type' => 'monthly',
            'monthly_sessions' => 6,
            'settlement_day' => 5,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
            'session_plan' => $this->plan(6, 180),
        ])->assertStatus(422);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return list<array<string, string>> */
    private function plan(int $count, int $durationMinutes): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'session_date' => Carbon::now()->addDays(7 + $i * 7)->toDateString(),
                'start_time' => '14:00',
                'kind' => 'future',
                'duration_minutes' => $durationMinutes,
            ];
        }

        return $rows;
    }

    private function create(array $over)
    {
        $durations = array_column($over['session_plan'] ?? [], 'duration_minutes');
        $payload = array_merge([
            'branch_id' => 1,
            'confirmed_dates' => [],
            'future_dates' => [],
            'student_id' => $this->studentId,
            'teacher_id' => $this->teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'payment_type' => 'session',
            'price_per_session' => 500,
            'duration_minutes' => $durations ? max($durations) : 120,
            'start_time' => '14:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'course_start_date' => Carbon::now()->addDays(7)->toDateString(),
        ], $over);

        return $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', $payload);
    }

    private function mkUser(string $type, string $login): int
    {
        return (int) User::create([
            'LoginName' => $login, 'Name' => $type, 'PSW' => 'secret',
            'type' => $type, 'phone' => '0900000000', 'MustChangePassword' => false,
        ])->id;
    }
}
