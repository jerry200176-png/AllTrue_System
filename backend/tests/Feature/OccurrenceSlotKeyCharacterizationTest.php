<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ClassSessionMaterializationService;
use App\Services\Scheduling\CommitmentReasonCodes;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Services\Scheduling\PreviewSessionHorizonService;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OCC-P0 characterization (docs: ARCH-OCCURRENCE-IDENTITY-20261011, CT-1..CT-7).
 *
 * These tests PIN CURRENT BEHAVIOR. No production code changes. A ClassSession does not remember which
 * series occurrence it started as; every module pairs rows by the CURRENT slot (course|date|HH:MM), so a
 * move breaks the pairing. Assertions tagged "DOCUMENTS CURRENT BUG" pin the latent behavior on purpose:
 * when the named phase lands, that assertion is supposed to flip and the test is edited in the same PR.
 * Series = Tuesday 16:30; today = Monday 2026-07-13; the occurrence under test is Tue 2026-07-14.
 */
class OccurrenceSlotKeyCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const TUE = '2026-07-14';
    private const WED = '2026-07-15';
    private const THU = '2026-07-16';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- CT-1

    /** CT-1: ADR-006 Preview after an occurrence moved Tue->Wed (current behavior: Tuesday is planned again). */
    public function test_ct1_preview_replans_the_vacated_original_slot_after_a_move(): void
    {
        $ctx = $this->seedSeries();
        $this->reschedule($ctx['token'], $ctx['course'], self::TUE, self::WED)->assertOk();

        $moved = ClassSession::findOrFail($ctx['tue']->id);
        $this->assertSame(self::WED, substr((string) $moved->SessionDate, 0, 10), 'moved in place, same id');
        $this->assertSame($ctx['tue']->id, $moved->id);

        $planned = $this->previewStates($ctx['course']);

        // DOCUMENTS CURRENT BUG (F4), fixed in P4: Preview subtracts by CURRENT slot and reads no schedules
        // rows, so the vacated Tuesday looks empty and is planned a second time (extra pool coverage).
        $this->assertContains($planned[self::TUE.'|16:30'] ?? null, ['planned_covered', 'planned_uncovered'],
            'CURRENT: original Tue slot is re-planned although its occurrence moved to Wed');
        // The moved occurrence's own session is not part of the expansion at all (its current slot is off-series).
        $this->assertArrayNotHasKey(self::WED.'|16:30', $planned);
        // Untouched later Tuesdays are planned normally.
        $this->assertArrayHasKey('2026-07-21|16:30', $planned);
    }

    /** CT-1 target (P4): the moved occurrence must not be planned again. */
    public function test_ct1_target_preview_does_not_replan_a_moved_occurrence(): void
    {
        $this->markTestIncomplete('Target for P4 (identity-based EXDATE): Tue 2026-07-14 16:30 must be absent/exists after the move.');
    }

    // ---------------------------------------------------------------- CT-2

    /**
     * CT-2: monthly (ScheduleMode=date) same-day teacher read-repair holds the vacated original slot ONLY
     * through the `rescheduled` schedules anchor (the EXDATE). Without it the slot is materialized again.
     */
    public function test_ct2_monthly_read_repair_depends_on_the_rescheduled_anchor(): void
    {
        $ctx = $this->seedSeries(mode: 'date', withHistory: false);
        // The Tue occurrence moved to Wed (in place) and the reschedule wrote its anchor on the original slot.
        DB::table('ClassSession')->where('id', $ctx['tue']->id)->update(['SessionDate' => self::WED]);
        $anchorId = DB::table('schedules')->insertGetId($this->scheduleRow($ctx['course'], self::TUE, '16:30', 'rescheduled'));

        $this->teacherListToday($ctx['teacherToken'], self::TUE)->assertOk();
        $this->assertSame(0, $this->sessionsAt($ctx['course'], self::TUE, '16:30'),
            'anchor present: the vacated slot is not re-materialized');

        // DOCUMENTS CURRENT BUG (F4), fixed in P4: RFC Phase 5 (stop writing the anchor) removes the only EXDATE.
        DB::table('schedules')->where('id', $anchorId)->delete();
        $this->teacherListToday($ctx['teacherToken'], self::TUE)->assertOk();
        $this->assertSame(1, $this->sessionsAt($ctx['course'], self::TUE, '16:30'),
            'CURRENT: without the anchor the read-repair materializes the moved occurrence a second time');
        $this->assertSame(1, $this->sessionsAt($ctx['course'], self::WED, '16:30'), 'the moved row is still there: two live rows, one occurrence');
    }

    // ---------------------------------------------------------------- CT-3

    /**
     * CT-3 (backend half): move -> substitute -> restore -> move. The ClassSession id never changes, but the
     * slot-derived key (occurrenceKey, the key behind teacherForOccurrence/teachersForOccurrences) changes with every move.
     * Frontend half: frontend/src/lib/occurrenceSlotKeyCharacterization.test.js.
     */
    public function test_ct3_session_id_is_stable_but_slot_keys_move_through_move_substitute_restore_move(): void
    {
        $ctx = $this->seedSeries(withHistory: false);
        $id = $ctx['tue']->id;
        $a = $ctx['teacher'];
        $b = $ctx['sub'];
        $key = fn (string $d) => SubstituteScheduleService::occurrenceKey($ctx['course'], $d, '16:30');
        $teacherAt = fn (string $d) => SubstituteScheduleService::teacherForOccurrence($ctx['course'], $d, $a, '16:30');

        $this->reschedule($ctx['token'], $ctx['course'], self::TUE, self::WED)->assertOk();
        $this->assertSame(self::WED, $this->dateOf($id));
        $this->assertSame($a, $teacherAt(self::WED));

        $this->api($ctx['token'])->postJson("/api/v1/class-sessions/{$id}/substitute", ['substitute_teacher_id' => $b, 'reason' => 'ct3'])->assertOk();
        $this->assertSame($b, $teacherAt(self::WED));
        $this->assertSame($a, $teacherAt(self::TUE), 'old slot resolves to the contract teacher: the key does not follow the occurrence');

        $this->api($ctx['token'])->postJson("/api/v1/class-sessions/{$id}/substitute/undo")->assertOk();
        $this->assertSame($a, $teacherAt(self::WED));

        $this->reschedule($ctx['token'], $ctx['course'], self::WED, self::THU)->assertOk();
        $this->assertSame(self::THU, $this->dateOf($id));
        $this->assertSame($a, $teacherAt(self::THU));

        // The calendar/course-lookup/picker keys are the session id and survive; the backend slot key does not.
        $this->assertSame($id, ClassSession::findOrFail($id)->id);
        $keys = [$key(self::TUE), $key(self::WED), $key(self::THU)];
        $this->assertCount(3, array_unique($keys), 'CURRENT: one occurrence has three different slot keys after two moves');
        // DOCUMENTS CURRENT BUG (F7/F8), fixed in P1+P5: ClassSession has no Original* columns to carry a frozen identity.
        $this->assertFalse(Schema::hasColumn('ClassSession', 'OriginalSessionDate'));
        $this->assertFalse(Schema::hasColumn('ClassSession', 'OriginalStartTimeHM'));
    }

    // ---------------------------------------------------------------- CT-4

    /** CT-4: leave on an already-moved occurrence: exactly one live row for that slot, no deduction. */
    public function test_ct4_leave_on_a_moved_occurrence_keeps_one_live_row_and_does_not_deduct(): void
    {
        $ctx = $this->seedSeries(withHistory: false);
        $this->reschedule($ctx['token'], $ctx['course'], self::TUE, self::WED)->assertOk();
        $before = StudentClass::where('ID', $ctx['course'])->first(['UsedSessions', 'RemainingSessions'])->toArray();

        $this->api($ctx['teacherToken'])->postJson('/api/v1/attendance', [
            'StudentID' => $ctx['student'], 'StudentClassID' => $ctx['course'],
            'ClassSessionID' => $ctx['tue']->id, 'Status' => 'leave', 'mark_mode' => 'arrival',
        ])->assertCreated();

        $this->assertSame('leave', strtolower((string) ClassSession::findOrFail($ctx['tue']->id)->Status));
        $this->assertSame(self::WED, $this->dateOf($ctx['tue']->id), 'the leave is on the moved row, same id');
        $this->assertSame(0, $this->sessionsAt($ctx['course'], self::TUE, '16:30'), 'nothing at the vacated slot');
        $liveAtWed = ClassSession::where('StudentClassID', $ctx['course'])->whereDate('SessionDate', self::WED)
            ->whereRaw("LOWER(Status) <> 'cancelled'")->count();
        $this->assertSame(1, $liveAtWed, 'exactly one live row on the moved slot');
        $after = StudentClass::where('ID', $ctx['course'])->first(['UsedSessions', 'RemainingSessions'])->toArray();
        $this->assertSame($before, $after, 'leave does not deduct');
    }

    // ---------------------------------------------------------------- CT-5

    /** CT-5: reassignContract: each contract's expansion and the duplicate count before/after. */
    public function test_ct5_reassign_contract_leaves_no_exdate_on_the_source_series(): void
    {
        $ctx = $this->seedSeries();
        // Second contract, same student + subject, Wednesday series (so Tue is off-series there).
        $new = $this->makeCourse($ctx['student'], $ctx['teacher'], ['week' => 3, 'time' => '16:30:00']);
        foreach (['2026-06-24', '2026-07-01', '2026-07-08'] as $d) {
            $this->mkSession($new, $d, 'attended');
        }

        $oldBefore = $this->previewStates($ctx['course']);
        $this->assertSame('exists', $oldBefore[self::TUE.'|16:30'] ?? null);

        $this->api($ctx['token'])->postJson("/api/v1/class-sessions/{$ctx['tue']->id}/reassign-contract", [
            'new_student_class_id' => $new, 'reason' => 'ct5',
        ])->assertOk();

        $this->assertSame($new, (int) ClassSession::findOrFail($ctx['tue']->id)->StudentClassID);
        $live = ClassSession::whereIn('StudentClassID', [$ctx['course'], $new])->whereDate('SessionDate', self::TUE)
            ->whereRaw('SUBSTRING(StartTime,1,5) = ?', ['16:30'])->whereRaw("LOWER(Status) <> 'cancelled'")->count();
        $this->assertSame(1, $live, 'one live row for the lesson across both contracts');

        // DOCUMENTS CURRENT BUG (F9), fixed in P4/P5: no EXDATE is left on the source series, so its
        // Preview plans the same Tuesday again while the reassigned row sits on the other contract.
        $oldAfter = $this->previewStates($ctx['course']);
        $this->assertContains($oldAfter[self::TUE.'|16:30'] ?? null, ['planned_covered', 'planned_uncovered'],
            'CURRENT: source series re-plans the reassigned occurrence');
        // The new contract's series does not contain Tuesday, so nothing there knows about it either.
        $this->assertArrayNotHasKey(self::TUE.'|16:30', $this->previewStates($new));
    }

    // ---------------------------------------------------------------- CT-6

    /** CT-6: series time change (syncFutureScheduledSessionTimes) leaves exception and taught sessions alone. */
    public function test_ct6_series_time_change_does_not_touch_exception_or_taught_sessions(): void
    {
        $ctx = $this->seedSeries(mode: 'count', withHistory: false);
        $plain = $ctx['tue']; // regular, scheduled, on-series
        $exception = $this->mkSession($ctx['course'], '2026-07-21', 'scheduled', '15:00:00', '17:00:00');
        DB::table('ClassSession')->where('id', $exception->id)->update(['IsContractException' => 1]);
        $taught = $this->mkSession($ctx['course'], '2026-07-28', 'attended');

        $slots = [['weekday' => 2, 'time' => '18:00', 'duration_minutes' => 120]];
        app(ContractSessionSchedule::class)->syncFutureScheduledSessionTimes($ctx['course'], $slots, 120);

        $hm = fn (int $id) => substr((string) ClassSession::findOrFail($id)->StartTime, 0, 5);
        $this->assertSame('18:00', $hm($plain->id), 'regular future session follows the new series time');
        $this->assertSame('15:00', $hm($exception->id), 'IsContractException session keeps its own time');
        $this->assertSame('16:30', $hm($taught->id), 'attended session is untouched');
        // Pinned shape: there is no frozen original slot to restore from (P1 adds it).
        $this->assertFalse(Schema::hasColumn('ClassSession', 'OriginalStartTimeHM'));
    }

    // ---------------------------------------------------------------- CT-7

    /** CT-7: upsertSlot is idempotent per CURRENT slot, not per occurrence identity. */
    public function test_ct7_upsert_slot_is_idempotent_by_current_slot_only(): void
    {
        $ctx = $this->seedSeries(withHistory: false);
        $svc = app(ClassSessionMaterializationService::class);
        $slot = ['StudentClassID' => $ctx['course'], 'SessionDate' => self::TUE, 'StartTime' => '16:30:00', 'EndTime' => '18:30:00', 'Status' => 'scheduled'];

        $again = $svc->upsertSlot($slot);
        $this->assertFalse($again['created']);
        $this->assertSame($ctx['tue']->id, $again['session']->id, 'same current slot: same row');
        $this->assertSame(1, $this->sessionsAt($ctx['course'], self::TUE, '16:30'));

        // Identity version: the same series occurrence, after it moved, is materialized as a SECOND row.
        $this->reschedule($ctx['token'], $ctx['course'], self::TUE, self::WED)->assertOk();
        $second = $svc->upsertSlot($slot);

        // DOCUMENTS CURRENT BUG (F3), fixed in P1+P6: idempotency key is the current slot, so the original
        // slot of a moved occurrence creates a duplicate; the identity unique index (P6) will reject it.
        $this->assertTrue($second['created'], 'CURRENT: a second ClassSession is created for the same occurrence');
        $this->assertNotSame($ctx['tue']->id, $second['session']->id);
        $this->assertSame(2, ClassSession::where('StudentClassID', $ctx['course'])
            ->whereIn(DB::raw('DATE(SessionDate)'), [self::TUE, self::WED])->count());
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string,string> "date|HH:MM" => state */
    private function previewStates(int $courseId): array
    {
        $dto = app(PreviewSessionHorizonService::class)->preview($courseId, null, Carbon::parse('2026-07-13', 'Asia/Taipei'));
        $this->assertTrue($dto['preview']['ok']);
        $this->assertSame(CommitmentReasonCodes::CLASS_EXPLICIT, $dto['preview']['classification']);
        $out = [];
        foreach ($dto['preview']['occurrences'] as $o) {
            $out[$o['date'].'|'.$o['start_hm']] = $o['state'];
        }

        return $out;
    }

    private function sessionsAt(int $courseId, string $date, string $hm): int
    {
        return ClassSession::where('StudentClassID', $courseId)->whereDate('SessionDate', $date)
            ->whereRaw('SUBSTRING(StartTime,1,5) = ?', [$hm])->count();
    }

    private function dateOf(int $sessionId): string
    {
        return substr((string) ClassSession::findOrFail($sessionId)->SessionDate, 0, 10);
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function teacherListToday(string $token, string $date)
    {
        return $this->api($token)->getJson("/api/v1/class-sessions?start={$date}&end={$date}&per_page=100");
    }

    private function reschedule(string $token, int $course, string $oldDate, string $newDate)
    {
        return $this->api($token)->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $course, 'old_date' => $oldDate, 'old_start_time' => '16:30',
            'new_date' => $newDate, 'start_time' => '16:30', 'end_time' => '18:30', 'ensure_schedule_exception' => true,
        ]);
    }

    /** @return array<string,mixed> */
    private function scheduleRow(int $course, string $date, string $start, string $status): array
    {
        return [
            'student_id' => (int) StudentClass::where('ID', $course)->value('StudentID'),
            'teacher_id' => (int) StudentClass::where('ID', $course)->value('TeacherID'),
            'subject' => 'Math', 'day_of_week' => Carbon::parse($date)->dayOfWeekIso,
            'start_time' => $start, 'end_time' => '18:30', 'duration_hours' => 2, 'class_type' => 'one_on_one',
            'status' => $status, 'type' => 'normal', 'deduction' => 0, 'branch_id' => 1,
            'schedule_date' => $date, 'student_course_id' => $course,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function user(string $type, bool $admin, string $tag): User
    {
        $u = User::create([
            'LoginName' => "occ-{$tag}-".uniqid().'@example.com', 'Name' => $tag, 'PSW' => 'x', 'type' => $type,
            'phone' => '09'.random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin ? 1 : 0, 'Approved' => 1]);

        return $u;
    }

    private function token(User $u): string
    {
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

        return $t;
    }

    private function makeCourse(int $studentId, int $teacherId, array $over = []): int
    {
        return (int) StudentClass::create(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'TotalHours' => 20, 'SessionCount' => 20, 'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 0,
            'Charge' => 0, 'Pay' => 0, 'Paid' => 1, 'Rate' => 500, 'Stop' => 0, 'MDate' => now(),
            'ScheduleMode' => 'count', 'week' => 2, 'time' => '16:30:00',
        ], $over))->ID;
    }

    private function mkSession(int $course, string $date, string $status, string $start = '16:30:00', string $end = '18:30:00'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => $start, 'EndTime' => $end,
            'Status' => $status, 'IsContractException' => 0,
        ]);
    }

    /** @return array<string,mixed> */
    private function seedSeries(string $mode = 'count', bool $withHistory = true): array
    {
        $director = $this->user('A', true, 'dir');
        $teacher = $this->user('T', false, 'tea');
        $sub = $this->user('T', false, 'sub');
        $student = Student::create([
            'name' => 'OCC-'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = $this->makeCourse((int) $student->id, (int) $teacher->id, ['ScheduleMode' => $mode]);
        if ($withHistory) {
            foreach (['2026-06-23', '2026-06-30', '2026-07-07'] as $d) {
                $this->mkSession($course, $d, 'attended');
            }
        }

        return [
            'token' => $this->token($director), 'teacherToken' => $this->token($teacher),
            'teacher' => (int) $teacher->id, 'sub' => (int) $sub->id, 'student' => (int) $student->id,
            'course' => $course, 'tue' => $this->mkSession($course, self::TUE, 'scheduled'),
        ];
    }
}
