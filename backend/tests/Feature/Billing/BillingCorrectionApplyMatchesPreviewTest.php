<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\BillingCorrection;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

/**
 * Reducing an unpaid count contract retires exactly the excess reservations
 * dated today or later (Asia/Taipei) that the preview listed and the token
 * binds. If any excess reservation is past-dated and never recorded, the
 * correction fails closed at preview and at the locked write (Founder
 * 2026-10-16): no history is cancelled and no contract is left over quota.
 */
class BillingCorrectionApplyMatchesPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_refuses_and_lists_past_unrecorded_sessions_without_writing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [$token] = $this->director();
        [$course, $s] = $this->mixedCourse();

        $res = $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => 1, 'new_charge' => 1100, 'reason' => '主任確認預覽與實際取消一致', 'preview' => true,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'billing_correction_past_unrecorded_sessions')
            ->assertJsonPath('next_step', 'record_or_cancel_past_sessions_then_retry')
            ->assertJsonCount(1, 'past_unrecorded_sessions')
            ->assertJsonPath('past_unrecorded_sessions.0.session_id', $s['past']->id)
            ->assertJsonPath('past_unrecorded_sessions.0.session_date', '2026-10-08')
            ->assertJsonPath('past_unrecorded_sessions.0.start_time', '15:00');
        $this->assertNull($res->json('confirmation_token'));
        $this->assertStringContainsString('2026-10-08 15:00–17:00', (string) $res->json('message'));

        $this->assertNothingWritten($course, $s);
    }

    public function test_locked_apply_refuses_a_valid_token_while_a_past_unrecorded_session_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [, $actorId] = $this->director();
        [$course, $s] = $this->mixedCourse();

        // A token that matches the current locked state exactly: the write
        // path must still refuse before touching anything.
        $service = app(BillingCorrection::class);
        $rows = ClassSession::where('StudentClassID', $course->ID)->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')->get();
        $excess = ContractSessionSchedule::partitionExcessScheduledRows($rows, 1);
        $diag = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$course->ID])[$course->ID] ?? [];
        $observedUsed = max((int) ($diag['expected_used'] ?? 0), (int) ($diag['uncapped_used'] ?? 0));
        $validToken = $service->confirmationToken(
            $course->fresh(), 1, 1100, $observedUsed, ContractSessionSchedule::describeRetirableSessions($excess['retirable']), $actorId, $rows
        );

        $response = $this->applyDirectly($course, 1, 1100, $validToken, $actorId);
        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('billing_correction_past_unrecorded_sessions', $body['code']);
        $this->assertSame([$s['past']->id], array_column($body['past_unrecorded_sessions'], 'session_id'));

        $this->assertNothingWritten($course, $s);
    }

    public function test_locked_apply_rejects_a_stale_token_without_writing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [, $actorId] = $this->director();
        [$course, $s] = $this->mixedCourse();
        $s['past']->update(['Status' => 'cancelled']);
        $s['past']->refresh();

        $response = $this->applyDirectly($course, 1, 1100, str_repeat('0', 64), $actorId);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('billing_correction_confirmation_stale', json_decode((string) $response->getContent(), true)['code']);

        $this->assertNothingWritten($course, $s, 'cancelled');
    }

    public function test_correction_succeeds_after_the_past_session_is_cancelled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [$token] = $this->director();
        [$course, $s] = $this->mixedCourse();
        $this->preview($token, $course, 1, 1100, 422);

        $s['past']->update(['Status' => 'cancelled']);

        $preview = $this->preview($token, $course, 1, 1100);
        $previewIds = collect($preview->json('affected_scheduled_sessions'))->pluck('session_id')->all();
        $this->assertSame([$s['today']->id, $s['tomorrow']->id, $s['later']->id], $previewIds);

        $confirmed = $this->confirm($token, $course, 1, 1100, $preview->json('confirmation_token'))->assertOk();
        $this->assertSame($previewIds, collect($confirmed->json('cancelled_scheduled_sessions'))->pluck('session_id')->all());
        $this->assertSame(
            [$s['past']->id, $s['today']->id, $s['tomorrow']->id, $s['later']->id],
            ClassSession::where('StudentClassID', $course->ID)->where('Status', 'cancelled')->where('SessionDate', '>=', '2026-10-01')->orderBy('id')->pluck('id')->all()
        );
        $this->assertSame(1, SessionDeductionLedger::where('student_class_id', $course->ID)->count());
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 1, 'Charge' => 1100, 'UsedSessions' => 1, 'RemainingSessions' => 0]);
    }

    public function test_correction_succeeds_after_the_past_session_is_recorded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [$token] = $this->director();
        [$course, $s] = $this->mixedCourse();
        $this->preview($token, $course, 1, 1100, 422);

        // Recording the past session makes it used: it now sits inside the
        // reduced count (2) instead of hanging outside it.
        $s['past']->update(['Status' => 'attended']);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $s['past']->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);

        $preview = $this->preview($token, $course, 2, 2200);
        $previewIds = collect($preview->json('affected_scheduled_sessions'))->pluck('session_id')->all();
        $this->assertSame([$s['today']->id, $s['tomorrow']->id, $s['later']->id], $previewIds);
        $this->confirm($token, $course, 2, 2200, $preview->json('confirmation_token'))->assertOk();

        $this->assertDatabaseHas('ClassSession', ['id' => $s['past']->id, 'Status' => 'attended']);
        $this->assertSame($previewIds, ClassSession::where('StudentClassID', $course->ID)->where('Status', 'cancelled')->where('SessionDate', '>=', '2026-10-01')->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, SessionDeductionLedger::where('student_class_id', $course->ID)->count());
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 2, 'Charge' => 2200, 'UsedSessions' => 2, 'RemainingSessions' => 0]);
    }

    public function test_a_confirmation_that_crosses_taipei_midnight_is_refused_without_writing(): void
    {
        // Previewed at 23:59 Taipei: the 2026-10-15 tail is retirable and listed.
        Carbon::setTestNow(Carbon::parse('2026-10-15T15:59:00Z', 'UTC'));
        [$token] = $this->director();
        [$course, $scheduled] = $this->fourSessionCourse(['2026-10-01', '2026-10-08', '2026-10-15']);
        $tail = $scheduled->last();
        $preview = $this->preview($token, $course, 3, 3300)
            ->assertJsonPath('affected_scheduled_sessions.0.session_id', $tail->id);

        // Confirmed at 00:01 Taipei: the tail is now past and unrecorded.
        Carbon::setTestNow(Carbon::parse('2026-10-15T16:01:00Z', 'UTC'));
        $this->confirm($token, $course, 3, 3300, $preview->json('confirmation_token'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_correction_past_unrecorded_sessions')
            ->assertJsonPath('past_unrecorded_sessions.0.session_id', $tail->id);

        $this->assertDatabaseHas('ClassSession', ['id' => $tail->id, 'Status' => 'scheduled']);
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 4, 'Charge' => 4400]);
    }

    public function test_a_past_leave_recorded_only_by_sign_in_neither_blocks_nor_consumes_quota(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [$token] = $this->director();
        [$course, $s] = $this->mixedCourse();
        // Legacy leave: active StudentSingIn leave row, ClassSession left `scheduled`.
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $course->StudentID, 'CampusID' => 1,
            'ClassSessionID' => $s['past']->id, 'SignInDT' => '2026-10-08 15:00:00', 'SignOutDT' => '2026-10-08 17:00:00', 'Status' => 'leave']);

        $preview = $this->preview($token, $course, 1, 1100);
        $previewIds = collect($preview->json('affected_scheduled_sessions'))->pluck('session_id')->all();
        $this->assertSame([$s['today']->id, $s['tomorrow']->id, $s['later']->id], $previewIds);
        $this->confirm($token, $course, 1, 1100, $preview->json('confirmation_token'))->assertOk();

        $this->assertDatabaseHas('ClassSession', ['id' => $s['past']->id, 'Status' => 'scheduled']);
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 1, 'Charge' => 1100]);
    }

    public function test_attended_rows_reserve_quota_before_an_older_unrecorded_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Asia/Taipei'));
        [$token, $actorId] = $this->director();
        $course = $this->course($this->student()->id, ['SessionCount' => 2, 'Charge' => 2200, 'RemainingSessions' => 1, 'UsedSessions' => 1]);
        $past = $this->scheduled($course, '2026-10-08');
        $attended = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-10-14', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $attended->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);

        $this->preview($token, $course, 1, 1100, 422)
            ->assertJsonPath('code', 'billing_correction_past_unrecorded_sessions')
            ->assertJsonPath('past_unrecorded_sessions.0.session_id', $past->id);
        $response = $this->applyDirectly($course, 1, 1100, str_repeat('0', 64), $actorId);
        $this->assertSame('billing_correction_past_unrecorded_sessions', json_decode((string) $response->getContent(), true)['code']);

        $this->assertDatabaseHas('ClassSession', ['id' => $past->id, 'Status' => 'scheduled']);
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 2, 'Charge' => 2200]);
    }

    private function applyDirectly(StudentClass $course, int $count, int $charge, string $confirmationToken, int $actorId)
    {
        try {
            app(BillingCorrection::class)->applyCountCorrection(
                $course->fresh(), $count, $charge, '主任確認預覽與實際取消一致', (int) $course->SessionCount, (int) $course->Charge, $confirmationToken, $actorId
            );
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }
        $this->fail('applyCountCorrection must refuse');
    }

    /** @return array{0: StudentClass, 1: array<string, ClassSession>} */
    private function mixedCourse(): array
    {
        $student = $this->student();
        $course = $this->course($student->id, ['SessionCount' => 5, 'Charge' => 5500, 'RemainingSessions' => 4, 'UsedSessions' => 1]);
        $attended = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-01', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'attended']);
        SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $attended->id, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-25', 'TotalAmount' => 5500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => '數學', 'Amount' => 5500]);

        return [$course, [
            'attended' => $attended,
            'invoice' => $invoice,
            'past' => $this->scheduled($course, '2026-10-08'),
            'today' => $this->scheduled($course, '2026-10-15'),
            'tomorrow' => $this->scheduled($course, '2026-10-16'),
            'later' => $this->scheduled($course, '2026-10-22'),
        ]];
    }

    private function assertNothingWritten(StudentClass $course, array $s, string $pastStatus = 'scheduled'): void
    {
        $this->assertDatabaseHas('StudentClass', ['ID' => $course->ID, 'SessionCount' => 5, 'Charge' => 5500, 'UsedSessions' => 1, 'RemainingSessions' => 4]);
        $this->assertDatabaseHas('Invoice', ['id' => $s['invoice']->id, 'TotalAmount' => 5500]);
        $this->assertDatabaseHas('InvoiceItem', ['InvoiceID' => $s['invoice']->id, 'Amount' => 5500]);
        $this->assertSame(1, SessionDeductionLedger::where('student_class_id', $course->ID)->count());
        $this->assertDatabaseHas('ClassSession', ['id' => $s['past']->id, 'Status' => $pastStatus]);
        foreach (['today', 'tomorrow', 'later'] as $key) {
            $this->assertDatabaseHas('ClassSession', ['id' => $s[$key]->id, 'Status' => 'scheduled']);
        }
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('security_audit_events')
            ->where('event_type', 'student_class.billing_contract_correction')->where('outcome', 'success')->count());
    }

    private function preview(string $token, StudentClass $course, int $count, int $charge, int $status = 200)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/billing-correction", [
            'new_session_count' => $count, 'new_charge' => $charge, 'reason' => '主任確認預覽與實際取消一致', 'preview' => true,
        ])->assertStatus($status);
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
