<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
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
use Tests\TestCase;

/**
 * #3834 — actual-duration pilot scoped to an allowlist of campuses
 * (`perfflags.actual_duration_campus_ids`). The allowlist is a third gate on top of
 * the environment flag and the course's own deduction_basis; outside it, an
 * opted-in course deducts exactly like fixed_session.
 *
 * Every test builds the allowlist from the campuses it created, so these tests
 * follow the config value rather than any particular production CampusID.
 */
class ActualDurationPilotCampusScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $teacherId;
    private int $pilotCampusId;
    private int $otherCampusId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfflags.actual_duration_deduction_enabled' => true]);

        $this->pilotCampusId = (int) Campus::factory()->create()->id;
        $this->otherCampusId = (int) Campus::factory()->create()->id;
        config(['perfflags.actual_duration_campus_ids' => (string) $this->pilotCampusId]);

        $this->teacherId = $this->mkUser('T', 'adp-t' . uniqid() . '@e.com');
        $dirId = $this->mkUser('A', 'adp-d' . uniqid() . '@e.com');
        foreach ([$this->pilotCampusId, $this->otherCampusId] as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $this->teacherId, 'Admin' => 0, 'Approved' => 1]);
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        }
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $this->token, 'expires_at' => now()->addDay()]);
    }

    public function test_allowlisted_campus_with_flag_on_deducts_actual_minutes(): void
    {
        [$sc, $studentId] = $this->course($this->pilotCampusId);

        $this->attend180($sc, $studentId);

        $this->assertSame(780, (int) $sc->fresh()->RemainingMinutes, '960 purchased - 180 actual');
        $this->assertSame(180, (int) $this->ledgerMinutes($sc));
    }

    public function test_campus_outside_the_allowlist_deducts_one_whole_lesson(): void
    {
        [$sc, $studentId] = $this->course($this->otherCampusId);

        $this->attend180($sc, $studentId);

        $this->assertSame(7, (int) $sc->fresh()->RemainingSessions, 'one whole lesson, as fixed_session');
        $this->assertNull($this->ledgerMinutes($sc), 'whole-session encoding, no minutes');
    }

    public function test_flag_off_is_fixed_session_even_at_an_allowlisted_campus(): void
    {
        config(['perfflags.actual_duration_deduction_enabled' => false]);
        [$sc, $studentId] = $this->course($this->pilotCampusId);

        $this->attend180($sc, $studentId);

        $this->assertSame(7, (int) $sc->fresh()->RemainingSessions);
        $this->assertNull($this->ledgerMinutes($sc));
    }

    /** Swap the allowlist: the campus that was out is now in, and vice versa. */
    public function test_deduction_follows_a_changed_allowlist(): void
    {
        config(['perfflags.actual_duration_campus_ids' => (string) $this->otherCampusId]);

        [$pilot, $pilotStudent] = $this->course($this->pilotCampusId);
        [$other, $otherStudent] = $this->course($this->otherCampusId);
        $this->attend180($pilot, $pilotStudent);
        $this->attend180($other, $otherStudent);

        $this->assertNull($this->ledgerMinutes($pilot), 'removed from the list -> fixed_session');
        $this->assertSame(180, (int) $this->ledgerMinutes($other), 'added to the list -> actual minutes');
    }

    public function test_allowlist_parsing_follows_config(): void
    {
        $a = $this->pilotCampusId;
        $b = $this->otherCampusId;

        config(['perfflags.actual_duration_campus_ids' => " {$b} , {$a} "]);
        $this->assertTrue(SessionDeductionService::isActualDurationCampusAllowed($a));
        $this->assertTrue(SessionDeductionService::isActualDurationCampusAllowed($b));

        config(['perfflags.actual_duration_campus_ids' => '']);
        $this->assertFalse(SessionDeductionService::isActualDurationCampusAllowed($a), 'empty = no campus');

        config(['perfflags.actual_duration_campus_ids' => '*']);
        $this->assertTrue(SessionDeductionService::isActualDurationCampusAllowed($b), '* = every campus');
        $this->assertFalse(SessionDeductionService::isActualDurationCampusAllowed(0), 'unknown campus never allowed, even under *');

        config(['perfflags.actual_duration_campus_ids' => (string) $a]);
        $this->assertFalse(SessionDeductionService::isActualDurationCampusAllowed(0), 'unknown campus never allowed');
    }

    public function test_create_api_refuses_an_actual_duration_course_outside_the_allowlist(): void
    {
        $studentId = $this->student($this->otherCampusId);

        $this->createCourse($studentId, $this->otherCampusId, DeductionBasis::ACTUAL_DURATION)
            ->assertStatus(422)
            ->assertJsonPath('errors.deduction_basis.0', '此分校尚未開放依實際時長扣堂，請改用「每次上課扣一堂」。');
        $this->assertSame(0, StudentClass::where('StudentID', $studentId)->count());

        // Fixed-session courses at the same campus are untouched.
        $this->createCourse($studentId, $this->otherCampusId, DeductionBasis::FIXED_SESSION)->assertCreated();
    }

    public function test_create_api_accepts_an_actual_duration_course_at_an_allowlisted_campus(): void
    {
        $studentId = $this->student($this->pilotCampusId);

        $this->createCourse($studentId, $this->pilotCampusId, DeductionBasis::ACTUAL_DURATION)->assertCreated();
        $this->assertTrue(StudentClass::where('StudentID', $studentId)->firstOrFail()->isActualDurationBasis());
    }

    public function test_edit_cannot_opt_a_course_in_outside_the_allowlist(): void
    {
        [$sc] = $this->course($this->otherCampusId, ['deduction_basis' => DeductionBasis::FIXED_SESSION]);

        $this->withHeaders($this->headers())
            ->putJson('/api/v1/student-classes/' . $sc->ID, [
                'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
                'standard_lesson_minutes' => 120,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.deduction_basis.0', '此分校尚未開放依實際時長扣堂，請改用「每次上課扣一堂」。');

        $this->assertSame(DeductionBasis::FIXED_SESSION, $sc->fresh()->deduction_basis);
    }

    public function test_edit_can_opt_a_course_in_at_an_allowlisted_campus(): void
    {
        [$sc] = $this->course($this->pilotCampusId, ['deduction_basis' => DeductionBasis::FIXED_SESSION]);

        $this->withHeaders($this->headers())
            ->putJson('/api/v1/student-classes/' . $sc->ID, [
                'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
                'standard_lesson_minutes' => 120,
            ])
            ->assertOk();

        $this->assertTrue($sc->fresh()->isActualDurationBasis());
    }

    /** An off-pilot course that is ALREADY actual_duration stays editable. */
    public function test_edit_of_an_existing_actual_duration_course_is_not_blocked(): void
    {
        [$sc] = $this->course($this->otherCampusId);

        $this->withHeaders($this->headers())
            ->putJson('/api/v1/student-classes/' . $sc->ID, [
                'deduction_basis' => DeductionBasis::ACTUAL_DURATION,
                'standard_lesson_minutes' => 90,
            ])
            ->assertOk();

        $this->assertSame(90, $sc->fresh()->resolvedStandardLessonMinutes());
    }

    /** Renewal / tutoring copies never mint a new actual_duration contract off-pilot. */
    /**
     * Codex P1 on #3948: a course that recorded a minute event while piloting must,
     * once its campus leaves the allowlist, count every attendance as one whole lesson.
     * SessionDuration (180) differs from the standard (120) so the old 90-minute row
     * would otherwise be re-read as a partial lesson against 180.
     */
    public function test_removing_the_campus_recounts_history_as_whole_lessons(): void
    {
        [$sc, $studentId] = $this->course($this->pilotCampusId, ['SessionDuration' => 180]);
        $this->attend($sc, $studentId, '14:00:00', '15:30:00', 12);
        $this->assertSame(90, (int) $this->ledgerMinutes($sc), 'live pilot records actual minutes');

        config(['perfflags.actual_duration_campus_ids' => (string) $this->otherCampusId]);
        $this->attend($sc, $studentId, '14:00:00', '17:00:00', 10);
        SessionDeductionService::recomputeCounters($sc->ID);

        $fresh = $sc->fresh();
        $this->assertSame(6, (int) $fresh->RemainingSessions, 'two attendances = two whole lessons');
        $this->assertSame(2, (int) $fresh->UsedSessions);
        $this->assertSame(6 * 180, (int) $fresh->RemainingMinutes, 'no fractional balance');
        $diag = SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $sc->ID])[(int) $sc->ID];
        $this->assertSame(2, $diag['expected_used'], 'the batch diagnostic agrees with the persisted counters');
        $row = collect($this->withHeaders($this->headers())->getJson('/api/v1/student-classes?per_page=200')->assertOk()->json('data'))
            ->firstWhere('ID', $sc->ID);
        $this->assertSame(['6.00', '2.00'], [$row['remaining_lesson_equivalent'] ?? null, $row['used_lesson_equivalent'] ?? null]);

        config(['perfflags.actual_duration_campus_ids' => (string) $this->pilotCampusId]);
        SessionDeductionService::recomputeCounters($sc->ID);
        $this->assertSame(8 * 120 - 90 - 120, (int) $sc->fresh()->RemainingMinutes, 'back in the pilot: minutes again');
    }

    public function test_copied_basis_follows_the_allowlist(): void
    {
        [$pilot] = $this->course($this->pilotCampusId);
        [$other] = $this->course($this->otherCampusId);
        [$fixed] = $this->course($this->pilotCampusId, ['deduction_basis' => DeductionBasis::FIXED_SESSION]);

        $this->assertSame(DeductionBasis::ACTUAL_DURATION, SessionDeductionService::pilotSafeCopiedBasis($pilot));
        $this->assertSame(DeductionBasis::FIXED_SESSION, SessionDeductionService::pilotSafeCopiedBasis($other));
        $this->assertSame(DeductionBasis::FIXED_SESSION, SessionDeductionService::pilotSafeCopiedBasis($fixed));

        config(['perfflags.actual_duration_campus_ids' => '*']);
        $this->assertSame(DeductionBasis::ACTUAL_DURATION, SessionDeductionService::pilotSafeCopiedBasis($other));
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
    }

    private function attend180(StudentClass $sc, int $studentId): void
    {
        $this->attend($sc, $studentId, '14:00:00', '17:00:00', 10);
    }

    private function attend(StudentClass $sc, int $studentId, string $start, string $end, int $daysAgo): void
    {
        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => Carbon::now()->subDays($daysAgo)->toDateString(),
            'StartTime' => $start, 'EndTime' => $end, 'Status' => 'scheduled',
        ]);

        $this->withHeaders($this->headers())->postJson('/api/v1/attendance', [
            'StudentID' => $studentId,
            'StudentClassID' => $sc->ID,
            'ClassSessionID' => $cs->id,
            'Status' => 'present',
        ])->assertCreated();
    }

    private function ledgerMinutes(StudentClass $sc): mixed
    {
        return SessionDeductionLedger::where('student_class_id', $sc->ID)
            ->where('event_type', 'deduct')->value('minutes');
    }

    private function student(int $campusId): int
    {
        return (int) Student::create([
            'name' => '試辦' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
    }

    /** @return array{0: StudentClass, 1: int} */
    private function course(int $campusId, array $over = []): array
    {
        $studentId = $this->student($campusId);
        $sc = StudentClass::create(array_merge([
            'StudentID' => $studentId, 'TeacherID' => $this->teacherId,
            'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0,
            'deduction_basis' => DeductionBasis::ACTUAL_DURATION, 'standard_lesson_minutes' => 120,
            'Rate' => 500, 'TotalHours' => 16, 'Charge' => 4000, 'Pay' => 4000, 'Paid' => 0,
            'Stop' => 0, 'by1' => 0, 'MDate' => now(),
            'StartDate' => Carbon::now()->subMonth()->toDateString(),
            'EndDate' => Carbon::now()->addMonths(6)->toDateString(),
        ], $over));

        return [$sc, $studentId];
    }

    private function createCourse(int $studentId, int $campusId, string $basis)
    {
        $plan = [];
        for ($i = 0; $i < 6; $i++) {
            $plan[] = [
                'session_date' => Carbon::now()->addDays(7 + $i * 7)->toDateString(),
                'start_time' => '14:00',
                'kind' => 'future',
                'duration_minutes' => 120,
            ];
        }
        $payload = [
            'branch_id' => $campusId,
            'confirmed_dates' => [],
            'future_dates' => [],
            'student_id' => $studentId,
            'teacher_id' => $this->teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'payment_type' => 'session',
            'price_per_session' => 500,
            'duration_minutes' => 120,
            'start_time' => '14:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'course_start_date' => Carbon::now()->addDays(7)->toDateString(),
            'total_classes' => 6,
            'session_plan' => $plan,
            'deduction_basis' => $basis,
        ];
        if ($basis === DeductionBasis::ACTUAL_DURATION) {
            $payload['standard_lesson_minutes'] = 120;
        }

        return $this->withHeaders($this->headers())->postJson('/api/v1/class-sessions/batch', $payload);
    }

    private function mkUser(string $type, string $login): int
    {
        return (int) User::create([
            'LoginName' => $login, 'Name' => $type, 'PSW' => 'secret',
            'type' => $type, 'phone' => '0900000000', 'MustChangePassword' => false,
        ])->id;
    }
}
