<?php

namespace Tests\Feature;

use App\Models\FeedbackPushLog;
use App\Models\LearningRecordFeedback;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\SessionDeductionLedger;
use App\Models\StudentSignIn;
use App\Services\FeedbackPushNotifier;
use App\Services\NotificationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #413: inbox sync re-checks deduction_failed source rows (flag-gated); lr_feedback stays
 * event-driven in FeedbackPushNotifier, which also reopens the notice on a parent follow-up.
 */
class NotificationSyncServiceResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $sessionSeq = 900;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfflags.notification_source_resolve_enabled' => true]);
    }

    private function notice(string $type, string $sourceType, int $sourceId, int $campusId = 1): Notification
    {
        return Notification::create([
            'CampusID' => $campusId, 'Type' => $type, 'Severity' => 'high', 'Title' => 't', 'Body' => 'b',
            'SourceType' => $sourceType, 'SourceID' => $sourceId, 'SourceKey' => "{$type}:{$campusId}:{$sourceId}",
            'Payload' => [], 'OccurredAt' => now(),
        ]);
    }

    private function signIn(array $overrides = []): StudentSignIn
    {
        return StudentSignIn::create(array_merge([
            'StudentID' => 501, 'StudentClassID' => 601, 'ClassSessionID' => $this->sessionSeq++,
            'SignInDT' => now()->toDateTimeString(), 'Status' => 'present', 'CampusID' => 1,
            'PersonType' => 'student', 'SessionDeducted' => false, 'MDT' => now(),
        ], $overrides));
    }

    private function ledger(StudentSignIn $signIn, string $event): void
    {
        SessionDeductionLedger::create([
            'student_class_id' => (int) $signIn->StudentClassID, 'class_session_id' => (int) $signIn->ClassSessionID,
            'event_type' => $event, 'source' => 'manual',
        ]);
    }

    private function deductionNotice(StudentSignIn $signIn, int $campusId = 1): Notification
    {
        return $this->notice('deduction_failed', 'StudentSignIn', (int) $signIn->id, $campusId);
    }

    private function isOpen(Notification $n): bool
    {
        return $n->fresh()->ResolvedAt === null;
    }

    public function test_deduction_failed_stays_open_while_sign_in_is_undeducted(): void
    {
        $n = $this->deductionNotice($this->signIn());

        NotificationSyncService::sync([]);

        $this->assertTrue($this->isOpen($n));
    }

    public function test_deduction_failed_resolves_with_reason_when_handled(): void
    {
        $deducted = $this->signIn();
        $voided = $this->signIn();
        $absent = $this->signIn();
        $a = $this->deductionNotice($deducted);
        $b = $this->deductionNotice($voided);
        $c = $this->deductionNotice($absent);
        $deducted->update(['SessionDeducted' => true]);
        $voided->update(['VoidedAt' => now()]);
        DB::table('StudentSingIn')->where('id', $absent->id)->update(['Status' => 'absent']);

        $result = NotificationSyncService::sync([]);

        $this->assertSame(3, $result['source_resolved']);
        $this->assertSame('deducted', $a->fresh()->Payload[NotificationSyncService::SOURCE_RESOLVED_REASON]);
        $this->assertSame('sign_in_voided', $b->fresh()->Payload[NotificationSyncService::SOURCE_RESOLVED_REASON]);
        $this->assertSame('non_deductible_status', $c->fresh()->Payload[NotificationSyncService::SOURCE_RESOLVED_REASON]);
        $this->assertArrayHasKey(NotificationSyncService::SOURCE_RESOLVED_MARKER, $a->fresh()->Payload);
    }

    public function test_deduction_failed_resolves_on_ledger_deduct_and_reopens_unread_after_reverse(): void
    {
        $signIn = $this->signIn();
        $n = $this->deductionNotice($signIn);
        $this->ledger($signIn, 'deduct');

        NotificationSyncService::sync([]);
        $this->assertFalse($this->isOpen($n));
        $this->assertSame('ledger_deducted', $n->fresh()->Payload[NotificationSyncService::SOURCE_RESOLVED_REASON]);
        NotificationRead::create(['NotificationID' => $n->id, 'UserID' => 1, 'ReadAt' => now()]);

        $this->ledger($signIn, 'reverse');
        $result = NotificationSyncService::sync([]);

        $this->assertTrue($this->isOpen($n));
        $this->assertSame(1, $result['source_reopened']);
        $this->assertArrayNotHasKey(NotificationSyncService::SOURCE_RESOLVED_REASON, (array) $n->fresh()->Payload);
        $this->assertSame(0, NotificationRead::where('NotificationID', $n->id)->count(), 'reopened item shows unread again');
    }

    public function test_rows_resolved_before_the_lookback_are_not_rechecked(): void
    {
        $signIn = $this->signIn();
        $n = $this->deductionNotice($signIn);
        $n->forceFill(['ResolvedAt' => now()->subDays(30)])->save();

        NotificationSyncService::sync([]);

        $this->assertFalse($this->isOpen($n));
    }

    public function test_flag_off_leaves_every_notice_untouched(): void
    {
        config(['perfflags.notification_source_resolve_enabled' => false]);
        $n = $this->deductionNotice($this->signIn(['SessionDeducted' => true]));

        $result = NotificationSyncService::sync([]);

        $this->assertTrue($this->isOpen($n));
        $this->assertSame(0, $result['source_resolved']);
    }

    public function test_only_scoped_campus_and_deduction_failed_are_touched(): void
    {
        $other = $this->deductionNotice($this->signIn(['SessionDeducted' => true]), 2);
        $manual = $this->notice('system_alert', 'StudentSignIn', (int) $this->signIn(['SessionDeducted' => true])->id);
        $substitute = $this->notice('substitute', 'ClassSession', 701);
        $feedback = $this->notice('lr_feedback', 'LearningRecordFeedback', 999);

        NotificationSyncService::sync([1]);

        $this->assertTrue($this->isOpen($other), 'campus 2 not in scope');
        $this->assertTrue($this->isOpen($manual), 'manual types stay manual');
        $this->assertTrue($this->isOpen($substitute), 'substitute ResolvedAt means undone, not handled');
        $this->assertTrue($this->isOpen($feedback), 'lr_feedback is event-driven, not polled');

        NotificationSyncService::sync([2]);
        $this->assertFalse($this->isOpen($other));
    }

    private function feedbackThreadNotice(): array
    {
        config(['perfflags.feedback_push_enabled' => true]);
        $fb = LearningRecordFeedback::create([
            'learning_record_id' => 801, 'student_id' => 501, 'student_class_id' => 601, 'class_session_id' => null,
            'teacher_id' => 1, 'campus_id' => 1, 'content' => 'parent message',
        ]);
        $notifier = app(FeedbackPushNotifier::class);
        $notifier->notifyParentSubmitted($fb);
        $n = Notification::where('Type', 'lr_feedback')->firstOrFail();
        $notifier->resolveStaffNotification($fb);
        NotificationRead::create(['NotificationID' => $n->id, 'UserID' => 1, 'ReadAt' => now()]);

        return [$fb, $notifier, $n];
    }

    public function test_staff_reply_resolves_lr_feedback_notice_and_clears_reads(): void
    {
        [$fb, , $n] = $this->feedbackThreadNotice();

        $this->assertFalse($this->isOpen($n));
        $this->assertNotNull($n->fresh()->ResolvedAt);
    }

    public function test_parent_follow_up_reopens_unread_even_inside_merge_window(): void
    {
        [$fb, $notifier, $n] = $this->feedbackThreadNotice();
        $this->assertFalse($this->isOpen($n), 'staff reply path resolved it');

        $notifier->notifyParentReplied($fb); // within the merge window: no push, still reopens

        $this->assertTrue($this->isOpen($n));
        $this->assertSame(0, NotificationRead::where('NotificationID', $n->id)->count());
    }

    public function test_flag_off_producer_keeps_existing_behaviour_inside_merge_window(): void
    {
        [$fb, $notifier, $n] = $this->feedbackThreadNotice();
        config(['perfflags.notification_source_resolve_enabled' => false]);

        $notifier->notifyParentReplied($fb);

        $this->assertFalse($this->isOpen($n));
        $this->assertSame(1, NotificationRead::where('NotificationID', $n->id)->count());

        FeedbackPushLog::query()->delete();
        $notifier->notifyParentReplied($fb);
        $this->assertTrue($this->isOpen($n), 'outside the window the producer reopens as before');
    }
}
