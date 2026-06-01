<?php

namespace App\Services;

use App\Models\FeedbackPushLog;
use App\Models\LearningRecordFeedback;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 學習回饋／回覆事件 → 推播。
 *
 * 設計重點：
 * - DARK LAUNCH：`perfflags.feedback_push_enabled` 預設 false → 全程 no-op，production 行為不變。
 * - 兩條既有 lane：to_staff = 站內 Notification 鈴鐺；to_parent = 家長 LINE（StudentLineBinding + Campus token）。
 * - 防騷擾：同 (feedback, direction) 在 merge window（預設 10 分鐘）內只推一次。
 * - 個資退出權：家長可於 binding 關閉 `notify_learning_feedback`。
 * - Best-effort：任何錯誤只記 log，絕不丟出 → 不阻斷回饋/回覆主流程。
 */
class FeedbackPushNotifier
{
    public function enabled(): bool
    {
        return (bool) config('perfflags.feedback_push_enabled', false);
    }

    private function mergeWindowSeconds(): int
    {
        return max(0, (int) config('perfflags.feedback_push_merge_window_seconds', 600));
    }

    /** 家長提交回饋 → 通知老師/主任（站內鈴鐺）。 */
    public function notifyParentSubmitted(LearningRecordFeedback $feedback): void
    {
        $this->safe(fn () => $this->dispatchToStaff($feedback, 'submitted'));
    }

    /** 家長追加回覆 → 通知老師/主任（站內鈴鐺）。 */
    public function notifyParentReplied(LearningRecordFeedback $feedback): void
    {
        $this->safe(fn () => $this->dispatchToStaff($feedback, 'replied'));
    }

    /** 老師/主任回覆 → 通知家長（LINE，需綁定且未退訂）。 */
    public function notifyStaffReplied(LearningRecordFeedback $feedback): void
    {
        $this->safe(fn () => $this->dispatchToParent($feedback));
    }

    private function dispatchToStaff(LearningRecordFeedback $feedback, string $event): void
    {
        if (!$this->enabled()) {
            return;
        }
        if (!$this->passMergeWindow($feedback, 'to_staff')) {
            return;
        }

        $student = Student::find($feedback->student_id);
        $studentName = $student->name ?? '學生';
        $title = $event === 'submitted' ? '家長送出學習回饋' : '家長追加了回覆';
        $body = "{$studentName} 的家長在學習回饋留言，請至學習評量查看並回覆。";

        // SourceKey 唯一 → 同一回饋串只會有一筆鈴鐺，重複事件更新同列（天然合併）。
        Notification::updateOrCreate(
            ['SourceKey' => "lrfb:{$feedback->campus_id}:{$feedback->learning_record_id}:to_staff"],
            [
                'CampusID' => (int) $feedback->campus_id,
                'Type' => 'lr_feedback',
                'Severity' => 'medium',
                'Title' => $title,
                'Body' => $body,
                'SourceType' => 'LearningRecordFeedback',
                'SourceID' => (string) $feedback->id,
                'Payload' => [
                    'learning_record_id' => (int) $feedback->learning_record_id,
                    'teacher_id' => (int) $feedback->teacher_id,
                ],
                'OccurredAt' => now(),
                'ResolvedAt' => null,
            ]
        );

        $this->recordPush($feedback, 'to_staff');
    }

    private function dispatchToParent(LearningRecordFeedback $feedback): void
    {
        if (!$this->enabled()) {
            return;
        }
        if (!$this->passMergeWindow($feedback, 'to_parent')) {
            return;
        }

        $campusId = (int) $feedback->campus_id;
        $bindings = StudentLineBinding::where('student_id', $feedback->student_id)
            ->where('campus_id', $campusId)
            ->get()
            ->filter(fn ($b) => (bool) ($b->notify_learning_feedback ?? true));
        if ($bindings->isEmpty()) {
            return;
        }

        $campus = DB::table('Campus')->where('id', $campusId)->first();
        if (!$campus || empty($campus->messaging_channel_token)) {
            return;
        }

        $student = Student::find($feedback->student_id);
        $studentName = $student->name ?? '學生';
        $msg = "親愛的家長您好，\n\n"
            . "老師回覆了 {$studentName} 的學習回饋，歡迎至家長系統查看。\n\n"
            . "如不想再收到此類通知，可於家長系統設定中關閉。";

        $sent = 0;
        foreach ($bindings as $binding) {
            if ($this->pushLine((string) $binding->line_user_id, $msg, (string) $campus->messaging_channel_token)) {
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->recordPush($feedback, 'to_parent');
        }
    }

    /** true = 可推送（不在合併窗內）。 */
    private function passMergeWindow(LearningRecordFeedback $feedback, string $direction): bool
    {
        $window = $this->mergeWindowSeconds();
        if ($window <= 0) {
            return true;
        }
        $log = FeedbackPushLog::where('feedback_id', $feedback->id)
            ->where('direction', $direction)
            ->first();
        if ($log && $log->last_pushed_at && $log->last_pushed_at->gt(now()->subSeconds($window))) {
            return false;
        }
        return true;
    }

    private function recordPush(LearningRecordFeedback $feedback, string $direction): void
    {
        FeedbackPushLog::updateOrCreate(
            ['feedback_id' => $feedback->id, 'direction' => $direction],
            [
                'campus_id' => (int) $feedback->campus_id,
                'learning_record_id' => (int) $feedback->learning_record_id,
                'last_pushed_at' => now(),
            ]
        );
    }

    private function pushLine(string $lineUserId, string $text, string $token): bool
    {
        if ($lineUserId === '' || $token === '') {
            return false;
        }
        try {
            $resp = Http::withToken($token)->post('https://api.line.me/v2/bot/message/push', [
                'to' => $lineUserId,
                'messages' => [['type' => 'text', 'text' => $text]],
            ]);
            return $resp->successful();
        } catch (\Throwable $e) {
            Log::warning('feedback_push_line_failed: ' . $e->getMessage());
            return false;
        }
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('feedback_push_failed: ' . $e->getMessage());
        }
    }
}
