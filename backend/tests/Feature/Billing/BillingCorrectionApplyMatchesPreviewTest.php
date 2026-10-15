<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The confirmed billing correction must cancel exactly the reservations its
 * preview listed (and its confirmation token binds): purchased-quota rows past
 * the new count that are `scheduled` and dated today or later in Asia/Taipei.
 * A past-dated `scheduled` row (attendance not yet recorded) is never retired.
 */
class BillingCorrectionApplyMatchesPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_cancels_the_same_today_or_later_rows_the_preview_listed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [$token] = $this->director();
        $student = $this->student();
        $course = $this->course($student->id, ['SessionCount' => 5, 'Charge' => 5500, 'RemainingSessions' => 4, 'UsedSessions' => 1]);
        $attended = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $attended->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
        $past = $this->scheduled($course, '2026-10-08');
        $today = $this->scheduled($course, '2026-10-15');
        $tomorrow = $this->scheduled($course, '2026-10-16');
        $later = $this->scheduled($course, '2026-10-22');

        $preview = $this->preview($token, $course, 1, 1100);
        $previewIds = collect($preview->json('affected_scheduled_sessions'))->pluck('session_id')->all();
        $this->assertSame([$today->id, $tomorrow->id, $later->id], $previewIds);

        $confirmed = $this->confirm($token, $course, 1, 1100, $preview->json('confirmation_token'))->assertOk();
        $this->assertSame($previewIds, collect($confirmed->json('cancelled_scheduled_sessions'))->pluck('session_id')->all());

        $cancelledIds = ClassSession::where('StudentClassID', $course->ID)->where('Status', 'cancelled')->orderBy('id')->pluck('id')->all();
        $this->assertSame($previewIds, $cancelledIds);
        $this->assertDatabaseHas('ClassSession', ['id' => $past->id, 'Status' => 'scheduled']);
        $this->assertDatabaseHas('ClassSession', ['id' => $attended->id, 'Status' => 'attended']);

        // No deduction is written or rewritten for untouched or retired rows.
        $this->assertSame(1, SessionDeductionLedger::where('student_class_id', $course->ID)->count());
        $this->assertSame(0, SessionDeductionLedger::where('class_session_id', $past->id)->count());
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 1, 'Charge' => 1100, 'UsedSessions' => 1, 'RemainingSessions' => 0]);
    }

    public function test_confirmation_token_rejects_apply_after_the_previewed_set_changed_at_taipei_midnight(): void
    {
        // Previewed at 23:59 Taipei: the 2026-10-15 tail is listed.
        Carbon::setTestNow(Carbon::parse('2026-10-15T15:59:00Z', 'UTC'));
        [$token] = $this->director();
        [$course, $scheduled] = $this->fourSessionCourse(['2026-10-01', '2026-10-08', '2026-10-15']);
        $tail = $scheduled->last();
        $preview = $this->preview($token, $course, 3, 3300)
            ->assertJsonPath('affected_scheduled_sessions.0.session_id', $tail->id);

        // Confirmed at 00:01 Taipei: the eligible set is now empty, so the
        // token no longer matches and nothing is written.
        Carbon::setTestNow(Carbon::parse('2026-10-15T16:01:00Z', 'UTC'));
        $this->confirm($token, $course, 3, 3300, $preview->json('confirmation_token'))->assertStatus(409);

        $this->assertDatabaseHas('ClassSession', ['id' => $tail->id, 'Status' => 'scheduled']);
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 4, 'Charge' => 4400]);
    }

    private function preview(string $token, StudentClass $course, int $count, int $charge)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => $count, 'new_charge' => $charge, 'reason' => '主任確認預覽與實際取消一致', 'preview' => true,
        ])->assertOk();
    }

    private function confirm(string $token, StudentClass $course, int $count, int $charge, ?string $confirmationToken)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => $count, 'new_charge' => $charge, 'reason' => '主任確認預覽與實際取消一致',
            'confirmation_token' => $confirmationToken,
        ]);
    }

    /** @return array{0: StudentClass, 1: \Illuminate\Support\Collection<int, ClassSession>} */
    private function fourSessionCourse(array $scheduledDates): array
    {
        $student = $this->student();
        $course = $this->course($student->id, ['SessionCount' => 4, 'Charge' => 4400, 'RemainingSessions' => 3, 'UsedSessions' => 1]);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-04-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'cancelled']);
        $attended = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $attended->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);

        return [$course, collect($scheduledDates)->map(fn (string $date) => $this->scheduled($course, $date))];
    }

    private function scheduled(StudentClass $course, string $date): ClassSession
    {
        return ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled']);
    }

    private function director(): array
    {
        $user = User::create([
            'LoginName' => 'billing-apply-preview-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return [$token, (int) $user->id];
    }

    private function student(): Student
    {
        return Student::create([
            'name' => '預覽一致測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function course(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-05-01',
            'TotalHours' => 16,
            'Charge' => 8800,
            'Paid' => 0,
            'Rate' => 1100,
            'rate_unit' => 'session',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
