<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #4188: a plain pause on /student-classes/{id}/pause must not undo a committed close. */
class StudentClassPauseCloseGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_plain_pause_after_committed_close_is_refused_and_close_survives(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture();
        $future = $this->createClassSession((int) $course->ID, '2026-10-20', 'scheduled');

        $this->close($token, $course)->assertOk();
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $future)->value('Status'));

        $this->lifecycle($token, $course, ['action' => 'pause', 'cancel_remaining' => true])->assertStatus(409)
            ->assertJsonPath('error_code', 'course_already_closed')
            ->assertJsonPath('closed_reason', 'settled');

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('settled', $course->closed_reason);
        $this->assertSame('2026-10-16', substr((string) $course->EndDate, 0, 10));
    }

    public function test_plain_pause_after_pending_reconciliation_close_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture(['Paid' => 0, 'Charge' => 8000]);

        $this->close($token, $course)->assertOk()->assertJsonPath('pending_reconciliation', true);

        $this->lifecycle($token, $course, ['action' => 'pause'])->assertStatus(409)->assertJsonPath('error_code', 'course_already_closed');

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('settled_pending', $course->closed_reason);
    }

    public function test_close_after_plain_pause_still_closes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture();

        $this->lifecycle($token, $course, ['action' => 'pause'])->assertOk();
        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('completed', $course->closed_reason, 'Paid count course with nothing left pauses as completed (unchanged).');

        $this->close($token, $course)->assertOk();
        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('settled', $course->closed_reason);
    }

    public function test_close_after_plain_pause_of_course_with_remaining_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture(['RemainingSessions' => 3, 'UsedSessions' => 5]);

        $this->lifecycle($token, $course, ['action' => 'pause'])->assertOk();
        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertNull($course->closed_reason);

        // A paused course that is not closed can still be paused again (idempotent, unchanged).
        $this->lifecycle($token, $course, ['action' => 'pause'])->assertOk();

        $this->lifecycle($token, $course, ['action' => 'pause', 'reason' => 'settled', 'forfeit_remaining' => true])->assertOk();
        $course->refresh();
        $this->assertSame('settled', $course->closed_reason);
    }

    public function test_resume_after_committed_close_is_refused_and_row_unchanged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture(['RemainingSessions' => 2, 'UsedSessions' => 6]);
        $past = $this->createClassSession((int) $course->ID, '2026-10-10', 'attended');
        $future = $this->createClassSession((int) $course->ID, '2026-10-20', 'scheduled');

        $this->lifecycle($token, $course, ['action' => 'pause', 'reason' => 'settled', 'forfeit_remaining' => true])->assertOk();
        $before = (array) DB::table('StudentClass')->where('ID', $course->ID)->first();
        $futureNote = DB::table('ClassSession')->where('id', $future)->value('Note');

        $this->lifecycle($token, $course, ['action' => 'resume'])->assertStatus(409)
            ->assertJsonPath('error_code', 'course_closed_use_renewal')
            ->assertJsonPath('closed_reason', 'settled');

        $this->assertSame($before, (array) DB::table('StudentClass')->where('ID', $course->ID)->first());
        $this->assertSame('attended', DB::table('ClassSession')->where('id', $past)->value('Status'));
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $future)->value('Status'));
        $this->assertSame($futureNote, DB::table('ClassSession')->where('id', $future)->value('Note'));
    }

    public function test_resume_after_pending_reconciliation_close_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture(['Paid' => 0, 'Charge' => 8000]);
        $this->close($token, $course)->assertOk();

        $this->lifecycle($token, $course, ['action' => 'resume'])->assertStatus(409)->assertJsonPath('error_code', 'course_closed_use_renewal');

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('settled_pending', $course->closed_reason);
    }

    public function test_resume_of_merely_paused_course_still_works(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture(['RemainingSessions' => 3, 'UsedSessions' => 5]);
        $future = $this->createClassSession((int) $course->ID, '2026-10-20', 'scheduled');

        $this->lifecycle($token, $course, ['action' => 'pause'])->assertOk();
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $future)->value('Status'));

        $this->lifecycle($token, $course, ['action' => 'resume'])->assertOk()->assertJsonPath('restored_count', 1);

        $course->refresh();
        $this->assertSame(0, (int) $course->Stop);
        $this->assertNull($course->closed_reason);
        $this->assertSame('scheduled', DB::table('ClassSession')->where('id', $future)->value('Status'));
    }

    public function test_unsupported_reason_cannot_bypass_the_close_guard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture();
        $this->close($token, $course)->assertOk();

        $this->lifecycle($token, $course, ['action' => 'pause', 'reason' => 'manual'])->assertStatus(422)->assertJsonPath('error_code', 'invalid_close_reason');

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('settled', $course->closed_reason);
    }

    public function test_plain_pause_keeps_a_close_reason_when_stop_has_drifted_to_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));
        [$token, $course] = $this->fixture(['RemainingSessions' => 3, 'UsedSessions' => 5, 'Stop' => 0]);
        DB::table('StudentClass')->where('ID', $course->ID)->update(['closed_reason' => 'settled']);

        $this->lifecycle($token, $course, ['action' => 'pause'])->assertStatus(409)->assertJsonPath('error_code', 'course_already_closed');

        $course->refresh();
        $this->assertSame(0, (int) $course->Stop);
        $this->assertSame('settled', $course->closed_reason);
    }

    /** @return array{0: string, 1: StudentClass} */
    /** Founder 2026-10-28: only a super admin undoes a mistaken close, with a reason; audited. */
    public function test_super_admin_reopens_a_committed_close_with_a_reason(): void
    {
        [$director, $course] = $this->fixture();
        $this->close($director, $course)->assertOk();
        $this->assertSame('settled', $course->fresh()->closed_reason);
        // A director keeps the 續課 answer, even with a reason.
        $this->lifecycle($director, $course, ['action' => 'resume', 'reopen_reason' => '按錯結案'])->assertStatus(409)
            ->assertJsonPath('error_code', 'course_closed_use_renewal');
        $admin = $this->createSuperAdminToken();
        // A super admin without a reason is refused too.
        $this->lifecycle($admin, $course, ['action' => 'resume'])->assertStatus(409);
        $this->lifecycle($admin, $course, ['action' => 'resume', 'reopen_reason' => '主任按錯結案'])->assertOk();
        $fresh = $course->fresh();
        $this->assertSame(0, (int) $fresh->Stop);
        $this->assertNull($fresh->closed_reason);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('security_audit_events')
            ->where('event_type', 'student_class.close_reopened')->count());
    }

    public function test_super_admin_cannot_reopen_a_waived_or_converted_close(): void
    {
        $admin = $this->createSuperAdminToken();
        foreach (['waived', 'converted_trial'] as $closed) {
            [, $course] = $this->fixture(['Stop' => 1, 'closed_reason' => $closed]);
            // waived is settlement-locked (422); converted_trial keeps the 續課 answer (409).
            $this->assertContains($this->lifecycle($admin, $course, ['action' => 'resume', 'reopen_reason' => '測試'])->status(), [409, 422]);
            $this->assertSame($closed, $course->fresh()->closed_reason);
        }
    }

    private function createSuperAdminToken(): string
    {
        $user = User::create([
            'LoginName' => 'pause-guard-sa-' . uniqid() . '@example.com', 'Name' => '超級管理員',
            'PSW' => 'secret', 'type' => 'S', 'phone' => '0900000001',
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function fixture(array $overrides = []): array
    {
        $campus = Campus::factory()->create();
        $token = $this->createDirectorToken((int) $campus->id);
        $student = Student::create([
            'name' => 'Pause guard fixture', 'CampusID' => $campus->id, 'ClassID' => 1,
            'SchoolName' => 'Test School', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1,
            'Period' => 4, 'StartDate' => '2026-09-01', 'TotalHours' => 16, 'Charge' => 0, 'Paid' => 1,
            'Rate' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 8,
        ], $overrides));

        return [$token, $course];
    }

    private function close(string $token, StudentClass $course): \Illuminate\Testing\TestResponse
    {
        return $this->lifecycle($token, $course, ['action' => 'pause', 'reason' => 'settled']);
    }

    private function lifecycle(string $token, StudentClass $course, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/student-classes/{$course->ID}/pause", $body, ['Authorization' => "Bearer {$token}"]);
    }

    private function createDirectorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'pause-guard-' . uniqid() . '@example.com', 'Name' => '暫停結案測試主任',
            'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function createClassSession(int $courseId, string $date, string $status): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId, 'SessionDate' => $date,
            'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => $status,
        ]);
    }
}
