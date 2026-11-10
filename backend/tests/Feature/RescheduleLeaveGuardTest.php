<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\CourseLeaveCascadeService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #5124 / in-app #527: rescheduling must not undo leave outside its cascade. */
class RescheduleLeaveGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider leaveCases */
    public function test_leave_with_appended_tail_cannot_be_rescheduled_into_an_extra_billable_lesson(int $purchased, bool $atomic): void
    {
        [$token, $courseId, $session] = $this->fixture($purchased);
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson('/api/v1/schedules/leave-by-session', [
            'class_session_id' => $session->id,
        ], $headers)->assertOk();
        $this->assertSame('leave', $session->fresh()->Status);
        $this->assertSame($purchased, $this->billableCount($courseId));
        $this->assertSame($purchased + 1, ClassSession::where('StudentClassID', $courseId)->count());
        $before = $this->businessSnapshot();

        $response = $this->postJson('/api/v1/learning-records/reschedule-session', $this->payload($courseId, $atomic), $headers);

        $this->assertSame($purchased, $this->billableCount($courseId),
            'Rescheduling a leave must not add another billable lesson; HTTP ' . $response->status());
        $response->assertStatus(422)->assertJsonPath('code', 'leave_requires_cascade_undo');
        $response->assertJsonPath('message', '請先使用「取消請假」，系統會同步回復順延堂次；不可直接改成已上或未上。');
        $this->assertSame($before, $this->businessSnapshot(), 'refusal must precede every schedule/usage/audit side effect');
    }

    public static function leaveCases(): array
    {
        return [
            'eight atomic' => [8, true], 'eight legacy' => [8, false],
            'three atomic' => [3, true], 'three legacy' => [3, false],
            'five atomic' => [5, true], 'five legacy' => [5, false],
            'twelve atomic' => [12, true], 'twelve legacy' => [12, false],
        ];
    }

    /** @dataProvider ordinaryMoves */
    public function test_ordinary_non_leave_rescheduling_keeps_the_existing_behavior(string $status, bool $atomic): void
    {
        [$token, $courseId, $session] = $this->fixture(3);
        DB::table('ClassSession')->where('id', $session->id)->update(['Status' => $status]);
        $ids = ClassSession::where('StudentClassID', $courseId)->orderBy('id')->pluck('id')->all();

        $this->postJson('/api/v1/learning-records/reschedule-session', $this->payload($courseId, $atomic), [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()->assertJsonPath('committed', true)->assertJsonPath('session_id', (int) $session->id);

        $session->refresh();
        $this->assertSame('2026-12-03', substr((string) $session->SessionDate, 0, 10));
        $this->assertSame('scheduled', $session->Status);
        $this->assertSame($ids, ClassSession::where('StudentClassID', $courseId)->orderBy('id')->pluck('id')->all());
        $this->assertSame(3, $this->billableCount($courseId));
    }

    public static function ordinaryMoves(): array
    {
        return [
            'scheduled atomic' => ['scheduled', true], 'scheduled legacy' => ['scheduled', false],
            'cancelled atomic' => ['cancelled', true], 'cancelled legacy' => ['cancelled', false],
            'absent atomic' => ['absent', true], 'absent legacy' => ['absent', false],
        ];
    }

    public function test_historical_leave_without_tail_still_requires_dedicated_undo_and_is_not_rewritten(): void
    {
        [$token, $courseId, $session] = $this->fixture(3);
        DB::table('ClassSession')->where('id', $session->id)->update(['Status' => 'leave', 'SessionDate' => '2026-11-18']);
        $before = $this->businessSnapshot();
        $payload = $this->payload($courseId);
        $payload['old_date'] = '2026-11-18';

        $this->postJson('/api/v1/learning-records/reschedule-session', $payload, [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(422)->assertJsonPath('code', 'leave_requires_cascade_undo');

        $this->assertSame($before, $this->businessSnapshot());
    }

    /** @dataProvider endedLeaveTargets */
    public function test_ended_target_keeps_leave_and_does_not_add_billable_lessons(string $date, string $start, string $end, bool $atomic): void
    {
        [$token, $courseId, $session] = $this->fixture(3);
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson('/api/v1/schedules/leave-by-session', ['class_session_id' => $session->id], $headers)->assertOk();
        $beforeCount = $this->billableCount($courseId);
        $beforeIds = ClassSession::where('StudentClassID', $courseId)->orderBy('id')->pluck('id')->all();
        $beforeLedger = DB::table('session_deduction_ledger')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $payload = $this->payload($courseId, $atomic);
        $payload['new_date'] = $date;
        $payload['start_time'] = $start;
        $payload['end_time'] = $end;

        $this->postJson('/api/v1/learning-records/reschedule-session', $payload, $headers)
            ->assertOk()->assertJsonPath('committed', true)->assertJsonPath('reset_to_scheduled', false);

        $this->assertSame('leave', $session->fresh()->Status);
        $this->assertSame($date, substr((string) $session->fresh()->SessionDate, 0, 10));
        $this->assertSame($beforeCount, $this->billableCount($courseId));
        $this->assertSame($beforeIds, ClassSession::where('StudentClassID', $courseId)->orderBy('id')->pluck('id')->all());
        $this->assertSame($beforeLedger, DB::table('session_deduction_ledger')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public static function endedLeaveTargets(): array
    {
        return [
            'past atomic' => ['2026-11-24', '16:00', '18:00', true],
            'past legacy' => ['2026-11-24', '16:00', '18:00', false],
            'today elapsed atomic' => ['2026-12-01', '07:00', '09:00', true],
            'today elapsed legacy' => ['2026-12-01', '07:00', '09:00', false],
            'today exact end atomic' => ['2026-12-01', '08:00', '10:00', true],
            'today exact end legacy' => ['2026-12-01', '08:00', '10:00', false],
        ];
    }

    /** @dataProvider unendedLeaveTargets */
    public function test_unended_target_is_refused_before_writes_using_the_effective_end_time(string $start, ?string $end, bool $atomic): void
    {
        [$token, $courseId, $session] = $this->fixture(3);
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson('/api/v1/schedules/leave-by-session', ['class_session_id' => $session->id], $headers)->assertOk();
        $before = $this->businessSnapshot();
        $payload = $this->payload($courseId, $atomic);
        $payload['new_date'] = '2026-12-01';
        $payload['start_time'] = $start;
        if ($end === null) {
            unset($payload['end_time']); // Existing source end18:00, rather than a separate guard fallback.
        } else {
            $payload['end_time'] = $end;
        }

        $this->postJson('/api/v1/learning-records/reschedule-session', $payload, $headers)
            ->assertStatus(422)->assertJsonPath('code', 'leave_requires_cascade_undo');
        $this->assertSame($before, $this->businessSnapshot());
    }

    public static function unendedLeaveTargets(): array
    {
        return [
            'today ongoing atomic' => ['08:00', '10:01', true], 'today ongoing legacy' => ['08:00', '10:01', false],
            'today future atomic' => ['20:00', '22:00', true], 'today future legacy' => ['20:00', '22:00', false],
            'source end fallback atomic' => ['16:00', null, true], 'source end fallback legacy' => ['16:00', null, false],
        ];
    }

    public function test_repeating_a_refused_same_slot_leave_request_has_no_side_effects(): void
    {
        [$token, $courseId, $session] = $this->fixture(3);
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson('/api/v1/schedules/leave-by-session', ['class_session_id' => $session->id], $headers)->assertOk();
        $before = $this->businessSnapshot();
        $payload = $this->payload($courseId);
        $payload['new_date'] = $payload['old_date'];
        $payload['start_time'] = '16:00';
        $payload['end_time'] = '18:00';

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/learning-records/reschedule-session', $payload, $headers)
                ->assertStatus(422)->assertJsonPath('code', 'leave_requires_cascade_undo');
        }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_cross_campus_refusal_still_precedes_leave_guard_without_writes(): void
    {
        [$token, $courseId, $session] = $this->fixture(3);
        $this->postJson('/api/v1/schedules/leave-by-session', ['class_session_id' => $session->id], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();
        $other = Campus::factory()->create();
        $actorId = AuthToken::where('token', $token)->value('user_id');
        UserCampus::where('UserID', $actorId)->update(['CampusID' => $other->id]);
        $before = $this->businessSnapshot();

        $this->postJson('/api/v1/learning-records/reschedule-session', $this->payload($courseId), [
            'Authorization' => "Bearer {$token}",
        ])->assertForbidden();

        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_dedicated_undo_restores_the_course_then_ordinary_reschedule_is_allowed(): void
    {
        [$token, $courseId, $session] = $this->fixture(3);
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson('/api/v1/schedules/leave-by-session', ['class_session_id' => $session->id], $headers)->assertOk();
        $this->postJson('/api/v1/schedules/undo-leave-by-session', ['class_session_id' => $session->id], $headers)->assertOk();
        $this->assertSame('scheduled', $session->fresh()->Status);
        $this->assertSame(3, $this->billableCount($courseId));

        $this->postJson('/api/v1/learning-records/reschedule-session', $this->payload($courseId), $headers)
            ->assertOk()->assertJsonPath('committed', true)->assertJsonPath('session_id', (int) $session->id);
        $this->assertSame(3, $this->billableCount($courseId));
    }

    private function payload(int $courseId, bool $atomic = true): array
    {
        return [
            'student_class_id' => $courseId, 'old_date' => '2026-12-02', 'old_start_time' => '16:00',
            'new_date' => '2026-12-03', 'start_time' => '20:00', 'end_time' => '22:00',
            'ensure_schedule_exception' => $atomic,
        ];
    }

    /** @return array{string,int,ClassSession} */
    private function fixture(int $purchased): array
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'Asia/Taipei'));
        $campus = Campus::factory()->create();
        $teacher = User::create([
            'LoginName' => 'leave-guard-teacher-' . uniqid() . '@example.test',
            'Name' => 'Synthetic teacher', 'PSW' => 'fixture', 'type' => 'T', 'phone' => 912345678,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $director = User::create([
            'LoginName' => 'leave-guard-director-' . uniqid() . '@example.test',
            'Name' => 'Synthetic director', 'PSW' => 'fixture', 'type' => 'A', 'phone' => 912345678,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::factory()->create(['CampusID' => $campus->id]);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-12-02', 'TotalHours' => 0,
            'Charge' => 0, 'Pay' => 0, 'Paid' => 1, 'Rate' => 0, 'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count', 'scheduling_policy' => 'auto_recurrence', 'SessionCount' => $purchased,
            'SessionDuration' => 120, 'RemainingSessions' => $purchased, 'UsedSessions' => 0,
            'Stop' => 0, 'week' => 3, 'time' => '16:00:00',
        ]);
        $first = null;
        for ($i = 0; $i < $purchased; $i++) {
            $session = ClassSession::create([
                'StudentClassID' => $courseId, 'SessionDate' => Carbon::parse('2026-12-02')->addWeeks($i)->toDateString(),
                'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled',
            ]);
            $first ??= $session;
        }
        // Keep real-shaped teaching evidence on another stable lesson. A refusal must
        // preserve its assessment text/author, attendance, debit and course counters too.
        $history = ClassSession::where('StudentClassID', $courseId)->where('id', '!=', $first->id)->orderBy('id')->firstOrFail();
        DB::table('ClassSession')->where('id', $history->id)->update(['SessionDate' => '2026-11-25', 'Status' => 'attended']);
        LearningRecord::create([
            'StudentClassID' => $courseId, 'ClassSessionID' => $history->id, 'StudentID' => $student->id,
            'TeacherID' => $teacher->id, 'SessionDate' => '2026-11-25', 'StartTime' => '16:00', 'EndTime' => '18:00',
            'Content' => 'Synthetic preserved assessment', 'Status' => 'approved', 'RecordedByUserID' => $teacher->id,
        ]);
        StudentSignIn::create([
            'StudentClassID' => $courseId, 'ClassSessionID' => $history->id, 'StudentID' => $student->id,
            'TeacherID' => $teacher->id, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => $campus->id,
            'SignInDT' => '2026-11-25 16:00:00', 'MDT' => now(), 'Status' => 'present', 'SessionDeducted' => 1,
        ]);
        SessionDeductionLedger::create([
            'student_class_id' => $courseId, 'class_session_id' => $history->id,
            'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120,
        ]);
        SessionDeductionService::recomputeCounters($courseId);

        return [$token, $courseId, $first];
    }

    private function billableCount(int $courseId): int
    {
        return ClassSession::where('StudentClassID', $courseId)
            ->whereNotIn('Status', CourseLeaveCascadeService::NON_BILLABLE_STATUSES)->count();
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function businessSnapshot(): array
    {
        $snapshot = [];
        foreach (['StudentClass', 'ClassSession', 'schedules', 'LearningRecord', 'StudentSingIn',
            'session_deduction_ledger', 'schedule_change_log', 'schedule_audit_logs', 'security_audit_events', 'User'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
