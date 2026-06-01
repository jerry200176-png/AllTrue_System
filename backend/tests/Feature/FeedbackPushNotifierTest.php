<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\FeedbackPushLog;
use App\Models\LearningRecordFeedback;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Services\FeedbackPushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FeedbackPushNotifier（學習回饋接推播）行為鎖定。
 * 重點：dark-launch flag、staff 站內鈴鐺、parent LINE、merge window、opt-out、跨校隔離、推播失敗不丟出。
 */
class FeedbackPushNotifierTest extends TestCase
{
    use RefreshDatabase;

    private int $lrSeq = 1;

    private function notifier(): FeedbackPushNotifier
    {
        return app(FeedbackPushNotifier::class);
    }

    /** messaging_channel_token 不在 Campus $fillable，factory 會丟棄 → 用 DB update 直接寫入。 */
    private function campusWithToken(string $token = 'TESTTOKEN'): Campus
    {
        $campus = Campus::factory()->create();
        DB::table('Campus')->where('id', $campus->id)->update(['messaging_channel_token' => $token]);
        return $campus->fresh();
    }

    private function makeFeedback(int $campusId, ?string $studentName = null): LearningRecordFeedback
    {
        $student = Student::create([
            'name' => $studentName ?? ('回饋' . uniqid()),
            'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        return LearningRecordFeedback::create([
            'learning_record_id' => $this->lrSeq++,
            'student_id' => (int) $student->id,
            'student_class_id' => 1,
            'class_session_id' => null,
            'teacher_id' => 1,
            'campus_id' => $campusId,
            'content' => '家長回饋內容',
        ]);
    }

    public function test_flag_off_is_complete_noop(): void
    {
        config(['perfflags.feedback_push_enabled' => false]);
        Http::fake();
        $campus = Campus::factory()->create();
        $fb = $this->makeFeedback((int) $campus->id);

        $this->notifier()->notifyParentSubmitted($fb);
        $this->notifier()->notifyStaffReplied($fb);

        $this->assertSame(0, Notification::count(), 'flag off 不可建立站內通知');
        $this->assertSame(0, FeedbackPushLog::count(), 'flag off 不可寫 push log');
        Http::assertNothingSent();
    }

    public function test_parent_event_creates_staff_inapp_notification(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        $campus = Campus::factory()->create();
        $fb = $this->makeFeedback((int) $campus->id, '小明');

        $this->notifier()->notifyParentSubmitted($fb);

        $notif = Notification::where('Type', 'lr_feedback')->first();
        $this->assertNotNull($notif, '應建立 lr_feedback 站內通知');
        $this->assertSame((int) $campus->id, (int) $notif->CampusID);
        $this->assertStringContainsString('小明', (string) $notif->Body);
        $this->assertSame(
            "lrfb:{$campus->id}:{$fb->learning_record_id}:to_staff",
            $notif->SourceKey
        );
        $this->assertDatabaseHas('feedback_push_log', [
            'feedback_id' => $fb->id, 'direction' => 'to_staff',
        ]);
    }

    public function test_staff_reply_pushes_line_to_bound_parent(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        Http::fake(['api.line.me/*' => Http::response([], 200)]);
        $campus = $this->campusWithToken();
        $fb = $this->makeFeedback((int) $campus->id);
        StudentLineBinding::create([
            'student_id' => $fb->student_id, 'line_user_id' => 'U-bound', 'campus_id' => (int) $campus->id,
            'bound_at' => now(), 'notify_learning_feedback' => 1,
        ]);

        $this->notifier()->notifyStaffReplied($fb);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me')
            && ($req->data()['to'] ?? null) === 'U-bound');
        $this->assertDatabaseHas('feedback_push_log', [
            'feedback_id' => $fb->id, 'direction' => 'to_parent',
        ]);
    }

    public function test_optout_parent_is_not_pushed(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        Http::fake(['api.line.me/*' => Http::response([], 200)]);
        $campus = $this->campusWithToken();
        $fb = $this->makeFeedback((int) $campus->id);
        StudentLineBinding::create([
            'student_id' => $fb->student_id, 'line_user_id' => 'U-optout', 'campus_id' => (int) $campus->id,
            'bound_at' => now(), 'notify_learning_feedback' => 0,
        ]);

        $this->notifier()->notifyStaffReplied($fb);

        Http::assertNothingSent();
        $this->assertSame(0, FeedbackPushLog::where('direction', 'to_parent')->count());
    }

    public function test_cross_campus_binding_is_not_pushed(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        Http::fake(['api.line.me/*' => Http::response([], 200)]);
        $campusA = $this->campusWithToken('TOKEN-A');
        $campusB = $this->campusWithToken('TOKEN-B');
        $fb = $this->makeFeedback((int) $campusA->id);
        // binding 掛在另一校 → 不可被 A 校事件推播
        StudentLineBinding::create([
            'student_id' => $fb->student_id, 'line_user_id' => 'U-other-campus', 'campus_id' => (int) $campusB->id,
            'bound_at' => now(), 'notify_learning_feedback' => 1,
        ]);

        $this->notifier()->notifyStaffReplied($fb);

        Http::assertNothingSent();
    }

    public function test_merge_window_suppresses_second_push(): void
    {
        config(['perfflags.feedback_push_enabled' => true, 'perfflags.feedback_push_merge_window_seconds' => 600]);
        Http::fake(['api.line.me/*' => Http::response([], 200)]);
        $campus = $this->campusWithToken();
        $fb = $this->makeFeedback((int) $campus->id);
        StudentLineBinding::create([
            'student_id' => $fb->student_id, 'line_user_id' => 'U-merge', 'campus_id' => (int) $campus->id,
            'bound_at' => now(), 'notify_learning_feedback' => 1,
        ]);

        $this->notifier()->notifyStaffReplied($fb); // 第一次推
        $this->notifier()->notifyStaffReplied($fb); // 10 分鐘內第二次 → 合併不推

        Http::assertSentCount(1);
    }

    public function test_push_failure_does_not_throw_and_does_not_record(): void
    {
        config(['perfflags.feedback_push_enabled' => true]);
        Http::fake(['api.line.me/*' => Http::response('err', 500)]);
        $campus = $this->campusWithToken();
        $fb = $this->makeFeedback((int) $campus->id);
        StudentLineBinding::create([
            'student_id' => $fb->student_id, 'line_user_id' => 'U-fail', 'campus_id' => (int) $campus->id,
            'bound_at' => now(), 'notify_learning_feedback' => 1,
        ]);

        $this->notifier()->notifyStaffReplied($fb); // 不可丟出例外

        $this->assertSame(0, FeedbackPushLog::where('direction', 'to_parent')->count(), '推播失敗不記 push log（下次仍可重試）');
    }
}
