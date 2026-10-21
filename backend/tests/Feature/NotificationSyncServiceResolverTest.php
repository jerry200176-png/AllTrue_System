<?php

namespace Tests\Feature;

use App\Models\LearningRecordFeedback;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\SessionDeductionLedger;
use App\Models\StudentSignIn;
use App\Services\NotificationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** in-app #413: the director inbox re-checks source rows of deduction_failed / lr_feedback and clears handled items. */
class NotificationSyncServiceResolverTest extends TestCase
{
    use RefreshDatabase;

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
            'StudentID' => 501, 'StudentClassID' => 601, 'ClassSessionID' => 701, 'SignInDT' => now()->toDateTimeString(),
            'Status' => 'present', 'CampusID' => 1, 'PersonType' => 'student', 'SessionDeducted' => false, 'MDT' => now(),
        ], $overrides));
    }

    private function ledger(string $event): void
    {
        SessionDeductionLedger::create([
            'student_class_id' => 601, 'class_session_id' => 701, 'event_type' => $event, 'source' => 'manual',
        ]);
    }

    private function feedback(int $campusId = 1): LearningRecordFeedback
    {
        return LearningRecordFeedback::create([
            'learning_record_id' => 801, 'student_id' => 501, 'student_class_id' => 601, 'class_session_id' => null,
            'teacher_id' => 1, 'campus_id' => $campusId, 'content' => 'parent message',
        ]);
    }

    private function reply(LearningRecordFeedback $feedback, string $role, string $at): void
    {
        DB::table('learning_record_feedback_replies')->insert([
            'feedback_id' => $feedback->id, 'author_role' => $role, 'content' => 'x', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function isOpen(Notification $n): bool
    {
        return $n->fresh()->ResolvedAt === null;
    }

    public function test_deduction_failed_stays_open_while_sign_in_is_undeducted(): void
    {
        $n = $this->notice('deduction_failed', 'StudentSignIn', (int) $this->signIn()->id);

        NotificationSyncService::sync([]);

        $this->assertTrue($this->isOpen($n));
    }

    public function test_deduction_failed_resolves_when_sign_in_is_deducted_voided_or_leave(): void
    {
        $deducted = $this->signIn();
        $voided = $this->signIn(['ClassSessionID' => 702]);
        $leave = $this->signIn(['ClassSessionID' => 703]);
        $a = $this->notice('deduction_failed', 'StudentSignIn', (int) $deducted->id);
        $b = $this->notice('deduction_failed', 'StudentSignIn', (int) $voided->id);
        $c = $this->notice('deduction_failed', 'StudentSignIn', (int) $leave->id);
        $deducted->update(['SessionDeducted' => true]);
        $voided->update(['VoidedAt' => now()]);
        DB::table('StudentSingIn')->where('id', $leave->id)->update(['Status' => 'leave', 'SignOutDT' => now()]);

        $result = NotificationSyncService::sync([]);

        $this->assertFalse($this->isOpen($a));
        $this->assertFalse($this->isOpen($b));
        $this->assertFalse($this->isOpen($c));
        $this->assertSame(3, $result['source_resolved']);
    }

    public function test_deduction_failed_resolves_on_ledger_deduct_and_reopens_after_reverse(): void
    {
        $n = $this->notice('deduction_failed', 'StudentSignIn', (int) $this->signIn()->id);
        $this->ledger('deduct');

        NotificationSyncService::sync([]);
        $this->assertFalse($this->isOpen($n));
        NotificationRead::create(['NotificationID' => $n->id, 'UserID' => 1, 'ReadAt' => now()]);

        $this->ledger('reverse');
        $result = NotificationSyncService::sync([]);

        $this->assertTrue($this->isOpen($n));
        $this->assertSame(1, $result['source_reopened']);
        $this->assertSame(0, NotificationRead::where('NotificationID', $n->id)->count(), 'reopened item shows unread again');
    }

    public function test_lr_feedback_resolves_on_staff_reply_and_reopens_on_parent_follow_up(): void
    {
        $fb = $this->feedback();
        $n = $this->notice('lr_feedback', 'LearningRecordFeedback', (int) $fb->id);

        NotificationSyncService::sync([]);
        $this->assertTrue($this->isOpen($n), 'unanswered parent feedback stays open');

        $this->reply($fb, 'teacher', now()->subMinutes(10)->toDateTimeString());
        NotificationSyncService::sync([]);
        $this->assertFalse($this->isOpen($n));

        $this->reply($fb, 'parent', now()->subMinute()->toDateTimeString());
        NotificationSyncService::sync([]);
        $this->assertTrue($this->isOpen($n));
    }

    public function test_lr_feedback_resolves_when_dismissed_as_no_reply_needed(): void
    {
        $fb = $this->feedback();
        $n = $this->notice('lr_feedback', 'LearningRecordFeedback', (int) $fb->id);
        DB::table('learning_record_feedbacks')->where('id', $fb->id)->update(['awaiting_dismissed_at' => now()->addSecond()]);

        NotificationSyncService::sync([]);

        $this->assertFalse($this->isOpen($n));
    }

    public function test_only_scoped_campus_and_own_types_are_touched(): void
    {
        $other = $this->notice('deduction_failed', 'StudentSignIn', (int) $this->signIn(['SessionDeducted' => true])->id, 2);
        $manual = $this->notice('system_alert', 'StudentSignIn', (int) $this->signIn(['SessionDeducted' => true])->id);
        $substitute = $this->notice('substitute', 'ClassSession', 701);

        NotificationSyncService::sync([1]);

        $this->assertTrue($this->isOpen($other), 'campus 2 not in scope');
        $this->assertTrue($this->isOpen($manual), 'manual types stay manual');
        $this->assertTrue($this->isOpen($substitute), 'substitute ResolvedAt means undone, not handled');

        NotificationSyncService::sync([2]);
        $this->assertFalse($this->isOpen($other));
    }
}
