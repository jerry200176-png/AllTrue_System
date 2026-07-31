<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Scheduling\DeductionBasis;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 2 — runtime deduction.
 *
 * The property under test throughout: a session consumes minutes in proportion to
 * THAT COURSE's own standard lesson length. The same 180-minute session costs
 * 2.00 / 1.50 / 1.00 lessons on a 90 / 120 / 180-minute course.
 */
class ActualDurationDeductionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $studentId;
    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfflags.actual_duration_deduction_enabled' => true]);

        $this->teacherId = $this->mkUser('T', 'add-t' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $this->teacherId, 'Admin' => 0, 'Approved' => 1]);
        $dirId = $this->mkUser('A', 'add-d' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $this->token, 'expires_at' => now()->addDay()]);

        $this->studentId = (int) Student::create([
            'name' => '時長' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
    }

    // ── the acceptance case ───────────────────────────────────────────

    /**
     * 8 units of a 120-minute standard = 960 minutes. Six 180-minute sessions.
     * Balance after each attendance must walk 780 / 600 / 420 / 240 / 60, and the
     * sixth session leaves 120 minutes uncovered.
     */
    public function test_acceptance_case_eight_units_of_120_against_six_180_minute_sessions(): void
    {
        $sc = $this->course(['SessionCount' => 8, 'standard_lesson_minutes' => 120]);

        $expectedRemaining = [780, 600, 420, 240, 60, 0];
        $expectedEquivalent = ['6.50', '5.00', '3.50', '2.00', '0.50', '0.00'];

        for ($i = 0; $i < 6; $i++) {
            $cs = $this->makeSession($sc->ID, $this->pastDate(30 - $i), '14:00', '17:00');
            $this->attend($sc->ID, $cs->id)->assertCreated();

            $fresh = $sc->fresh();
            $this->assertSame(
                $expectedRemaining[$i],
                (int) $fresh->RemainingMinutes,
                'remaining minutes after session ' . ($i + 1)
            );
            $this->assertSame($expectedEquivalent[$i], $this->equivalent($fresh), 'lesson equivalent after session ' . ($i + 1));
        }

        // The 6th session needed 180 but only 60 remained. RemainingMinutes floors at 0;
        // the 120-minute shortfall is recoverable from the ledger, which records every
        // deduction at its true length.
        $netMinutes = $this->netDeductedMinutes($sc->ID);
        $this->assertSame(1080, $netMinutes, 'six 180-minute sessions = 1080 minutes deducted');
        $this->assertSame(120, max(0, $netMinutes - 960), 'uncovered = net - purchased');
    }

    // ── per-course standard ───────────────────────────────────────────

    /**
     * The identical 180-minute session consumes a different amount on each course.
     *
     * @dataProvider perCourseStandardProvider
     */
    public function test_same_session_costs_different_amounts_per_course_standard(
        int $standard,
        int $units,
        int $expectedPurchased,
        int $expectedRemaining,
        string $expectedEquivalentUsed
    ): void {
        $sc = $this->course(['SessionCount' => $units, 'standard_lesson_minutes' => $standard]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00'); // 180 minutes

        $this->attend($sc->ID, $cs->id)->assertCreated();

        $fresh = $sc->fresh();
        $this->assertSame($expectedPurchased, (int) $fresh->PurchasedMinutes);
        $this->assertSame($expectedRemaining, (int) $fresh->RemainingMinutes);

        // A session exactly one standard long keeps the whole-session (null) encoding;
        // any other length records its real minutes. Both consume the same 180 minutes.
        $recorded = SessionDeductionLedger::where('student_class_id', $sc->ID)
            ->where('event_type', 'deduct')->value('minutes');
        if ($standard === 180) {
            $this->assertNull($recorded, 'exactly one standard long -> whole-session encoding');
        } else {
            $this->assertSame(180, (int) $recorded, 'ledger records the real 180 minutes');
        }

        $this->assertSame(
            $expectedEquivalentUsed,
            (new \App\Services\Scheduling\LessonEntitlementCoverageCalculator())
                ->lessonEquivalent(180, $standard),
            'a 180-minute session in this course standard'
        );
    }

    /** @return array<string, array{0:int,1:int,2:int,3:int,4:string}> */
    public static function perCourseStandardProvider(): array
    {
        return [
            // standard, units, purchased, remaining after one 180-min session, equivalent
            '120-minute standard: 180min costs 1.50' => [120, 8, 960, 780, '1.50'],
            '180-minute standard: 180min costs 1.00' => [180, 8, 1440, 1260, '1.00'],
            '90-minute standard: 180min costs 2.00' => [90, 8, 720, 540, '2.00'],
        ];
    }

    /** Mixed weekly durations consume their own real lengths, never an average. */
    public function test_mixed_durations_consume_their_own_real_lengths(): void
    {
        $sc = $this->course(['SessionCount' => 8, 'standard_lesson_minutes' => 120]);

        $tue = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00'); // 180
        $this->attend($sc->ID, $tue->id)->assertCreated();
        $this->assertSame(780, (int) $sc->fresh()->RemainingMinutes, '960 - 180');

        $sat = $this->makeSession($sc->ID, $this->pastDate(6), '10:00', '12:00'); // 120
        $this->attend($sc->ID, $sat->id)->assertCreated();
        $this->assertSame(660, (int) $sc->fresh()->RemainingMinutes, '780 - 120, not an averaged 150');
    }

    // ── fail-safe behaviour ───────────────────────────────────────────

    /** Flag off: an opted-in course still behaves exactly like fixed_session. */
    public function test_flag_off_keeps_an_opted_in_course_on_whole_session_deduction(): void
    {
        config(['perfflags.actual_duration_deduction_enabled' => false]);
        $sc = $this->course(['SessionCount' => 8, 'standard_lesson_minutes' => 120]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00'); // 180 minutes

        $this->attend($sc->ID, $cs->id)->assertCreated();

        $fresh = $sc->fresh();
        $this->assertSame(7, (int) $fresh->RemainingSessions, 'one whole lesson consumed');
        $this->assertNull(
            SessionDeductionLedger::where('student_class_id', $sc->ID)->value('minutes'),
            'whole-session encoding, no minutes recorded'
        );
    }

    /** Flag on but course not opted in: unchanged whole-session behaviour. */
    public function test_flag_on_with_fixed_session_course_is_unchanged(): void
    {
        $sc = $this->course(['SessionCount' => 8, 'deduction_basis' => DeductionBasis::FIXED_SESSION]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00'); // 180 vs 120 SessionDuration

        $this->attend($sc->ID, $cs->id)->assertCreated();

        $this->assertSame(7, (int) $sc->fresh()->RemainingSessions);
        $this->assertNull(SessionDeductionLedger::where('student_class_id', $sc->ID)->value('minutes'));
    }

    /**
     * Opted in but with no persisted standard: fail CLOSED to whole-session rather
     * than inventing a divisor. Guarded at create/update time too, but the engine
     * must not depend on that.
     */
    public function test_missing_standard_fails_closed_to_whole_session(): void
    {
        $sc = $this->course([
            'SessionCount' => 8,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => null,
        ]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00');

        $this->attend($sc->ID, $cs->id)->assertCreated();

        $this->assertSame(7, (int) $sc->fresh()->RemainingSessions, 'whole lesson, not a guessed 120-minute divisor');
        $this->assertNull(SessionDeductionLedger::where('student_class_id', $sc->ID)->value('minutes'));
    }

    /** A session exactly one standard long keeps the whole-session encoding. */
    public function test_session_exactly_one_standard_long_stays_a_whole_session(): void
    {
        $sc = $this->course(['SessionCount' => 8, 'standard_lesson_minutes' => 180]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00'); // exactly 180

        $this->attend($sc->ID, $cs->id)->assertCreated();

        $fresh = $sc->fresh();
        $this->assertSame(7, (int) $fresh->RemainingSessions);
        $this->assertSame(1260, (int) $fresh->RemainingMinutes, '8x180 - 180');
    }

    // ── reverse / idempotency ─────────────────────────────────────────

    /** Reverse must return the ORIGINAL minutes, not re-derive them from the contract. */
    public function test_reverse_restores_the_original_deducted_minutes(): void
    {
        $sc = $this->course(['SessionCount' => 8, 'standard_lesson_minutes' => 120]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00');

        $this->attend($sc->ID, $cs->id)->assertCreated();
        $this->assertSame(780, (int) $sc->fresh()->RemainingMinutes);

        SessionDeductionService::reverseForSession($sc->ID, (int) $cs->id, 'status_adjust');
        SessionDeductionService::recomputeCounters($sc->ID);

        $this->assertSame(960, (int) $sc->fresh()->RemainingMinutes, 'full restore, no drift');
        $this->assertSame(180, (int) SessionDeductionLedger::where('student_class_id', $sc->ID)
            ->where('event_type', 'reverse')->value('minutes'), 'reverse carries the original 180');
        $this->assertSame(0, $this->netDeductedMinutes($sc->ID));
    }

    /** Duplicate attendance must not consume the entitlement twice. */
    public function test_duplicate_attendance_does_not_double_consume(): void
    {
        $sc = $this->course(['SessionCount' => 8, 'standard_lesson_minutes' => 120]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00');

        $this->attend($sc->ID, $cs->id)->assertCreated();
        $this->attend($sc->ID, $cs->id);

        $this->assertSame(780, (int) $sc->fresh()->RemainingMinutes, 'still one 180-minute deduction');
        $this->assertSame(180, $this->netDeductedMinutes($sc->ID));
    }

    /** Over-consumption floors the stored balance at 0 but never loses ledger truth. */
    public function test_overconsumption_floors_balance_but_ledger_keeps_the_truth(): void
    {
        $sc = $this->course(['SessionCount' => 1, 'standard_lesson_minutes' => 120]);
        $cs = $this->makeSession($sc->ID, $this->pastDate(10), '14:00', '17:00'); // 180 > 120 purchased

        $this->attend($sc->ID, $cs->id)->assertCreated();

        $fresh = $sc->fresh();
        $this->assertSame(0, (int) $fresh->RemainingMinutes, 'floors at zero');
        $this->assertSame(180, $this->netDeductedMinutes($sc->ID), 'ledger still records the real 180');
        $this->assertSame(60, max(0, $this->netDeductedMinutes($sc->ID) - 120), 'uncovered is recoverable');
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function equivalent(StudentClass $sc): string
    {
        return (new \App\Services\Scheduling\LessonEntitlementCoverageCalculator())
            ->lessonEquivalent((int) $sc->RemainingMinutes, (int) $sc->standard_lesson_minutes);
    }

    private function netDeductedMinutes(int $scId): int
    {
        return (int) SessionDeductionLedger::where('student_class_id', $scId)
            ->selectRaw("SUM(CASE WHEN event_type='deduct' THEN minutes ELSE -minutes END) as net")
            ->value('net');
    }

    private function attend(int $scId, int $csId)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $this->studentId,
            'StudentClassID' => $scId,
            'ClassSessionID' => $csId,
            'Status' => 'present',
        ]);
    }

    /** Sessions must already be over before attendance is allowed. */
    private function pastDate(int $daysAgo): string
    {
        return Carbon::now()->subDays($daysAgo)->toDateString();
    }

    private function makeSession(int $scId, string $date, string $start, string $end): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $scId, 'SessionDate' => $date,
            'StartTime' => $start . ':00', 'EndTime' => $end . ':00', 'Status' => 'scheduled',
        ]);
    }

    private function mkUser(string $type, string $login): int
    {
        return (int) User::create([
            'LoginName' => $login, 'Name' => $type, 'PSW' => 'secret',
            'type' => $type, 'phone' => '0900000000', 'MustChangePassword' => false,
        ])->id;
    }

    private function course(array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $this->studentId, 'TeacherID' => $this->teacherId,
            'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'Rate' => 500, 'TotalHours' => 16, 'Charge' => 4000, 'Pay' => 4000, 'Paid' => 0,
            'Stop' => 0, 'by1' => 0, 'MDate' => now(),
            'StartDate' => Carbon::now()->subMonth()->toDateString(),
            'EndDate' => Carbon::now()->addMonths(6)->toDateString(),
        ], $over));
    }
}
