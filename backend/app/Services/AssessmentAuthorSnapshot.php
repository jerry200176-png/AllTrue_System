<?php

namespace App\Services;

use App\Models\LearningRecord;
use App\Models\LearningRecordAuthorSnapshot;
use App\Support\LearningRecordMutableOwnership;
use Illuminate\Support\Facades\DB;

/**
 * #3824 part 2: the one place that handles a teacher change on a filled-but-unapproved assessment.
 * Saves the author's body as an immutable snapshot, then clears the live row so the new teacher refills it.
 * Approved rows are never touched (they stay protected by LearningRecordMutableOwnership).
 * Call inside the caller's transaction, BEFORE the LR's TeacherID is rewritten.
 */
final class AssessmentAuthorSnapshot
{
    /** Body columns that move into the snapshot and are cleared (Content is NOT NULL, so '' ). */
    private const BODY = ['Content', 'AttachmentUrl', 'Progress', 'NextHomework', 'NextWeekTestScope', 'QuizScore', 'HomeworkStatus', 'Performance', 'Comment'];

    public static function sendBackForRefill(int $classSessionId, int $newTeacherId, ?int $changedBy = null, ?string $reason = null): bool
    {
        if ($classSessionId <= 0 || $newTeacherId <= 0) {
            return false;
        }

        return DB::transaction(function () use ($classSessionId, $newTeacherId, $changedBy, $reason): bool {
            $lr = LearningRecord::query()->where('ClassSessionID', $classSessionId)->whereNull('VoidedAt')->lockForUpdate()->first();
            if (!$lr || (int) $lr->TeacherID === $newTeacherId
                || strtolower(trim((string) $lr->Status)) === 'approved'
                || !LearningRecordMutableOwnership::hasSubstantiveTeacherContent($lr)) {
                return false;
            }

            $payload = [];
            foreach (self::BODY as $col) {
                $payload[$col] = $lr->getAttribute($col);
            }
            // ponytail: comment row is copied into the payload then removed (smallest safe: nothing lost, new teacher starts blank).
            $comment = DB::table('learning_record_teacher_comments')->where('learning_record_id', $lr->id)->lockForUpdate()->first();
            $payload['teacher_comment'] = $comment ? (array) $comment : null;

            (new LearningRecordAuthorSnapshot())->newQuery()->create([
                'learning_record_id' => $lr->id,
                'author_teacher_id' => (int) $lr->TeacherID ?: null,
                'new_teacher_id' => $newTeacherId,
                'status_at_snapshot' => (string) $lr->Status,
                'payload' => $payload,
                'created_by' => $changedBy ?: null,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            ]);

            $clear = array_fill_keys(self::BODY, null);
            $clear['Content'] = '';
            DB::table('learning_record_teacher_comments')->where('learning_record_id', $lr->id)->delete();
            DB::table('LearningRecord')->where('id', $lr->id)->update($clear + ['Status' => 'pending', 'ReviewNote' => null, 'updated_at' => now()]);

            return true;
        });
    }
}
