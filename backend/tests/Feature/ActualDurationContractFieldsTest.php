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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 1 — additive contract fields and
 * the contract lock. No runtime deduction behaviour changes in this phase.
 */
class ActualDurationContractFieldsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $teacherId = $this->mkUser('T', 'ad-t' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacherId, 'Admin' => 0, 'Approved' => 1]);
        $dirId = $this->mkUser('A', 'ad-d' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $this->token, 'expires_at' => now()->addDay()]);

        $this->studentId = (int) Student::create([
            'name' => '契約' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
    }

    public function test_migration_adds_both_contract_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('StudentClass', 'standard_lesson_minutes'));
        $this->assertTrue(Schema::hasColumn('StudentClass', 'deduction_basis'));
    }

    /**
     * The whole safety story rests on this: every pre-existing row must read as
     * fixed_session, not as an ambiguous NULL that some later branch might treat
     * as "unset, so guess".
     */
    public function test_existing_courses_default_to_fixed_session_with_no_standard(): void
    {
        $sc = $this->course();

        $this->assertSame(DeductionBasis::FIXED_SESSION, $sc->deduction_basis);
        $this->assertNull($sc->standard_lesson_minutes);
        $this->assertFalse($sc->isActualDurationBasis());
        $this->assertNull($sc->resolvedStandardLessonMinutes());
    }

    /**
     * resolvedStandardLessonMinutes() must NOT fall back to SessionDuration or to any
     * house default — a course with no persisted standard is simply not set up for
     * actual-duration billing, and callers must fail closed rather than assume 120.
     */
    public function test_resolved_standard_never_falls_back_to_session_duration(): void
    {
        $sc = $this->course(['SessionDuration' => 120, 'standard_lesson_minutes' => null]);
        $this->assertNull($sc->resolvedStandardLessonMinutes(), 'must not borrow SessionDuration');

        $explicit = $this->course(['SessionDuration' => 120, 'standard_lesson_minutes' => 90]);
        $this->assertSame(90, $explicit->resolvedStandardLessonMinutes(), 'per-course value wins');
    }

    /** A course may define any legal standard; 120 carries no special status. */
    public function test_any_legal_per_course_standard_can_be_persisted(): void
    {
        foreach ([30, 45, 90, 120, 180, 480] as $minutes) {
            $sc = $this->course([
                'standard_lesson_minutes' => $minutes,
                'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            ]);
            $this->assertSame($minutes, $sc->fresh()->resolvedStandardLessonMinutes());
            $this->assertTrue($sc->fresh()->isActualDurationBasis());
        }
    }

    // ── update-path validation ────────────────────────────────────────

    public function test_update_rejects_unknown_deduction_basis(): void
    {
        $sc = $this->course();

        $this->patchCourse($sc, ['deduction_basis' => 'per_minute'])
            ->assertStatus(422)
            ->assertJsonPath('errors.deduction_basis.0', '扣堂方式無效。');
    }

    /** @dataProvider outOfRangeStandardProvider */
    public function test_update_rejects_out_of_range_standard(int $minutes): void
    {
        $sc = $this->course();

        $this->patchCourse($sc, [
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => $minutes,
        ])->assertStatus(422);
    }

    /** @return array<string, array{0:int}> */
    public static function outOfRangeStandardProvider(): array
    {
        return ['below minimum' => [29], 'above maximum' => [481], 'zero' => [0], 'negative' => [-120]];
    }

    /** Fail closed: actual_duration with no standard has nothing to divide by. */
    public function test_update_rejects_actual_duration_without_a_standard(): void
    {
        $sc = $this->course();

        $this->patchCourse($sc, ['deduction_basis' => DeductionBasis::ACTUAL_DURATION])
            ->assertStatus(422)
            ->assertJsonPath('errors.standard_lesson_minutes.0', '請先設定標準一堂時長（分鐘）。');

        $this->assertSame(DeductionBasis::FIXED_SESSION, $sc->fresh()->deduction_basis);
    }

    public function test_update_accepts_a_valid_opt_in_before_any_deduction(): void
    {
        $sc = $this->course();

        $this->patchCourse($sc, [
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 90,
        ])->assertOk();

        $fresh = $sc->fresh();
        $this->assertTrue($fresh->isActualDurationBasis());
        $this->assertSame(90, $fresh->resolvedStandardLessonMinutes());
    }

    // ── D4: CoursePackage mutual exclusion ────────────────────────────

    public function test_package_member_cannot_switch_to_actual_duration(): void
    {
        $pkgId = DB::table('course_packages')->insertGetId([
            'student_id' => $this->studentId, 'campus_id' => 1, 'name' => 'Pkg',
            'total_sessions' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sc = $this->course(['PackageID' => $pkgId]);

        $this->patchCourse($sc, [
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
        ])->assertStatus(422)
            ->assertJsonPath('errors.deduction_basis.0', '共用課程包不支援依實際時長扣堂。');

        $this->assertSame(DeductionBasis::FIXED_SESSION, $sc->fresh()->deduction_basis);
    }

    // ── contract lock ─────────────────────────────────────────────────

    public function test_contract_is_editable_before_the_first_deduction(): void
    {
        $sc = $this->course([
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
        ]);
        $this->assertFalse($sc->hasDeductionHistory());

        $this->patchCourse($sc, ['standard_lesson_minutes' => 180])->assertOk();
        $this->assertSame(180, $sc->fresh()->resolvedStandardLessonMinutes());
    }

    /**
     * After any deduction the standard is frozen. Entitlement is still derived as
     * SessionCount x standard_lesson_minutes, so allowing an edit here would silently
     * re-price every deduction already recorded.
     */
    public function test_standard_is_locked_after_the_first_deduction(): void
    {
        $sc = $this->course([
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
        ]);
        $this->ledger($sc->ID);

        $this->patchCourse($sc, ['standard_lesson_minutes' => 180])
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_contract_locked');

        $this->assertSame(120, $sc->fresh()->resolvedStandardLessonMinutes(), 'unchanged');
    }

    public function test_deduction_basis_and_purchased_units_are_locked_after_the_first_deduction(): void
    {
        $sc = $this->course([
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
            'SessionCount' => 8,
        ]);
        $this->ledger($sc->ID);

        $this->patchCourse($sc, ['deduction_basis' => DeductionBasis::FIXED_SESSION])
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_contract_locked');

        $this->patchCourse($sc, ['sessions_purchased' => 12])
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_contract_locked');

        $fresh = $sc->fresh();
        $this->assertTrue($fresh->isActualDurationBasis());
        $this->assertSame(8, (int) $fresh->SessionCount);
    }

    /**
     * Ordinary "save the whole form" edits resubmit the current contract values
     * unchanged. That must keep working after a deduction — only a genuine change
     * is blocked, otherwise the lock would freeze the entire edit screen.
     */
    public function test_unrelated_edits_still_work_after_a_deduction(): void
    {
        $sc = $this->course([
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'standard_lesson_minutes' => 120,
            'SessionCount' => 8,
        ]);
        $this->ledger($sc->ID);

        $this->patchCourse($sc, [
            'memo' => '更新備註',
            'standard_lesson_minutes' => 120,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
            'sessions_purchased' => 8,
        ])->assertOk();

        $this->assertSame('更新備註', $sc->fresh()->Memo);
    }

    /** fixed_session courses are equally protected once they have consumed anything. */
    public function test_lock_also_applies_to_fixed_session_courses(): void
    {
        $sc = $this->course(['SessionCount' => 8]);
        $this->ledger($sc->ID);

        $this->patchCourse($sc, ['sessions_purchased' => 20])
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_contract_locked');
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function patchCourse(StudentClass $sc, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/student-classes/' . $sc->ID, $payload);
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
            'StudentID' => $this->studentId, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0,
            'Rate' => 500, 'TotalHours' => 16, 'Charge' => 4000, 'Pay' => 4000, 'Paid' => 0,
            'Stop' => 0, 'by1' => 0, 'MDate' => now(),
            'StartDate' => Carbon::now()->subMonth()->toDateString(),
            'EndDate' => Carbon::now()->addMonths(3)->toDateString(),
        ], $over));
    }

    private function ledger(int $scId): void
    {
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $scId, 'class_session_id' => 1, 'event_type' => 'deduct',
            'source' => 'attendance', 'minutes' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
