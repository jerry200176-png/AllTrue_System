<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Merges a duplicate teacher User (and matching Teacher row) into the canonical account.
 * TeacherID / teacher_id across the app reference User.id (G-001).
 */
class TeacherUserMergeService
{
    public function resolveUserIdByLogin(string $loginName): ?int
    {
        $row = DB::table('User')->where('LoginName', $loginName)->first(['id']);

        return $row ? (int) $row->id : null;
    }

    public function assertMergeable(int $keepId, int $mergeId): void
    {
        if ($keepId === $mergeId) {
            throw new InvalidArgumentException('keep and merge user id must differ.');
        }

        $keep = DB::table('User')->where('id', $keepId)->first(['id', 'type', 'LoginName']);
        $merge = DB::table('User')->where('id', $mergeId)->first(['id', 'type', 'LoginName']);
        if (!$keep || !$merge) {
            throw new InvalidArgumentException('User row missing for keep or merge id.');
        }
        if ($keep->type !== 'T' || $merge->type !== 'T') {
            throw new InvalidArgumentException('Both users must be teachers (type=T).');
        }
    }

    /**
     * @return array<string, int>
     */
    public function preview(int $keepId, int $mergeId): array
    {
        $this->assertMergeable($keepId, $mergeId);

        return [
            'StudentClass' => $this->count('StudentClass', 'TeacherID', $mergeId),
            'schedules' => $this->count('schedules', 'teacher_id', $mergeId),
            'LearningRecord' => $this->count('LearningRecord', 'TeacherID', $mergeId),
            'LearningRecord_CreatedBy' => $this->count('LearningRecord', 'CreatedByUserID', $mergeId),
            'StudentSingIn' => $this->count('StudentSingIn', 'TeacherID', $mergeId),
            'StudentSingIn_RecordedBy' => $this->count('StudentSingIn', 'RecordedByUserID', $mergeId),
            'TeacherSingIn' => $this->count('TeacherSingIn', 'TeacherID', $mergeId),
            'UserCampus' => $this->count('UserCampus', 'UserID', $mergeId),
            'auth_tokens' => $this->count('auth_tokens', 'user_id', $mergeId),
            'NotificationReads' => $this->count('NotificationReads', 'UserID', $mergeId),
        ];
    }

    public function merge(int $keepId, int $mergeId): void
    {
        $this->assertMergeable($keepId, $mergeId);

        DB::transaction(function () use ($keepId, $mergeId) {
            $this->dedupeOverlappingFk($keepId, $mergeId, 'teacher_subjects', 'teacher_id', 'subject_id');
            $this->dedupeTeacherSubjectLevels($keepId, $mergeId);
            $this->dedupeOverlappingFk($keepId, $mergeId, 'teacher_branches', 'teacher_id', 'branch_id');
            $this->dedupeOverlappingFk($keepId, $mergeId, 'UserCampus', 'UserID', 'CampusID');
            $this->dedupePayrollTeacherBranchRules($keepId, $mergeId);
            $this->mergeTeacherMonthlyStats($keepId, $mergeId);
            $this->dedupeScopedUserRows($keepId, $mergeId, 'NotificationReads', 'UserID', 'NotificationID');
            $this->dedupeScopedUserRows($keepId, $mergeId, 'bug_report_user_reads', 'user_id', 'bug_report_id');
            $this->dedupeUserNotificationPreferences($keepId, $mergeId);
            $this->dedupeChatThreadMembers($keepId, $mergeId);

            $this->retargetPairs($keepId, $mergeId, [
                ['StudentClass', 'TeacherID'],
                ['schedules', 'teacher_id'],
                ['LearningRecord', 'TeacherID'],
                ['LearningRecord', 'CreatedByUserID'],
                ['StudentSingIn', 'TeacherID'],
                ['StudentSingIn', 'RecordedByUserID'],
                ['TeacherSingIn', 'TeacherID'],
            ]);

            $this->retargetTableColumnsIfExists('learning_record_teacher_changes', $keepId, $mergeId, [
                'new_teacher_id', 'old_teacher_id', 'changed_by',
            ]);
            $this->retargetTableColumnsIfExists('learning_record_feedbacks', $keepId, $mergeId, ['teacher_id']);
            $this->retargetTableColumnsIfExists('learning_record_teacher_comments', $keepId, $mergeId, [
                'teacher_id', 'author_user_id',
            ]);
            $this->retargetTableColumnsIfExists('exception_workflow_candidates', $keepId, $mergeId, ['teacher_id']);

            $this->retargetBugReportUserRefs($keepId, $mergeId);
            $this->bulkRetarget('chat_threads', 'created_by', $keepId, $mergeId);
            $this->bulkRetarget('chat_messages', 'sender_user_id', $keepId, $mergeId);

            if ($this->hasColumn('User', 'PasswordSetByUserID')) {
                $this->bulkRetarget('User', 'PasswordSetByUserID', $keepId, $mergeId);
            }
            $this->bulkRetarget('payroll_audit_log', 'user_id', $keepId, $mergeId);

            $this->mergeTeacherRows($keepId, $mergeId);
            $this->finalizeMergedUser($keepId, $mergeId);

            if ($this->hasTable('auth_tokens')) {
                DB::table('auth_tokens')->where('user_id', $mergeId)->delete();
            }

            if ($this->hasTable('Teacher') && DB::table('Teacher')->where('id', $mergeId)->exists()) {
                throw new RuntimeException('Teacher row for merge id still exists after merge.');
            }
        });
    }

    /**
     * @param array<int, array{0: string, 1: string}> $pairs
     */
    private function retargetPairs(int $keepId, int $mergeId, array $pairs): void
    {
        foreach ($pairs as [$table, $column]) {
            $this->bulkRetarget($table, $column, $keepId, $mergeId);
        }
    }

    /**
     * @param string[] $columns
     */
    private function retargetTableColumnsIfExists(string $table, int $keepId, int $mergeId, array $columns): void
    {
        if (!$this->hasTable($table)) {
            return;
        }
        foreach ($columns as $col) {
            $this->bulkRetarget($table, $col, $keepId, $mergeId);
        }
    }

    /**
     * Delete merge rows that duplicate keep rows on $scopeCol, then point remaining merge FKs at keep.
     */
    private function dedupeScopedUserRows(int $keepId, int $mergeId, string $table, string $userCol, string $scopeCol): void
    {
        if (!$this->hasTable($table)) {
            return;
        }

        $dupIds = DB::table($table . ' as m')
            ->join($table . ' as k', function ($join) use ($keepId, $mergeId, $userCol, $scopeCol) {
                $join->on('m.' . $scopeCol, '=', 'k.' . $scopeCol)
                    ->where('m.' . $userCol, $mergeId)
                    ->where('k.' . $userCol, $keepId);
            })
            ->pluck('m.id');

        if ($dupIds->isNotEmpty()) {
            DB::table($table)->whereIn('id', $dupIds->all())->delete();
        }

        DB::table($table)->where($userCol, $mergeId)->update([$userCol => $keepId]);
    }

    /**
     * When merge and keep each have a row with the same $overlapCol, drop merge's row; then retarget FK.
     */
    private function dedupeOverlappingFk(int $keepId, int $mergeId, string $table, string $fkCol, string $overlapCol): void
    {
        if (!$this->hasTable($table)) {
            return;
        }

        $dupVals = DB::table($table . ' as m')
            ->join($table . ' as k', function ($join) use ($keepId, $mergeId, $fkCol, $overlapCol) {
                $join->on('m.' . $overlapCol, '=', 'k.' . $overlapCol)
                    ->where('m.' . $fkCol, $mergeId)
                    ->where('k.' . $fkCol, $keepId);
            })
            ->pluck('m.' . $overlapCol);

        if ($dupVals->isNotEmpty()) {
            DB::table($table)->where($fkCol, $mergeId)->whereIn($overlapCol, $dupVals->all())->delete();
        }

        DB::table($table)->where($fkCol, $mergeId)->update([$fkCol => $keepId]);
    }

    private function dedupeTeacherSubjectLevels(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('teacher_subject_levels')) {
            return;
        }

        $dupKeys = DB::table('teacher_subject_levels as m')
            ->join('teacher_subject_levels as k', function ($join) use ($keepId, $mergeId) {
                $join->on('m.subject_id', '=', 'k.subject_id')
                    ->on('m.level', '=', 'k.level')
                    ->where('m.teacher_id', $mergeId)
                    ->where('k.teacher_id', $keepId);
            })
            ->get(['m.subject_id', 'm.level']);

        foreach ($dupKeys as $row) {
            DB::table('teacher_subject_levels')
                ->where('teacher_id', $mergeId)
                ->where('subject_id', $row->subject_id)
                ->where('level', $row->level)
                ->delete();
        }

        DB::table('teacher_subject_levels')->where('teacher_id', $mergeId)->update(['teacher_id' => $keepId]);
    }

    private function dedupePayrollTeacherBranchRules(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('payroll_teacher_branch_rules')) {
            return;
        }

        foreach (
            DB::table('payroll_teacher_branch_rules')->where('teacher_user_id', $mergeId)->get(['id', 'branch_id']) as $row
        ) {
            $dup = DB::table('payroll_teacher_branch_rules')
                ->where('teacher_user_id', $keepId)
                ->where('branch_id', $row->branch_id)
                ->exists();
            if ($dup) {
                DB::table('payroll_teacher_branch_rules')->where('id', $row->id)->delete();
            } else {
                DB::table('payroll_teacher_branch_rules')->where('id', $row->id)->update(['teacher_user_id' => $keepId]);
            }
        }
    }

    private function mergeTeacherMonthlyStats(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('teacher_monthly_stats')) {
            return;
        }

        foreach (DB::table('teacher_monthly_stats')->where('TeacherID', $mergeId)->get() as $row) {
            $existing = DB::table('teacher_monthly_stats')
                ->where('TeacherID', $keepId)
                ->where('YearMonth', $row->YearMonth)
                ->first();

            if ($existing) {
                DB::table('teacher_monthly_stats')->where('id', $existing->id)->update([
                    'SessionCount' => (int) $existing->SessionCount + (int) $row->SessionCount,
                ]);
                DB::table('teacher_monthly_stats')->where('id', $row->id)->delete();
            } else {
                DB::table('teacher_monthly_stats')->where('id', $row->id)->update(['TeacherID' => $keepId]);
            }
        }
    }

    private function dedupeUserNotificationPreferences(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('user_notification_preferences')) {
            return;
        }

        if (
            DB::table('user_notification_preferences')->where('user_id', $keepId)->exists()
            && DB::table('user_notification_preferences')->where('user_id', $mergeId)->exists()
        ) {
            DB::table('user_notification_preferences')->where('user_id', $mergeId)->delete();
        } else {
            DB::table('user_notification_preferences')->where('user_id', $mergeId)->update(['user_id' => $keepId]);
        }
    }

    private function dedupeChatThreadMembers(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('chat_thread_members')) {
            return;
        }

        foreach (DB::table('chat_thread_members')->where('user_id', $mergeId)->get(['id', 'thread_id']) as $mem) {
            $dup = DB::table('chat_thread_members')
                ->where('thread_id', $mem->thread_id)
                ->where('user_id', $keepId)
                ->exists();
            if ($dup) {
                DB::table('chat_thread_members')->where('id', $mem->id)->delete();
            } else {
                DB::table('chat_thread_members')->where('id', $mem->id)->update(['user_id' => $keepId]);
            }
        }
    }

    private function retargetBugReportUserRefs(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('bug_reports')) {
            return;
        }

        $this->bulkRetarget('bug_reports', 'reporter_user_id', $keepId, $mergeId);
        $this->bulkRetarget('bug_reports', 'assigned_to', $keepId, $mergeId);
        $this->bulkRetarget('bug_report_comments', 'author_user_id', $keepId, $mergeId);
        $this->bulkRetarget('bug_report_status_logs', 'changed_by', $keepId, $mergeId);
    }

    private function mergeTeacherRows(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('Teacher')) {
            return;
        }

        $keepT = DB::table('Teacher')->where('id', $keepId)->first();
        $mergeT = DB::table('Teacher')->where('id', $mergeId)->first();

        if (!$mergeT) {
            return;
        }

        if ($keepT) {
            $patch = [];
            foreach (['RFID', 'Phone', 'LineID', 'T_Name'] as $col) {
                if (!isset($mergeT->$col) || $mergeT->$col === null || $mergeT->$col === '') {
                    continue;
                }
                if (!isset($keepT->$col) || $keepT->$col === null || $keepT->$col === '') {
                    $patch[$col] = $mergeT->$col;
                }
            }
            if (isset($patch['RFID']) && $this->hasColumn('Teacher', 'RFID')) {
                DB::table('Teacher')->where('id', $mergeId)->update(['RFID' => null]);
            }
            if ($patch !== []) {
                DB::table('Teacher')->where('id', $keepId)->update($patch);
            }
        } else {
            DB::table('Teacher')->where('id', $mergeId)->update(['id' => $keepId]);

            return;
        }

        DB::table('Teacher')->where('id', $mergeId)->delete();
    }

    private function finalizeMergedUser(int $keepId, int $mergeId): void
    {
        $mergedLogin = Str::limit('merged_into_' . $keepId . '_' . Str::lower(Str::random(6)), 64, '');
        DB::table('User')->where('id', $mergeId)->update([
            'LoginName' => $mergedLogin,
            'status' => 'inactive',
        ]);
    }

    /**
     * @param non-empty-string $table
     * @param non-empty-string $column
     */
    private function bulkRetarget(string $table, string $column, int $keepId, int $mergeId): void
    {
        if (!$this->hasTable($table) || !$this->hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->where($column, $mergeId)->update([$column => $keepId]);
    }

    private function count(string $table, string $column, int $mergeId): int
    {
        if (!$this->hasTable($table) || !$this->hasColumn($table, $column)) {
            return 0;
        }

        return (int) DB::table($table)->where($column, $mergeId)->count();
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && Schema::hasColumn($table, $column);
    }
}
