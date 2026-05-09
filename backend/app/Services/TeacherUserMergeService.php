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
            $this->dedupeTeacherSubjects($keepId, $mergeId);
            $this->dedupeTeacherSubjectLevels($keepId, $mergeId);
            $this->dedupeTeacherBranches($keepId, $mergeId);
            $this->dedupeUserCampus($keepId, $mergeId);
            $this->dedupePayrollTeacherBranchRules($keepId, $mergeId);
            $this->mergeTeacherMonthlyStats($keepId, $mergeId);
            $this->dedupeNotificationReads($keepId, $mergeId);
            $this->dedupeBugReportUserReads($keepId, $mergeId);
            $this->dedupeUserNotificationPreferences($keepId, $mergeId);
            $this->dedupeChatThreadUsers($keepId, $mergeId);

            $this->bulkRetarget('StudentClass', 'TeacherID', $keepId, $mergeId);
            $this->bulkRetarget('schedules', 'teacher_id', $keepId, $mergeId);
            $this->bulkRetarget('LearningRecord', 'TeacherID', $keepId, $mergeId);
            $this->bulkRetarget('LearningRecord', 'CreatedByUserID', $keepId, $mergeId);
            $this->bulkRetarget('StudentSingIn', 'TeacherID', $keepId, $mergeId);
            $this->bulkRetarget('StudentSingIn', 'RecordedByUserID', $keepId, $mergeId);
            $this->bulkRetarget('TeacherSingIn', 'TeacherID', $keepId, $mergeId);

            if ($this->hasTable('learning_record_teacher_changes')) {
                $this->bulkRetarget('learning_record_teacher_changes', 'new_teacher_id', $keepId, $mergeId);
                $this->bulkRetarget('learning_record_teacher_changes', 'old_teacher_id', $keepId, $mergeId);
                $this->bulkRetarget('learning_record_teacher_changes', 'changed_by', $keepId, $mergeId);
            }
            if ($this->hasTable('learning_record_feedbacks')) {
                $this->bulkRetarget('learning_record_feedbacks', 'teacher_id', $keepId, $mergeId);
            }
            if ($this->hasTable('learning_record_teacher_comments')) {
                $this->bulkRetarget('learning_record_teacher_comments', 'teacher_id', $keepId, $mergeId);
                $this->bulkRetarget('learning_record_teacher_comments', 'author_user_id', $keepId, $mergeId);
            }
            if ($this->hasTable('exception_workflow_candidates')) {
                $this->bulkRetarget('exception_workflow_candidates', 'teacher_id', $keepId, $mergeId);
            }

            $this->retargetBugReportUserRefs($keepId, $mergeId);
            if ($this->hasTable('chat_threads')) {
                $this->bulkRetarget('chat_threads', 'created_by', $keepId, $mergeId);
            }
            $this->bulkRetarget('chat_messages', 'sender_user_id', $keepId, $mergeId);

            // Other User.id references
            if ($this->hasColumn('User', 'PasswordSetByUserID')) {
                $this->bulkRetarget('User', 'PasswordSetByUserID', $keepId, $mergeId);
            }
            if ($this->hasTable('payroll_audit_log')) {
                $this->bulkRetarget('payroll_audit_log', 'user_id', $keepId, $mergeId);
            }

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

    private function mergeTeacherRows(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('Teacher')) {
            return;
        }

        $keepT = DB::table('Teacher')->where('id', $keepId)->first();
        $mergeT = DB::table('Teacher')->where('id', $mergeId)->first();

        if ($mergeT) {
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
                    // Clear merge row first so a unique RFID is not held by two rows.
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
    }

    private function finalizeMergedUser(int $keepId, int $mergeId): void
    {
        $suffix = '_into_' . $keepId . '_' . Str::lower(Str::random(6));
        $mergedLogin = 'merged' . $suffix;
        if (strlen($mergedLogin) > 64) {
            $mergedLogin = Str::limit($mergedLogin, 64, '');
        }

        DB::table('User')->where('id', $mergeId)->update([
            'LoginName' => $mergedLogin,
            'status' => 'inactive',
        ]);
    }

    private function dedupeTeacherSubjects(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('teacher_subjects')) {
            return;
        }

        $dupSubjects = DB::table('teacher_subjects as m')
            ->join('teacher_subjects as k', function ($join) use ($keepId, $mergeId) {
                $join->on('m.subject_id', '=', 'k.subject_id')
                    ->where('m.teacher_id', $mergeId)
                    ->where('k.teacher_id', $keepId);
            })
            ->pluck('m.subject_id');

        if ($dupSubjects->isNotEmpty()) {
            DB::table('teacher_subjects')
                ->where('teacher_id', $mergeId)
                ->whereIn('subject_id', $dupSubjects->all())
                ->delete();
        }

        DB::table('teacher_subjects')
            ->where('teacher_id', $mergeId)
            ->update(['teacher_id' => $keepId]);
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

        DB::table('teacher_subject_levels')
            ->where('teacher_id', $mergeId)
            ->update(['teacher_id' => $keepId]);
    }

    private function dedupeTeacherBranches(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('teacher_branches')) {
            return;
        }

        $dupBranches = DB::table('teacher_branches as m')
            ->join('teacher_branches as k', function ($join) use ($keepId, $mergeId) {
                $join->on('m.branch_id', '=', 'k.branch_id')
                    ->where('m.teacher_id', $mergeId)
                    ->where('k.teacher_id', $keepId);
            })
            ->pluck('m.branch_id');

        if ($dupBranches->isNotEmpty()) {
            DB::table('teacher_branches')
                ->where('teacher_id', $mergeId)
                ->whereIn('branch_id', $dupBranches->all())
                ->delete();
        }

        DB::table('teacher_branches')
            ->where('teacher_id', $mergeId)
            ->update(['teacher_id' => $keepId]);
    }

    private function dedupeUserCampus(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('UserCampus')) {
            return;
        }

        $dupCampuses = DB::table('UserCampus as m')
            ->join('UserCampus as k', function ($join) use ($keepId, $mergeId) {
                $join->on('m.CampusID', '=', 'k.CampusID')
                    ->where('m.UserID', $mergeId)
                    ->where('k.UserID', $keepId);
            })
            ->pluck('m.CampusID');

        if ($dupCampuses->isNotEmpty()) {
            DB::table('UserCampus')
                ->where('UserID', $mergeId)
                ->whereIn('CampusID', $dupCampuses->all())
                ->delete();
        }

        DB::table('UserCampus')
            ->where('UserID', $mergeId)
            ->update(['UserID' => $keepId]);
    }

    private function dedupePayrollTeacherBranchRules(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('payroll_teacher_branch_rules')) {
            return;
        }

        $mergeRows = DB::table('payroll_teacher_branch_rules')
            ->where('teacher_user_id', $mergeId)
            ->get(['id', 'branch_id']);

        foreach ($mergeRows as $row) {
            $dup = DB::table('payroll_teacher_branch_rules')
                ->where('teacher_user_id', $keepId)
                ->where('branch_id', $row->branch_id)
                ->exists();
            if ($dup) {
                DB::table('payroll_teacher_branch_rules')->where('id', $row->id)->delete();
            } else {
                DB::table('payroll_teacher_branch_rules')
                    ->where('id', $row->id)
                    ->update(['teacher_user_id' => $keepId]);
            }
        }
    }

    private function mergeTeacherMonthlyStats(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('teacher_monthly_stats')) {
            return;
        }

        $rows = DB::table('teacher_monthly_stats')->where('TeacherID', $mergeId)->get();
        foreach ($rows as $row) {
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
                DB::table('teacher_monthly_stats')->where('id', $row->id)->update([
                    'TeacherID' => $keepId,
                ]);
            }
        }
    }

    private function dedupeNotificationReads(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('NotificationReads')) {
            return;
        }

        $dupIds = DB::table('NotificationReads as m')
            ->join('NotificationReads as k', function ($join) use ($keepId, $mergeId) {
                $join->on('m.NotificationID', '=', 'k.NotificationID')
                    ->where('m.UserID', $mergeId)
                    ->where('k.UserID', $keepId);
            })
            ->pluck('m.id');

        if ($dupIds->isNotEmpty()) {
            DB::table('NotificationReads')->whereIn('id', $dupIds->all())->delete();
        }

        DB::table('NotificationReads')->where('UserID', $mergeId)->update(['UserID' => $keepId]);
    }

    private function dedupeBugReportUserReads(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('bug_report_user_reads')) {
            return;
        }

        $dupIds = DB::table('bug_report_user_reads as m')
            ->join('bug_report_user_reads as k', function ($join) use ($keepId, $mergeId) {
                $join->on('m.bug_report_id', '=', 'k.bug_report_id')
                    ->where('m.user_id', $mergeId)
                    ->where('k.user_id', $keepId);
            })
            ->pluck('m.id');

        if ($dupIds->isNotEmpty()) {
            DB::table('bug_report_user_reads')->whereIn('id', $dupIds->all())->delete();
        }

        DB::table('bug_report_user_reads')->where('user_id', $mergeId)->update(['user_id' => $keepId]);
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
            DB::table('user_notification_preferences')
                ->where('user_id', $mergeId)
                ->update(['user_id' => $keepId]);
        }
    }

    private function dedupeChatThreadUsers(int $keepId, int $mergeId): void
    {
        if (!$this->hasTable('chat_thread_members')) {
            return;
        }

        $mergeMemberships = DB::table('chat_thread_members')->where('user_id', $mergeId)->get(['id', 'thread_id']);

        foreach ($mergeMemberships as $mem) {
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
        if ($this->hasTable('bug_report_comments')) {
            $this->bulkRetarget('bug_report_comments', 'author_user_id', $keepId, $mergeId);
        }
        if ($this->hasTable('bug_report_status_logs')) {
            $this->bulkRetarget('bug_report_status_logs', 'changed_by', $keepId, $mergeId);
        }
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
        if (!$this->hasTable($table)) {
            return false;
        }

        return Schema::hasColumn($table, $column);
    }
}
