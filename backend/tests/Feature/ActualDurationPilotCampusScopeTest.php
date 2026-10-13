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

/** #3834: actual-duration pilot; every campus ID and allowlist comes from test fixtures. */
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
    public function test_removing_the_campus_recounts_history_as_whole_lessons(): void
    {
        foreach ([\App\Models\Schedule::STATUS_SUPERSEDED, 'cancelled'] as $staleStatus) {
            [$sc, $studentId] = $this->course($this->pilotCampusId, ['SessionDuration' => 180]);
            \App\Models\Schedule::create([
                'student_id' => $studentId, 'student_course_id' => $sc->ID, 'teacher_id' => $this->teacherId, 'branch_id' => $this->pilotCampusId,
                'subject' => 'Math', 'schedule_date' => Carbon::now()->subDays(12)->toDateString(), 'day_of_week' => Carbon::now()->subDays(12)->dayOfWeekIso, 'start_time' => '14:00', 'end_time' => '15:30',
                'duration_hours' => 1.5, 'class_type' => 'one_on_one', 'status' => $staleStatus, 'type' => 'extra', 'deduction' => 1,
            ]);
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
            $this->assertSame([null, null], [$row['remaining_lesson_equivalent'] ?? null, $row['used_lesson_equivalent'] ?? null], 'no minute equivalents while gated off');
            config(['perfflags.actual_duration_campus_ids' => (string) $this->pilotCampusId]);
            SessionDeductionService::recomputeCounters($sc->ID);
            $this->assertSame(8 * 120 - 90 - 120, (int) $sc->fresh()->RemainingMinutes, 'back in the pilot: minutes again');
        }
    }
    public function test_a_gated_off_course_still_prorates_a_makeup(): void
    {
        foreach ([[$this->otherCampusId, 120, 90, '15:30'], [$this->pilotCampusId, 180, 120, '16:00']] as [$campusId, $duration, $minutes, $endTime]) {
            config(['perfflags.actual_duration_deduction_enabled' => true]);
            [$sc, $studentId] = $this->course($campusId, ['SessionDuration' => $duration]);
            $date = Carbon::now()->subDays(10)->toDateString();
            $schedule = \App\Models\Schedule::create([
                'student_id' => $studentId, 'teacher_id' => $this->teacherId, 'subject' => 'Math', 'day_of_week' => Carbon::parse($date)->dayOfWeekIso,
                'start_time' => '14:00', 'end_time' => $endTime, 'duration_hours' => $minutes / 60, 'class_type' => 'one_on_one', 'status' => 'scheduled',
                'type' => 'extra', 'deduction' => 1, 'branch_id' => $campusId, 'schedule_date' => $date, 'student_course_id' => $sc->ID,
            ]);
            $this->attend($sc, $studentId, '14:00:00', $endTime . ':00', 10);
            SessionDeductionService::recomputeCounters($sc->ID);
            $this->assertSame(8 * SessionDeductionService::billingStandardMinutes($sc) - $minutes, (int) $sc->fresh()->RemainingMinutes);
            $this->assertSame(1, SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $sc->ID])[(int) $sc->ID]['expected_used']);
            \App\Models\Schedule::query()->where('student_course_id', $sc->ID)->update(['status' => \App\Models\Schedule::STATUS_SUPERSEDED]);
            SessionDeductionService::recomputeCounters($sc->ID);
            $this->assertSame(8 * SessionDeductionService::billingStandardMinutes($sc) - $minutes, (int) $sc->fresh()->RemainingMinutes);
            $this->withHeaders($this->headers())->deleteJson('/api/v1/schedules/' . $schedule->id)->assertOk();
            $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
            config(['perfflags.actual_duration_deduction_enabled' => false]);
            SessionDeductionService::recomputeCounters($sc->ID);
            $this->assertSame(8 * $duration - $minutes, (int) $sc->fresh()->RemainingMinutes);
            $diag = SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $sc->ID])[(int) $sc->ID];
            $this->assertSame($minutes, $diag['ledger_net_minutes']);
            $this->assertFalse($diag['ambiguous_minute_evidence']);
            $sessionId = (int) SessionDeductionLedger::where('student_class_id', $sc->ID)->where('event_type', 'deduct')->value('class_session_id');
            $this->assertTrue(SessionDeductionService::reverseForSession($sc->ID, $sessionId));
            SessionDeductionService::recomputeCounters($sc->ID);
            $this->assertSame(8 * $duration, (int) $sc->fresh()->RemainingMinutes);
            $reverse = SessionDeductionLedger::where('student_class_id', $sc->ID)->where('event_type', 'reverse')->firstOrFail();
            $this->assertSame($minutes, (int) $reverse->minutes);
            $this->assertSame('retro_leave_makeup', $reverse->source);
        }
    }
    public function test_unmarked_minute_history_is_held_until_matching_reversal(): void
    {
        [$sc, $studentId] = $this->course($this->pilotCampusId);
        $this->attend($sc, $studentId, '14:00:00', '15:30:00', 10);
        SessionDeductionLedger::where('student_class_id', $sc->ID)->update(['source' => 'attendance']);
        \App\Models\Schedule::create([
            'student_id' => $studentId, 'student_course_id' => $sc->ID, 'teacher_id' => $this->teacherId, 'branch_id' => $this->pilotCampusId, 'subject' => 'Math', 'day_of_week' => Carbon::now()->subDays(10)->dayOfWeekIso,
            'schedule_date' => Carbon::now()->subDays(10)->toDateString(), 'start_time' => '14:00', 'end_time' => '15:30', 'duration_hours' => 1.5, 'class_type' => 'one_on_one', 'status' => 'cancelled', 'type' => 'extra', 'deduction' => 1,
        ]);
        $before = $sc->fresh()->getAttributes();
        config(['perfflags.actual_duration_deduction_enabled' => false]);
        SessionDeductionService::recomputeCounters($sc->ID);
        $this->assertSame($before, $sc->fresh()->getAttributes());
        $diag = SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $sc->ID])[(int) $sc->ID];
        $this->assertTrue($diag['ambiguous_minute_evidence']);
        $this->assertTrue(SessionDeductionService::usageBalanceAssessment($sc->fresh(), $diag)['review_required']);
        $pending = ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => Carbon::now()->subDays(8)->toDateString(), 'StartTime' => '14:00:00', 'EndTime' => '16:00:00', 'Status' => 'scheduled']);
        $evidenceCounts = [\App\Models\StudentSignIn::count(), SessionDeductionLedger::count()];
        $this->withHeaders($this->headers())->postJson('/api/v1/attendance', ['StudentID' => $studentId, 'StudentClassID' => $sc->ID, 'ClassSessionID' => $pending->id, 'Status' => 'present'])->assertStatus(422);
        $this->assertSame('scheduled', $pending->fresh()->Status);
        $this->assertSame($evidenceCounts, [\App\Models\StudentSignIn::count(), SessionDeductionLedger::count()]);
        $this->assertSame($before, $sc->fresh()->getAttributes());
        $sessionId = (int) SessionDeductionLedger::where('student_class_id', $sc->ID)->value('class_session_id');
        $this->assertTrue(SessionDeductionService::reverseForSession($sc->ID, $sessionId));
        \App\Models\StudentSignIn::where('ClassSessionID', $sessionId)->update(['VoidedAt' => now()]);
        ClassSession::whereKey($sessionId)->update(['Status' => 'scheduled']);
        SessionDeductionService::recomputeCounters($sc->ID);
        $this->assertSame(960, (int) $sc->fresh()->RemainingMinutes);
        $this->withHeaders($this->headers())->postJson('/api/v1/attendance', ['StudentID' => $studentId, 'StudentClassID' => $sc->ID, 'ClassSessionID' => $pending->id, 'Status' => 'present'])->assertCreated();
        $this->assertSame([1, 7], [(int) $sc->fresh()->UsedSessions, (int) $sc->fresh()->RemainingSessions]);
        $this->assertFalse(SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $sc->ID])[(int) $sc->ID]['ambiguous_minute_evidence']);
    }
    public function test_purchase_batch_api_preserves_only_an_enabled_pilot_contract(): void
    {
        foreach ([[$this->pilotCampusId, true, DeductionBasis::ACTUAL_DURATION, 90], [$this->otherCampusId, true, DeductionBasis::FIXED_SESSION, null], [$this->pilotCampusId, false, DeductionBasis::FIXED_SESSION, null]] as [$campus, $enabled, $basis, $standard]) {
            config(['perfflags.actual_duration_deduction_enabled' => $enabled]);
            [$source] = $this->course($campus, ['standard_lesson_minutes' => 90, 'SessionDuration' => 180]);
            $result = $this->withHeaders($this->headers())->postJson('/api/v1/student-classes/' . $source->ID . '/purchase-batch', [
                'sessions' => 4, 'start_date' => Carbon::now()->addMonths(8)->toDateString(),
            ])->assertCreated();
            $new = StudentClass::findOrFail($result->json('new_course.id'));
            $this->assertSame($basis, $new->deduction_basis);
            $this->assertSame($standard, $new->standard_lesson_minutes);
            $this->assertSame(DeductionBasis::ACTUAL_DURATION, $source->fresh()->deduction_basis);
            $this->assertSame(90, $source->fresh()->standard_lesson_minutes);
        }
    }
    public function test_copied_basis_follows_the_allowlist(): void
    {
        [$pilot] = $this->course($this->pilotCampusId);
        [$other] = $this->course($this->otherCampusId, ['SessionDuration' => 180]);
        [$fixed] = $this->course($this->pilotCampusId, ['deduction_basis' => DeductionBasis::FIXED_SESSION]);
        $this->assertSame(DeductionBasis::ACTUAL_DURATION, SessionDeductionService::pilotSafeCopiedBasis($pilot));
        $this->assertSame(DeductionBasis::FIXED_SESSION, SessionDeductionService::pilotSafeCopiedBasis($other));
        $this->assertSame(DeductionBasis::FIXED_SESSION, SessionDeductionService::pilotSafeCopiedBasis($fixed));
        [$copy] = $this->course($this->otherCampusId, array_merge(['SessionDuration' => 180], SessionDeductionService::pilotSafeCopiedContract($other)));
        SessionDeductionService::syncCounters($copy);
        $row = collect($this->withHeaders($this->headers())->getJson('/api/v1/student-classes?per_page=200')->assertOk()->json('data'))->firstWhere('ID', $copy->ID);
        $this->assertSame([8, null], [(int) $row['RemainingSessions'], $row['remaining_lesson_equivalent']]);
        config(['perfflags.actual_duration_campus_ids' => '*']);
        $this->assertSame(DeductionBasis::ACTUAL_DURATION, SessionDeductionService::pilotSafeCopiedBasis($other));
    }


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
