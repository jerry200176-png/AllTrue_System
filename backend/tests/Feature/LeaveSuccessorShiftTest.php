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

    public function test_failure_mid_apply_rolls_back_every_write(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        $token = $this->shift('preview', ['class_session_id' => $leaveId])->assertOk()->json('preview_token');
        $before = $this->fingerprint();
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

    /** #4210: provenance alone is historical once every original slot is restored. */
    public function test_restored_shift_provenance_does_not_block_undo(): void
    {
        $leaveId = $this->sessions['2026-11-17'];
        DB::table('ClassSession')->where('id', $leaveId)->update(['Status' => 'leave']);
        DB::table('ClassSession')->where('id', $this->sessions['2026-12-01'])->update([
            'Note' => 'successor-shift-after-leave:ls=' . $leaveId . ':from=2026-12-01T19:00',
        ]);
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $leaveId])->assertOk();
        $this->assertSame('scheduled', DB::table('ClassSession')->where('id', $leaveId)->value('Status'));
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

    private function undoSnapshot(): string
    {
        return (string) json_encode(array_map(fn ($table) => DB::table($table)->get()->all(), [
            'ClassSession', 'StudentClass', 'StudentSingIn', 'LearningRecord', (new SessionDeductionLedger)->getTable(), 'schedules',
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
