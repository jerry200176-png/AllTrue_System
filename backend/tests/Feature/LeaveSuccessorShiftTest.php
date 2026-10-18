<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ClassSessionMaterializationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-app #410 / #4152: a leave on an old contract whose tail would land in a pre-created renewal.
 * Old contract: Tuesdays 2026-11-03 … 11-24 (4 lessons). Renewal: Tuesdays 12-01 … 12-22 (4 lessons).
 * Leave on 11-17 → the tail takes 12-01, the renewal shifts one week back, ending 12-29.
 */
class LeaveSuccessorShiftTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENT = 88410;
    private const TEACHER = 7410;

    private string $token;
    private int $old;
    private int $renewal;
    /** @var array<string, int> */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00', 'Asia/Taipei'));
        config(['perfflags.leave_successor_shift_apply_enabled' => true]);
        $this->token = $this->directorToken();
        $this->student(self::STUDENT);
        $this->old = $this->course(self::STUDENT, '2026-11-03', '2026-11-24');
        $this->renewal = $this->course(self::STUDENT, '2026-12-01', '2026-12-22');
        foreach (['2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'] as $d) {
            $this->sessions[$d] = $this->lesson($this->old, $d);
        }
        foreach (['2026-12-01', '2026-12-08', '2026-12-15', '2026-12-22'] as $d) {
            $this->sessions[$d] = $this->lesson($this->renewal, $d);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_lists_every_move_and_apply_shifts_the_renewal_once(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $before = $this->fingerprint();
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $preview->assertJsonPath('applicable', true)->assertJsonPath('eligible', true)->assertJsonPath('blockers', []);
        $moves = $preview->json('moves');
        $this->assertSame(['date' => '2026-12-01', 'start' => '19:00:00', 'end' => '21:00:00'], $moves[0]['to']);
        $this->assertSame('old_tail', $moves[0]['role']);
        $this->assertSame(
            ['2026-12-01>2026-12-08', '2026-12-08>2026-12-15', '2026-12-15>2026-12-22', '2026-12-22>2026-12-29'],
            array_map(fn ($m) => $m['from']['date'] . '>' . $m['to']['date'], array_slice($moves, 1))
        );
        $preview->assertJsonPath('old_end_date', '2026-12-01')->assertJsonPath('successor_end_date', '2026-12-29');
        $this->assertSame($before, $this->fingerprint(), 'preview must not write');

        $applied = $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertOk();
        $applied->assertJsonPath('applied', true);
        foreach (array_slice($moves, 1) as $move) {
            $this->assertStringContainsString('successor-shift-after-leave:ls=' . $leaveId . ':from=' . $move['from']['date'] . 'T' . $move['from']['start'] . ':end=' . $move['from']['end'], (string) DB::table('ClassSession')->where('id', $move['session_id'])->value('Note'));
        }
        $this->assertTrue(DB::table('ClassSession')->where('StudentClassID', $this->old)->where('Note', 'like', '%:ls=' . $leaveId . ':succ=' . $this->renewal . ':n=4%')->exists());

        $this->assertSame('leave', DB::table('ClassSession')->where('id', $leaveId)->value('Status'));
        $this->assertSame(['2026-12-08', '2026-12-15', '2026-12-22', '2026-12-29'], $this->liveDates($this->renewal));
        $this->assertSame(['2026-11-03', '2026-11-10', '2026-11-24', '2026-12-01'], $this->liveDates($this->old));
        $this->assertSame('2026-12-01', substr((string) DB::table('StudentClass')->where('ID', $this->old)->value('EndDate'), 0, 10));
        $this->assertSame('2026-12-29', substr((string) DB::table('StudentClass')->where('ID', $this->renewal)->value('EndDate'), 0, 10));
        // No ledger row and no counter change on either contract: never deduct across contracts.
        $this->assertSame(0, SessionDeductionLedger::query()->count());
        $this->assertSame([4, 0], $this->counters($this->old));
        $this->assertSame([4, 0], $this->counters($this->renewal));
        $this->assertSame(1, DB::table('StudentSingIn')->where('ClassSessionID', $leaveId)->where('Status', 'leave')->count());

        $after = $this->fingerprint();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])
            ->assertOk()->assertJsonPath('already_applied', true);
        $this->assertSame($after, $this->fingerprint(), 'a repeated apply writes nothing');
    }

    /** #4210 remaining initial-delivery protections. @dataProvider initialProtectionCases */
    public function test_initial_delivery_refusals_preserve_all_evidence(string $kind): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $target = $this->sessions['2026-12-08'];
        $owner = $this->renewal;
        if ($kind === 'old_settled' || $kind === 'renewal_settled') {
            $owner = $kind === 'old_settled' ? $this->old : $this->renewal;
            DB::table('StudentClass')->where('ID', $owner)->update(['settlement_locked_at' => now()]);
        } elseif ($kind === 'invalid_renewal_time') {
            DB::table('ClassSession')->where('id', $target)->update(['EndTime' => '01:00:00']);
        } elseif ($kind === 'voided_attendance') {
            DB::table('StudentSingIn')->insert(['ClassSessionID' => $target, 'StudentClassID' => $owner, 'StudentID' => self::STUDENT,
                'TeacherID' => self::TEACHER, 'CampusID' => 1, 'PersonType' => 'student', 'Status' => 'present', 'SessionDeducted' => false,
                'SignInDT' => '2026-12-08 19:00:00', 'MDT' => now(), 'VoidedAt' => now(), 'VoidReason' => 'historical correction']);
        } elseif ($kind === 'voided_evaluation') {
            LearningRecord::create(['StudentClassID' => $owner, 'ClassSessionID' => $target, 'TeacherID' => self::TEACHER,
                'Subject' => 'Math', 'Content' => 'historical', 'SessionDate' => '2026-12-08', 'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'approved', 'VoidedAt' => now()]);
        } else {
            if ($kind === 'old_anchor') { $target = $leaveId; $owner = $this->old; }
            $row = DB::table('ClassSession')->where('id', $target)->first();
            if ($kind === 'frozen_identity') DB::table('ClassSession')->where('id', $target)->update(['OriginalSessionDate' => '2026-11-25', 'OriginalStartTimeHM' => '18:00']);
            DB::table('schedules')->insert(['student_id' => self::STUDENT, 'student_course_id' => $owner,
                'teacher_id' => self::TEACHER + 1, 'branch_id' => 1, 'subject' => 'Math', 'day_of_week' => 2,
                'schedule_date' => in_array($kind, ['frozen_anchor', 'frozen_identity'], true) ? '2026-12-30' : $row->SessionDate,
                'start_time' => $row->StartTime, 'end_time' => $row->EndTime, 'status' => 'scheduled', 'type' => 'normal',
                'original_schedule_date' => $kind === 'frozen_identity' ? '2026-11-25' : $row->SessionDate, 'original_start_time' => $kind === 'frozen_identity' ? '18:00' : $row->StartTime]);
        }
        $before = $this->undoSnapshot();
        $plan = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', false);
        $this->assertSame($owner, $plan->json('blockers.0.course_id'));
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $plan->json('preview_token')])->assertStatus(422);
        $this->assertSame($before, $this->undoSnapshot());
    }

    public static function initialProtectionCases(): array
    {
        return array_map(fn ($kind) => [$kind], ['old_settled', 'renewal_settled', 'old_anchor', 'renewal_anchor', 'frozen_anchor', 'frozen_identity', 'voided_attendance', 'voided_evaluation', 'invalid_renewal_time']);
    }

    public function test_an_elapsed_scheduled_leave_today_is_refused_without_writes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 20:00:00', 'Asia/Taipei'));
        AuthToken::where('token', $this->token)->update(['expires_at' => now()->addDay()]);
        $this->assertBlockedWithoutWrites('ineligible', $this->sessions['2026-11-17']);
    }

    public function test_slot_aware_tail_and_exact_short_lesson_duration(): void
    {
        DB::table('StudentClass')->where('ID', $this->renewal)->update(['week1' => 2, 'time1' => '21:00:00', 'duration1' => 45]);
        $leaveId = $this->sessions['2026-11-17'];
        DB::table('ClassSession')->where('id', $leaveId)->update(['EndTime' => '19:20:00']);
        $plan = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', true);
        $moves = $plan->json('moves');
        $this->assertSame('19:20:00', $moves[0]['to']['end']);
        $this->assertSame(['date' => '2026-12-22', 'start' => '21:00:00', 'end' => '21:45:00'], end($moves)['to']);
        $plan->assertJsonPath('successor_end_date', '2026-12-22');
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $plan->json('preview_token')])->assertOk();
        $this->assertSame('21:45:00', DB::table('ClassSession')->where('id', $this->sessions['2026-12-22'])->value('EndTime'));
    }

    /** #4210 review: compare proposed intervals, including the final same-day contract slot. @dataProvider plannedTailDurations */
    public function test_proposed_intervals_cannot_double_book_the_student(int $minutes, bool $blocked): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        DB::table('ClassSession')->where('id', $leaveId)->update(['EndTime' => $minutes === 120 ? '21:00:00' : '20:00:00']);
        DB::table('ClassSession')->where('StudentClassID', $this->renewal)
            ->where('id', '!=', $this->sessions['2026-12-01'])->delete();
        DB::table('ClassSession')->where('id', $this->sessions['2026-12-01'])->update(['EndTime' => '20:00:00']);
        DB::table('StudentClass')->where('ID', $this->renewal)->update([
            'SessionDuration' => 60, 'week1' => 2, 'time1' => '20:00:00', 'duration1' => 60,
        ]);
        $before = $this->undoSnapshot();
        $plan = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', !$blocked);
        $this->assertSame('2026-12-01', $plan->json('moves.1.to.date'));
        $this->assertSame('20:00:00', $plan->json('moves.1.to.start'));
        $this->assertSame($before, $this->undoSnapshot(), 'preview must not write');
        $response = $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $plan->json('preview_token')]);
        if ($blocked) {
            $this->assertSame('student', $plan->json('blockers.0.type'));
            $response->assertStatus(422);
            $this->assertSame($before, $this->undoSnapshot());
        } else {
            $response->assertOk()->assertJsonPath('applied', true);
        }
    }

    public static function plannedTailDurations(): array
    {
        return ['overlapping tail' => [120, true], 'adjacent tail' => [60, false]];
    }

    /** #4215 review: scheduling duration comes from the existing occurrence, never billing minutes. */
    public function test_legacy_missing_duration_uses_existing_successor_occurrence(): void
    {
        DB::table('StudentClass')->where('ID', $this->renewal)->update(['SessionDuration' => null, 'standard_lesson_minutes' => 90]);
        DB::table('ClassSession')->where('StudentClassID', $this->renewal)->update(['EndTime' => '20:15:00']);
        $leaveId = $this->sessions['2026-11-17'];
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', true);
        $moves = $preview->json('moves');
        $this->assertSame(['date' => '2026-12-29', 'start' => '19:00:00', 'end' => '20:15:00'], end($moves)['to']);
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertOk();
        $this->assertNull(DB::table('StudentClass')->where('ID', $this->renewal)->value('SessionDuration'));
        $this->assertSame(90, (int) DB::table('StudentClass')->where('ID', $this->renewal)->value('standard_lesson_minutes'));
    }

    public function test_old_tail_cannot_silently_cross_midnight(): void
    {
        DB::table('ClassSession')->where('id', $this->sessions['2026-12-01'])->update(['StartTime' => '23:00:00', 'EndTime' => '23:30:00']);
        DB::table('StudentClass')->where('ID', $this->renewal)->update(['time' => '23:00:00', 'SessionDuration' => 30]);
        $before = $this->undoSnapshot();
        foreach (['preview', 'apply'] as $action) {
            $this->shift($action, ['class_session_id' => $this->sessions['2026-11-17'], 'preview_token' => str_repeat('a', 64)])
                ->assertStatus(422)->assertJsonPath('message', '跨日補堂需先核對，無法自動順延');
            $this->assertSame($before, $this->undoSnapshot());
        }
    }

    public function test_apply_locks_session_before_course_and_only_the_selected_renewal(): void
    {
        $other = $this->course(self::STUDENT, '2027-01-05', '2027-01-05');
        $this->lesson($other, '2027-01-05');
        $leaveId = $this->sessions['2026-11-17'];
        $plan = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        DB::enableQueryLog();
        try {
            $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $plan->json('preview_token')])->assertOk();
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'for update')));
            $this->assertStringContainsString('`ClassSession`', $locks[0]['query']);
            foreach ($locks as $query) {
                if (str_contains($query['query'], '`StudentClass`')) {
                    $this->assertStringContainsString('`ID` = ?', $query['query']);
                    $this->assertNotContains($other, $query['bindings']);
                }
            }
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    public function test_successor_routes_enforce_teacher_and_other_campus_director_boundaries(): void
    {
        $userId = (int) AuthToken::where('token', $this->token)->value('user_id');
        $before = $this->undoSnapshot();
        foreach (['other_campus', 'teacher', 'no_campus'] as $kind) {
            User::where('id', $userId)->update(['type' => $kind === 'teacher' ? 'T' : 'A']);
            UserCampus::where('UserID', $userId)->update(['CampusID' => $kind === 'teacher' ? 1 : 2, 'Admin' => $kind === 'teacher' ? 0 : 1]);
            if ($kind === 'no_campus') UserCampus::where('UserID', $userId)->delete();
            foreach (['preview', 'apply'] as $action) $this->shift($action, ['class_session_id' => $this->sessions['2026-11-17'], 'preview_token' => str_repeat('a', 64)])->assertStatus(403);
        }
        $this->assertSame($before, $this->undoSnapshot());
    }

    public function test_effective_teacher_is_checked_without_exposing_foreign_conflict_details(): void
    {
        $id = DB::table('schedules')->insertGetId(['student_course_id' => $this->renewal, 'student_id' => self::STUDENT,
            'teacher_id' => self::TEACHER + 1, 'branch_id' => 1, 'schedule_date' => '2026-12-08', 'day_of_week' => 2,
            'start_time' => '19:00:00', 'end_time' => '21:00:00', 'status' => 'scheduled', 'type' => 'normal']);
        DB::table('schedules')->where('id', $id)->update(['original_schedule_id' => $id]);
        $teachers = [];
        $guard = \Mockery::mock(\App\Services\ScheduleGuardService::class);
        $guard->shouldReceive('validateCourseOccurrence')->andReturnUsing(function ($course) use (&$teachers) {
            $teachers[] = (int) $course->TeacherID;
            return [['type' => 'teacher_capacity', 'message' => 'ForeignPersonSynthetic / ForeignCampusSynthetic']];
        });
        $this->app->instance(\App\Services\ScheduleGuardService::class, $guard);
        $before = $this->undoSnapshot();
        $plan = $this->shift('preview', ['class_session_id' => $this->sessions['2026-11-17']])->assertOk()->assertJsonPath('eligible', false);
        $this->assertContains(self::TEACHER + 1, $teachers);
        $this->assertStringNotContainsString('ForeignPersonSynthetic', $plan->getContent());
        $this->assertStringNotContainsString('ForeignCampusSynthetic', $plan->getContent());
        $this->shift('apply', ['class_session_id' => $this->sessions['2026-11-17'], 'preview_token' => $plan->json('preview_token')])->assertStatus(422);
        $this->assertSame($before, $this->undoSnapshot());
    }

    public function test_deadlock_retry_rolls_back_and_replans_before_writing_once(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $plan = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $userId = (int) AuthToken::where('token', $this->token)->value('user_id');
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
        $connection = DB::connection();
        $originalEvents = $connection->getEventDispatcher();
        $events = clone $originalEvents;
        $connection->setEventDispatcher($events);
        $attempts = 0;
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($query) use (&$attempts) {
            if (str_starts_with($query->sql, 'update `ClassSession` set `Status`')) {
                if (++$attempts === 1) throw new \PDOException('Deadlock found when trying to get lock', 40001);
            }
        });
        try {
            $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $plan->json('preview_token')])->assertOk();
            $this->assertSame(2, $attempts);
            $this->assertSame(1, DB::table('StudentSingIn')->where('ClassSessionID', $leaveId)->count());
            $this->assertSame(1, DB::table('schedules')->where('student_course_id', $this->old)->count());
            $this->assertSame(5, DB::table('ClassSession')->where('StudentClassID', $this->old)->count());
        } finally {
            $connection->setEventDispatcher($originalEvents);
            $courseIds = [$this->old, $this->renewal];
            $ids = DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->pluck('id');
            DB::table('schedule_audit_logs')->whereIn('session_id', $ids)->delete();
            DB::table('schedules')->whereIn('student_course_id', $courseIds)->delete();
            DB::table('StudentSingIn')->whereIn('StudentClassID', $courseIds)->delete();
            DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->delete();
            DB::table('StudentClass')->whereIn('ID', $courseIds)->delete();
            DB::table('auth_tokens')->where('user_id', $userId)->delete();
            DB::table('UserCampus')->where('UserID', $userId)->delete();
            DB::table('User')->where('id', $userId)->delete();
            DB::table('Student')->where('id', self::STUDENT)->delete();
            DB::beginTransaction();
        }
    }

    /** #4210 P2-3: old-session evidence must refuse before marking leave or moving a renewal. @dataProvider oldLeaveEvidence */
    public function test_old_leave_evidence_blocks_preview_and_apply_without_writes(string $kind): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $this->seedOldLeaveEvidence($leaveId, $kind);
        $before = $this->undoSnapshot();
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', false)
            ->assertJsonPath('blockers.0.course_id', $this->old)->assertJsonPath('blockers.0.session_id', $leaveId);
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])
            ->assertStatus(422)->assertJsonPath('plan.blockers.0.course_id', $this->old);
        $this->assertSame($before, $this->undoSnapshot());
    }

    public static function oldLeaveEvidence(): array
    {
        return ['ledger' => ['ledger'], 'live attendance' => ['live'], 'past date' => ['past'],
            'approved evaluation' => ['approved'], 'voided leave history' => ['voided_leave'], 'voided attendance history' => ['voided_attendance']];
    }

    /** #4210: apply must recheck evidence introduced after the preview. @dataProvider lateOldLeaveEvidence */
    public function test_old_leave_evidence_added_after_preview_refuses_without_writes(string $kind): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', true);
        $this->seedOldLeaveEvidence($leaveId, $kind);
        $before = $this->undoSnapshot();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])
            ->assertStatus(422)->assertJsonPath('plan.blockers.0.course_id', $this->old)->assertJsonPath('plan.blockers.0.session_id', $leaveId);
        $this->assertSame($before, $this->undoSnapshot());
    }

    public static function lateOldLeaveEvidence(): array
    {
        return ['ledger' => ['ledger'], 'live attendance' => ['live'], 'voided history' => ['voided_leave'], 'occurrence anchor' => ['anchor']];
    }

    public static function staleApplyEvidence(): array
    {
        return self::lateOldLeaveEvidence() + ['renewal changed to date mode' => ['renewal_mode']];
    }

    /** #4210: locking reads must defeat a pre-existing REPEATABLE READ snapshot. @dataProvider staleApplyEvidence */
    public function test_stale_apply_snapshot_sees_committed_ineligibility(string $kind): void
    {
        $original = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(1, DB::transactionLevel());
        $userId = AuthToken::query()->where('token', $this->token)->value('user_id');
        DB::commit();
        config(['database.connections.shift_stale_apply' => config('database.connections.' . $original)]);
        $apply = DB::connection('shift_stale_apply');
        $leaveId = $this->sessions['2026-11-17'];
        $table = $kind === 'anchor' ? 'schedules' : ($kind === 'ledger' ? (new SessionDeductionLedger)->getTable() : 'StudentSingIn');
        $column = $kind === 'anchor' ? 'student_course_id' : ($kind === 'ledger' ? 'class_session_id' : 'ClassSessionID');
        $lookup = $kind === 'anchor' ? $this->old : $leaveId;
        $observed = fn () => $kind === 'renewal_mode'
            ? $apply->table('StudentClass')->where('ID', $this->renewal)->where('ScheduleMode', 'date')->count()
            : $apply->table($table)->where($column, $lookup)->count();
        try {
            $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', true);
            $apply->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $apply->beginTransaction();
            $this->assertSame(0, $observed());
            if ($kind === 'renewal_mode') {
                DB::table('StudentClass')->where('ID', $this->renewal)->update(['ScheduleMode' => 'date']);
            } else {
                $this->seedOldLeaveEvidence($leaveId, $kind);
            } // Committed on the independent original connection.
            $before = $this->undoSnapshot();
            $this->assertSame(0, $observed(), 'prove that the snapshot is stale');
            DB::setDefaultConnection('shift_stale_apply');
            $response = $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])
                ->assertStatus(422);
            if ($kind === 'renewal_mode') {
                $response->assertJsonPath('message', '續約課程已變動，請重新預覽');
            } else {
                $response->assertJsonPath('plan.blockers.0.course_id', $this->old)->assertJsonPath('plan.blockers.0.session_id', $leaveId);
            }
            DB::setDefaultConnection($original);
            $this->assertSame($before, $this->undoSnapshot());
        } finally {
            DB::setDefaultConnection($original);
            while ($apply->transactionLevel() > 0) {
                $apply->rollBack();
            }
            DB::purge('shift_stale_apply');
            DB::transaction(function () use ($userId) {
                $courseIds = [$this->old, $this->renewal];
                $ids = DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->pluck('id');
                DB::table('schedule_audit_logs')->whereIn('session_id', $ids)->delete();
                DB::table('schedules')->whereIn('student_course_id', $courseIds)->delete();
                DB::table('StudentSingIn')->whereIn('StudentClassID', $courseIds)->delete();
                SessionDeductionLedger::query()->whereIn('student_class_id', $courseIds)->delete();
                DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->delete();
                DB::table('StudentClass')->whereIn('ID', $courseIds)->delete();
                DB::table('auth_tokens')->where('user_id', $userId)->delete();
                DB::table('UserCampus')->where('UserID', $userId)->delete();
                DB::table('User')->where('id', $userId)->delete();
                DB::table('Student')->where('id', self::STUDENT)->delete();
            });
            DB::beginTransaction(); // Balance RefreshDatabase teardown, without remigrating or changing indexes.
        }
    }

    /** #4210 P2-3: keep the ordinary leave marker contract, including same-date occurrence identity. @dataProvider leaveMarkerDates */
    public function test_successor_apply_writes_an_occurrence_aware_leave_marker_once(bool $sameDate): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $otherMarker = null;
        if ($sameDate) {
            DB::table('ClassSession')->where('id', $this->sessions['2026-11-10'])->update([
                'SessionDate' => '2026-11-17', 'StartTime' => '16:00:00', 'EndTime' => '17:00:00', 'Status' => 'leave',
            ]);
            $otherMarker = \App\Models\Schedule::create(['student_id' => self::STUDENT, 'student_course_id' => $this->old,
                'teacher_id' => self::TEACHER, 'branch_id' => 1, 'schedule_date' => '2026-11-17', 'day_of_week' => 2,
                'start_time' => '16:00', 'end_time' => '17:00', 'status' => 'leave', 'type' => 'normal']);
        }
        $otherBefore = $otherMarker ? (array) DB::table('schedules')->where('id', $otherMarker->id)->first() : null;
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', true);
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertOk();
        $markers = DB::table('schedules')->where('student_course_id', $this->old)->whereDate('schedule_date', '2026-11-17')
            ->where('status', 'leave')->where('start_time', '19:00')->get();
        $this->assertCount(1, $markers);
        $expected = ['student_id' => self::STUDENT, 'student_course_id' => $this->old, 'teacher_id' => self::TEACHER,
            'branch_id' => 1, 'day_of_week' => 2, 'start_time' => '19:00', 'end_time' => '21:00', 'status' => 'leave',
            'class_type' => 'one_on_one', 'type' => 'normal', 'deduction' => 0, 'subject' => '1'];
        $this->assertEquals($expected, array_intersect_key((array) $markers[0], $expected));
        if ($otherMarker) {
            $this->assertSame($otherBefore, (array) DB::table('schedules')->where('id', $otherMarker->id)->first());
        }
        $after = $this->undoSnapshot();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])
            ->assertOk()->assertJsonPath('already_applied', true);
        $this->assertSame($after, $this->undoSnapshot());
    }

    public static function leaveMarkerDates(): array
    {
        return ['single lesson' => [false], 'two lessons with another leave' => [true]];
    }

    private function seedOldLeaveEvidence(int $sessionId, string $kind): void
    {
        if ($kind === 'anchor') {
            DB::table('schedules')->insert(['student_course_id' => $this->old, 'student_id' => self::STUDENT, 'branch_id' => 1,
                'teacher_id' => self::TEACHER + 1, 'schedule_date' => '2026-11-17', 'start_time' => '19:00:00',
                'end_time' => '21:00:00', 'status' => 'scheduled', 'type' => 'normal', 'day_of_week' => 2]);
        } elseif ($kind === 'ledger') {
            SessionDeductionLedger::create(['student_class_id' => $this->old, 'class_session_id' => $sessionId,
                'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
        } elseif ($kind === 'approved') {
            LearningRecord::create(['StudentClassID' => $this->old, 'ClassSessionID' => $sessionId, 'TeacherID' => self::TEACHER,
                'Subject' => 'Math', 'Content' => 'ok', 'SessionDate' => '2026-11-17', 'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'approved']);
        } elseif ($kind === 'past') {
            Carbon::setTestNow(Carbon::parse('2026-11-18 10:00:00', 'Asia/Taipei'));
            AuthToken::query()->where('token', $this->token)->update(['expires_at' => now()->addDay()]);
        } else {
            DB::table('StudentSingIn')->insert(['StudentClassID' => $this->old, 'ClassSessionID' => $sessionId,
                'StudentID' => self::STUDENT, 'TeacherID' => self::TEACHER, 'CampusID' => 1, 'PersonType' => 'student',
                'Status' => $kind === 'voided_leave' ? 'leave' : 'present', 'SessionDeducted' => false,
                'SignInDT' => '2026-11-17 19:00:00', 'SignOutDT' => '2026-11-17 21:00:00', 'MDT' => now(),
                'VoidedAt' => $kind === 'live' ? null : '2026-10-31 18:00:00', 'VoidReason' => $kind === 'live' ? null : 'historical correction']);
        }
    }

    /** @dataProvider ineligibleRenewalLessons */
    public function test_taught_deducted_or_evaluated_renewal_lesson_blocks_everything(string $kind): void
    {
        $target = $this->sessions['2026-12-08'];
        match ($kind) {
            'taught' => DB::table('ClassSession')->where('id', $target)->update(['Status' => 'completed']),
            'deducted' => SessionDeductionLedger::create(['student_class_id' => $this->renewal, 'class_session_id' => $target, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]),
            'evaluated' => LearningRecord::create(['StudentClassID' => $this->renewal, 'ClassSessionID' => $target, 'TeacherID' => self::TEACHER, 'Subject' => 'Math', 'Content' => 'ok', 'SessionDate' => '2026-12-08', 'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'approved']),
        };

        $this->assertBlockedWithoutWrites('ineligible', $target);
    }

    /** @return array<string, array{string}> */
    public static function ineligibleRenewalLessons(): array
    {
        return ['taught' => ['taught'], 'deducted' => ['deducted'], 'evaluated' => ['evaluated']];
    }

    /** @dataProvider conflicts */
    public function test_teacher_student_or_classroom_conflict_stops_the_whole_shift(string $kind): void
    {
        if ($kind === 'student') {
            $other = $this->course(self::STUDENT, '2026-12-29', '2026-12-29', ['SubjectID' => 2, 'TeacherID' => 7999]);
        } else {
            $this->student(88411);
            $extra = [];
            if ($kind === 'classroom') {
                $room = DB::table('rooms')->insertGetId(['campus_id' => 1, 'name' => 'R410', 'capacity' => 2, 'created_at' => now(), 'updated_at' => now()]);
                $extra = ['room_id' => $room, 'ClassType' => 'one_on_three'];
                DB::table('StudentClass')->whereIn('ID', [$this->old, $this->renewal])->update($extra);
            }
            $other = $this->course(88411, '2026-12-29', '2026-12-29', $extra);
        }
        $this->lesson($other, '2026-12-29');

        $this->assertBlockedWithoutWrites($kind, null);
    }

    /** @return array<string, array{string}> */
    public static function conflicts(): array
    {
        return ['teacher' => ['teacher'], 'student' => ['student'], 'classroom' => ['classroom']];
    }

    public function test_stale_token_is_refused_without_writes(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $token = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->json('preview_token');
        DB::table('ClassSession')->where('id', $this->sessions['2026-12-22'])->update(['StartTime' => '18:00:00', 'EndTime' => '20:00:00']);
        $before = $this->fingerprint();

        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $token])->assertStatus(409);
        $this->assertSame($before, $this->fingerprint());
    }

    /** #4210 r4296589495: an EndDate edit after preview must not be overwritten by stale apply. @dataProvider changedContractBoundaries */
    public function test_concurrent_end_date_edit_requires_a_fresh_preview(string $which, ?string $endDate): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', true);
        $courseId = $which === 'old' ? $this->old : $this->renewal;
        DB::table('StudentClass')->where('ID', $courseId)->update(['EndDate' => $endDate]);
        $before = $this->undoSnapshot();

        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertStatus(409);
        $this->assertSame($before, $this->undoSnapshot(), 'stale apply must preserve both contracts and all lesson artifacts');
        $fresh = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->assertJsonPath('eligible', true)
            ->assertJsonPath('current_end_dates.' . $which, $endDate);
        $this->assertNotSame($preview->json('preview_token'), $fresh->json('preview_token'));
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $fresh->json('preview_token')])
            ->assertOk()->assertJsonPath('applied', true);
    }

    public static function changedContractBoundaries(): array
    {
        return ['old boundary' => ['old', '2026-11-25'], 'renewal boundary' => ['successor', '2026-12-23'],
            'old boundary cleared' => ['old', null], 'renewal boundary cleared' => ['successor', null]];
    }

    public function test_failure_mid_apply_rolls_back_every_write(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $token = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->json('preview_token');
        $before = $this->fingerprint();
        $beforeArtifacts = $this->undoSnapshot();
        // The renewal rows move first; the tail write then fails.
        $this->mock(ClassSessionMaterializationService::class, function ($mock) {
            $mock->shouldReceive('assertStudentSlotAvailableForSession')->andReturnNull();
            $mock->shouldReceive('upsertSlot')->andThrow(new \RuntimeException('simulated failure'));
        });
        $this->withoutExceptionHandling();
        try {
            $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $token]);
            $this->fail('apply should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($beforeArtifacts, $this->undoSnapshot());
        $this->assertSame(0, DB::table('StudentSingIn')->count());
    }

    public function test_apply_is_refused_without_writes_while_the_gate_is_off(): void
    {
        config(['perfflags.leave_successor_shift_apply_enabled' => false]); // the default until undo is guarded (Q9a)
        $leaveId = $this->sessions['2026-11-17'];
        $token = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->json('preview_token');
        $before = $this->fingerprint();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $token])->assertStatus(403)->assertJsonPath('message', '續約順延尚未開放（撤銷請假的保護尚未上線），未做任何變更');
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_not_applicable_when_the_tail_fits_before_the_renewal(): void
    {
        DB::table('ClassSession')->where('StudentClassID', $this->renewal)->delete();
        foreach (['2026-12-15', '2026-12-22'] as $d) {
            $this->lesson($this->renewal, $d);
        }

        $this->shift('preview', ['class_session_id' => $this->sessions['2026-11-17']])
            ->assertOk()->assertJsonPath('applicable', false);
    }

    /** #4210 Q9: refuse before changing any artifact. @dataProvider undoShiftCases */
    public function test_undo_after_successor_shift_is_refused_without_writes(string $entry, bool $consumed, bool $desync): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $token = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->json('preview_token');
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $token])->assertOk();
        config(['perfflags.leave_successor_shift_apply_enabled' => false]);
        $marker = \App\Models\Schedule::create([
            'student_id' => self::STUDENT, 'student_course_id' => $this->old, 'teacher_id' => self::TEACHER,
            'branch_id' => 1, 'schedule_date' => '2026-11-17', 'day_of_week' => 2,
            'start_time' => '19:00', 'end_time' => '21:00', 'status' => 'leave', 'type' => 'regular',
        ]);
        if ($consumed) {
            Carbon::setTestNow(Carbon::parse('2027-01-01 10:00:00', 'Asia/Taipei'));
            DB::table('ClassSession')->where('StudentClassID', $this->renewal)->update(['Status' => 'completed']);
            // Keep the synthetic login valid after advancing the clock.
            AuthToken::query()->update(['expires_at' => now()->addDay()]);
        }
        if ($desync) {
            DB::table('ClassSession')->where('id', $leaveId)->update(['Status' => 'scheduled']);
        }
        $before = $this->undoSnapshot();
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson($entry === 'schedule' ? '/api/v1/schedules/' . $marker->id . '/undo-leave' : '/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId]);
        $response->assertStatus(422)->assertJsonPath('code', 'undo_leave_successor_shift_active')
            ->assertJsonPath('affected_lessons.0.course_id', $this->renewal)
            ->assertJsonPath('affected_lessons.0.session_id', $this->sessions['2026-12-01'])
            ->assertJsonPath('affected_lessons.0.from.date', '2026-12-01')
            ->assertJsonPath('affected_lessons.0.to.date', '2026-12-08');
        $this->assertCount(4, $response->json('affected_lessons'));
        $this->assertSame($before, $this->undoSnapshot());
    }

    public static function undoShiftCases(): array
    {
        return [
            'session' => ['session', false, false],
            'schedule' => ['schedule', false, false],
            'past consumed renewal' => ['session', true, false],
            'desynced leave sign-in' => ['session', false, true],
        ];
    }

    /** #4210: an unrelated leave's provenance must not trap ordinary undo. */
    public function test_undo_without_its_own_active_shift_still_works(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        DB::table('ClassSession')->where('id', $leaveId)->update(['Status' => 'leave']);
        DB::table('ClassSession')->where('id', $this->sessions['2026-12-01'])->update([
            'Note' => 'successor-shift-after-leave:ls=' . ($leaveId * 10) . ':from=2026-11-24T19:00',
        ]);
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])->assertOk();
        $this->assertSame('scheduled', DB::table('ClassSession')->where('id', $leaveId)->value('Status'));
    }

    /** #4212: legacy provenance without the original end time must fail closed. */
    public function test_incomplete_legacy_shift_provenance_blocks_undo(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        DB::table('ClassSession')->where('id', $leaveId)->update(['Status' => 'leave']);
        DB::table('ClassSession')->where('id', $this->sessions['2026-12-01'])->update([
            'Note' => 'successor-shift-after-leave:ls=' . $leaveId . ':from=2026-12-01T19:00',
        ]);
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])->assertStatus(422);
        $this->assertSame('leave', DB::table('ClassSession')->where('id', $leaveId)->value('Status'));
    }

    /** #4210: a locking Q9 read must see a shift committed after undo's repeatable-read snapshot. */
    public function test_undo_with_a_stale_snapshot_sees_the_committed_successor_shift(): void
    {
        $original = DB::getDefaultConnection();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(1, DB::transactionLevel());
        $userId = AuthToken::query()->where('token', $this->token)->value('user_id');
        // Publish synthetic fixtures to a second connection, then delete only our own
        // rows below. Re-migrating here would reapply earlier tests' leaked index flag.
        DB::commit();
        config(['database.connections.q9_stale_undo' => config('database.connections.' . $original)]);
        $undo = DB::connection('q9_stale_undo');
        $leaveId = $this->sessions['2026-11-17'];
        $renewalId = $this->sessions['2026-12-01'];
        try {
            $undo->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $undo->beginTransaction();
            $this->assertSame('', $undo->table('ClassSession')->where('id', $renewalId)->value('Note'));

            $token = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->json('preview_token');
            $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $token])->assertOk();
            $before = $this->undoSnapshot();
            // Demonstrate the stale snapshot is real, not merely sequential requests.
            $this->assertSame('', $undo->table('ClassSession')->where('id', $renewalId)->value('Note'));
            DB::setDefaultConnection('q9_stale_undo');
            $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
                ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])
                ->assertStatus(422)->assertJsonPath('code', 'undo_leave_successor_shift_active')
                ->assertJsonPath('affected_lessons.0.session_id', $renewalId);
            DB::setDefaultConnection($original);
            $this->assertSame($before, $this->undoSnapshot());
        } finally {
            DB::setDefaultConnection($original);
            while ($undo->transactionLevel() > 0) {
                $undo->rollBack();
            }
            DB::purge('q9_stale_undo');
            DB::transaction(function () use ($userId) {
                $courseIds = [$this->old, $this->renewal];
                $sessionIds = DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->pluck('id');
                DB::table('schedule_audit_logs')->whereIn('session_id', $sessionIds)->delete();
                DB::table('StudentSingIn')->whereIn('StudentClassID', $courseIds)->delete();
                DB::table('schedules')->whereIn('student_course_id', $courseIds)->delete();
                DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->delete();
                DB::table('StudentClass')->whereIn('ID', $courseIds)->delete();
                DB::table('auth_tokens')->where('user_id', $userId)->delete();
                DB::table('UserCampus')->where('UserID', $userId)->delete();
                DB::table('User')->where('id', $userId)->delete();
                DB::table('Student')->where('id', self::STUDENT)->delete();
            });
            // Keep RefreshDatabase's existing teardown transaction balanced.
            DB::beginTransaction();
        }
    }

    /** #4210 review: mutable subjects must never hide exact leave provenance. @dataProvider changedSubjectCourses */
    public function test_subject_edits_do_not_hide_an_active_shift(string $which): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $token = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->json('preview_token');
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $token])->assertOk();
        DB::table('StudentClass')->where('ID', $which === 'old' ? $this->old : $this->renewal)->update(['SubjectID' => 2]);
        $before = $this->undoSnapshot();
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])
            ->assertStatus(422)->assertJsonPath('affected_lessons.0.course_id', $this->renewal);
        $this->assertSame($before, $this->undoSnapshot());
    }

    public static function changedSubjectCourses(): array
    {
        return ['old subject changed' => ['old'], 'renewal subject changed' => ['renewal']];
    }

    /** #4210 review: restoring slots alone does not reconcile the contract boundary. @dataProvider renewalBoundaries */
    public function test_restored_slots_require_a_reconciled_renewal_boundary(bool $reconciled): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertOk();
        foreach (array_slice($preview->json('moves'), 1) as $move) {
            DB::table('ClassSession')->where('id', $move['session_id'])->update([
                'SessionDate' => $move['from']['date'], 'StartTime' => $move['from']['start'], 'EndTime' => $move['from']['end'],
            ]);
        }
        if ($reconciled) {
            DB::table('StudentClass')->where('ID', $this->renewal)->update(['EndDate' => '2026-12-22']);
        }
        $before = $this->undoSnapshot();
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId]);
        if ($reconciled) {
            $response->assertOk();
            $this->assertSame('2026-12-22', substr((string) DB::table('StudentClass')->where('ID', $this->renewal)->value('EndDate'), 0, 10));
        } else {
            $response->assertStatus(422)->assertJsonPath('affected_lessons.0.reason', 'renewal_end_date_mismatch')
                ->assertJsonPath('affected_lessons.0.course_id', $this->renewal);
            $this->assertSame($before, $this->undoSnapshot(), 'Q9 never repairs the boundary during undo');
        }
    }

    public static function renewalBoundaries(): array
    {
        return ['stale end date' => [false], 'reconciled end date' => [true]];
    }

    /** #4210 review: provenance lookup must retain the authorized student's boundary. */
    public function test_another_campus_student_marker_is_not_exposed_or_used(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        DB::table('ClassSession')->where('id', $leaveId)->update(['Status' => 'leave']);
        $this->student(88411);
        DB::table('Student')->where('id', 88411)->update(['CampusID' => 2]);
        $other = $this->course(88411, '2026-12-01', '2026-12-08');
        $otherLesson = $this->lesson($other, '2026-12-08');
        DB::table('ClassSession')->where('id', $otherLesson)->update([
            'Note' => 'successor-shift-after-leave:ls=' . $leaveId . ':from=2026-12-01T19:00',
        ]);
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])->assertOk();
        $this->assertSame('2026-12-08', substr((string) DB::table('ClassSession')->where('id', $otherLesson)->value('SessionDate'), 0, 10));
    }

    /** #4212 r4300456905: a supported renewal rebuild must not erase the undo guard. */
    public function test_renewal_rebuild_cannot_erase_active_shift_identity(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertOk();
        $renewalIds = DB::table('ClassSession')->where('StudentClassID', $this->renewal)->pluck('id');
        $edit = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->putJson('/api/v1/student-classes/' . $this->renewal, [
                'days_of_week' => [3], 'start_time' => '16:00', 'duration_hours' => 2,
            ]);
        $this->assertSame(200, $edit->status(), $edit->getContent());
        $this->assertSame(0, DB::table('ClassSession')->whereIn('id', $renewalIds)->count(), 'exercise the real delete/rebuild path');
        $before = $this->undoSnapshot();
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])
            ->assertStatus(422)->assertJsonPath('affected_lessons.0.reason', 'shift_provenance_missing')
            ->assertJsonPath('affected_lessons.0.course_id', $this->renewal);
        $this->assertSame($before, $this->undoSnapshot());
    }

    /** #4212 r4300456910: original date/start do not prove original duration. */
    public function test_undo_requires_the_original_end_time_too(): void
    {
        DB::table('ClassSession')->where('id', $this->sessions['2026-12-01'])->update(['EndTime' => '20:00:00']);
        $leaveId = $this->sessions['2026-11-17'];
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertOk();
        foreach (array_slice($preview->json('moves'), 1) as $move) {
            DB::table('ClassSession')->where('id', $move['session_id'])->update([
                'SessionDate' => $move['from']['date'], 'StartTime' => $move['from']['start'],
            ]);
        }
        DB::table('StudentClass')->where('ID', $this->renewal)->update(['EndDate' => '2026-12-22']);
        $before = $this->undoSnapshot();
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])
            ->assertStatus(422)->assertJsonPath('affected_lessons.0.from.end', '20:00:00')
            ->assertJsonPath('affected_lessons.0.to.end', '21:00:00');
        $this->assertSame($before, $this->undoSnapshot());
        foreach (array_slice($preview->json('moves'), 1) as $move) {
            DB::table('ClassSession')->where('id', $move['session_id'])->update(['EndTime' => $move['from']['end']]);
        }
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])->assertOk();
    }

    /** #4212 r4300456914: reconciled desync uses cascade cleanup, preserving consumed tails. @dataProvider shiftTailStates */
    public function test_reconciled_desync_undo_cleans_up_only_a_safe_owned_tail(string $state, string $entry = 'session'): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        if ($state === 'historical_policy') { // Legacy undo preserves this note on scheduled occurrences.
            DB::table('ClassSession')->where('id', $leaveId)->update(['Note' => 'leave-policy-shift']);
        }
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])->assertOk();
        foreach (array_slice($preview->json('moves'), 1) as $move) {
            DB::table('ClassSession')->where('id', $move['session_id'])->update([
                'SessionDate' => $move['from']['date'], 'StartTime' => $move['from']['start'], 'EndTime' => $move['from']['end'],
            ]);
        }
        DB::table('StudentClass')->where('ID', $this->renewal)->update(['EndDate' => '2026-12-22']);
        if ($entry === 'session') {
            DB::table('ClassSession')->where('id', $leaveId)->update(['Status' => 'scheduled']);
        }
        $tail = DB::table('ClassSession')->where('StudentClassID', $this->old)->where('Note', 'like', 'auto-extended-after-leave%:succ=%')->value('id');
        if ($state === 'consumed') {
            DB::table('ClassSession')->where('id', $tail)->update(['Status' => 'completed']);
        } elseif ($state === 'ledger') {
            SessionDeductionLedger::create(['student_class_id' => $this->old, 'class_session_id' => $tail, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
        } elseif ($state === 'past') {
            DB::table('ClassSession')->where('id', $tail)->update(['SessionDate' => '2026-10-31']);
        }
        if (in_array($state, ['elapsed_today', 'later_today'], true)) {
            Carbon::setTestNow(Carbon::parse('2026-12-01 ' . ($state === 'elapsed_today' ? '22:00:00' : '18:00:00'), 'Asia/Taipei'));
            AuthToken::query()->where('token', $this->token)->update(['expires_at' => now()->addDay()]);
        }
        $url = '/api/v1/schedules/undo-leave-by-session';
        if ($entry === 'schedule') {
            $marker = \App\Models\Schedule::create(['student_id' => self::STUDENT, 'student_course_id' => $this->old, 'teacher_id' => self::TEACHER,
                'branch_id' => 1, 'schedule_date' => '2026-11-17', 'day_of_week' => 2, 'start_time' => '18:00', 'end_time' => '20:00', 'status' => 'leave', 'type' => 'regular']);
            $url = '/api/v1/schedules/' . $marker->id . '/undo-leave'; // Stale start deliberately resolves no ID.
        }
        $before = $this->undoSnapshot();
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson($url, ['class_session_id' => $leaveId]);
        if (!in_array($state, ['scheduled', 'later_today', 'historical_policy'], true)) {
            $response->assertStatus(422);
            $this->assertSame($before, $this->undoSnapshot());
        } else {
            $response->assertOk()->assertJsonPath('extended_end_date', '2026-11-24');
            $this->assertFalse(DB::table('ClassSession')->where('id', $tail)->exists());
            $this->assertSame(0, DB::table('StudentSingIn')->where('ClassSessionID', $leaveId)->whereNull('VoidedAt')->count());
            $this->assertSame(4, DB::table('ClassSession')->where('StudentClassID', $this->old)->count());
            // Historical provenance must not trap a later ordinary leave on this occurrence.
            DB::table('ClassSession')->where('id', $leaveId)->update(['Status' => 'leave']);
            $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
                ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])->assertOk();
        }
    }

    public static function shiftTailStates(): array
    {
        return ['scheduled tail' => ['scheduled'], 'consumed tail' => ['consumed'], 'past tail' => ['past'], 'elapsed today' => ['elapsed_today'], 'later today' => ['later_today'], 'historical policy' => ['historical_policy'],
            'schedule elapsed' => ['elapsed_today', 'schedule'], 'schedule ledger' => ['ledger', 'schedule'],
            'schedule safe' => ['scheduled', 'schedule'], 'schedule historical' => ['historical_policy', 'schedule']];
    }

    /** @dataProvider noteCapacityCases */
    public function test_apply_respects_note_capacity_without_truncation(bool $old, bool $overflow): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $suffix = $old ? '; leave; successor-shift-anchor:succ=' . $this->renewal . ':n=4:state=active; revert-to-scheduled'
            : '; successor-shift-after-leave:ls=' . $leaveId . ':from=2026-12-01T19:00:00:end=21:00:00';
        $text = str_repeat('備', 255 - mb_strlen($suffix) + (int) $overflow);
        $target = $old ? $leaveId : $this->sessions['2026-12-01'];
        DB::table('ClassSession')->where('id', $target)->update(['Note' => $text]);
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $before = $this->undoSnapshot();
        $response = $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')]);
        if ($overflow) {
            $response->assertStatus(422)->assertJsonPath('plan.affected_lessons.0.reason', 'shift_note_capacity');
            $this->assertSame($before, $this->undoSnapshot());
        } else {
            $response->assertOk()->assertJsonPath('applied', true);
            foreach (array_slice($preview->json('moves'), 1) as $move) {
                DB::table('ClassSession')->where('id', $move['session_id'])->update([
                    'SessionDate' => $move['from']['date'], 'StartTime' => $move['from']['start'], 'EndTime' => $move['from']['end'],
                ]);
            }
            DB::table('StudentClass')->where('ID', $this->renewal)->update(['EndDate' => '2026-12-22']);
            $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
                ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])->assertOk();
            $note = DB::table('ClassSession')->where('id', $target)->value('Note');
            $this->assertStringContainsString($text, $note);
            $this->assertSame(255, mb_strlen($note));
        }
    }

    public static function noteCapacityCases(): array
    {
        return ['old leave overflow' => [true, true], 'renewal overflow' => [false, true], 'old leave fits' => [true, false], 'renewal fits' => [false, false]];
    }

    private function undoSnapshot(): string
    {
        return (string) json_encode(array_map(fn ($table) => DB::table($table)->get()->all(), [
            'ClassSession', 'StudentClass', 'StudentSingIn', 'LearningRecord', (new SessionDeductionLedger)->getTable(), 'schedules',
            'Notifications', 'schedule_audit_logs',
        ]));
    }

    private function assertBlockedWithoutWrites(string $type, ?int $sessionId): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $before = $this->fingerprint();
        $preview = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk();
        $preview->assertJsonPath('applicable', true)->assertJsonPath('eligible', false);
        $blockers = collect($preview->json('blockers'))->where('type', $type);
        $this->assertTrue($blockers->isNotEmpty(), (string) json_encode($preview->json('blockers')));
        if ($sessionId !== null) {
            $this->assertSame($sessionId, $blockers->first()['session_id']);
        }
        // Q9(a): every blocker names the course (and lesson, when one moves) the director opens to fix it.
        $this->assertContains($blockers->first()['course_id'] ?? null, [$this->old, $this->renewal]);
        $this->assertArrayHasKey('session_id', $blockers->first());

        $this->shift('apply', ['class_session_id' => $leaveId, 'preview_token' => $preview->json('preview_token')])
            ->assertStatus(422)->assertJsonPath('plan.blockers.0.course_id', $preview->json('blockers.0.course_id'));
        $this->assertSame($before, $this->fingerprint());
    }

    /** @param array<string, mixed> $body */
    private function shift(string $action, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/schedules/leave-successor-shift/' . $action, $body);
    }

    /** @return list<string> */
    private function fingerprint(): array
    {
        $rows = DB::table('ClassSession')->orderBy('id')->get()
            ->map(fn ($s) => implode('|', [$s->id, $s->StudentClassID, substr((string) $s->SessionDate, 0, 10), $s->StartTime, $s->Status]))->all();
        $ends = DB::table('StudentClass')->orderBy('ID')->pluck('EndDate')->map(fn ($d) => substr((string) $d, 0, 10))->all();

        return array_merge($rows, $ends, ['signins=' . DB::table('StudentSingIn')->count()]);
    }

    /** @return list<string> */
    private function liveDates(int $courseId): array
    {
        return DB::table('ClassSession')->where('StudentClassID', $courseId)->where('Status', 'scheduled')
            ->orderBy('SessionDate')->pluck('SessionDate')->map(fn ($d) => substr((string) $d, 0, 10))->all();
    }

    /** @return array{int, int} */
    private function counters(int $courseId): array
    {
        $row = DB::table('StudentClass')->where('ID', $courseId)->first();

        return [(int) $row->RemainingSessions, (int) $row->UsedSessions];
    }

    private function student(int $id): void
    {
        DB::table('Student')->insert(['id' => $id, 'name' => 'S' . $id, 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
    }

    /** @param array<string, mixed> $overrides */
    private function course(int $studentId, string $start, string $end, array $overrides = []): int
    {
        return (int) DB::table('StudentClass')->insertGetId($overrides + [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => self::TEACHER, 'by1' => 1,
            'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 0,
            'ClassType' => 'one_on_one', 'StartDate' => $start, 'EndDate' => $end, 'SessionCount' => 4,
            'SessionDuration' => 120, 'RemainingSessions' => 4, 'UsedSessions' => 0, 'Stop' => 0,
            'ScheduleMode' => 'count', 'week' => 2, 'time' => '19:00:00',
        ]);
    }

    private function lesson(int $courseId, string $date): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId, 'SessionDate' => $date, 'StartTime' => '19:00:00', 'EndTime' => '21:00:00',
            'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function directorToken(): string
    {
        $user = User::create(['LoginName' => 'director-410@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
