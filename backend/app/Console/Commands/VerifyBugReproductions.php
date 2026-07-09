<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bug-termination gate (#1080).
 *
 * The in-app bug "infinite loop" recurs because the same divergence generators stay live
 * while individual user-cases are patched. This command turns every recurring bug into a
 * machine-checkable **reproduction query** and runs them against production:
 *
 *   - `enforced` conditions are bugs already verified FIXED (count must stay 0). If one
 *     regresses (count > 0) the command exits non-zero — the auto-reopen / alert signal,
 *     so "PR merged" can never again be mistaken for "verified absent in production".
 *   - non-enforced conditions are KNOWN-OPEN divergences (owner-gated fixes #1062/#957/#964);
 *     reported with their tracking issue so "production state vs bug state" is measurable nightly.
 *
 * Read-only: only SELECT COUNT(*). Scheduled nightly (Kernel) — feeds #904 recurrence report.
 */
class VerifyBugReproductions extends Command
{
    protected $signature = 'bugs:verify-reproductions {--json : Emit JSON instead of a table}';

    protected $description = 'Run every recurring bug reproduction query against production; fail if a FIXED bug regresses (#1080).';

    /**
     * @return list<array{key:string,label:string,issue:string,enforced:bool,sql:string}>
     */
    private function conditions(): array
    {
        return [
            [
                'key' => 'attended_session_without_learning_record',
                'label' => 'Attended sessions with no LearningRecord (teacher cannot submit 評量)',
                'issue' => '#1078/#188', 'enforced' => true,
                'sql' => "SELECT COUNT(*) AS c FROM ClassSession cs
                          JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                          WHERE LOWER(cs.Status) IN ('attended','late','absent')
                            AND CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= NOW()
                            AND NOT EXISTS (SELECT 1 FROM LearningRecord lr
                                            WHERE lr.ClassSessionID = cs.id AND lr.VoidedAt IS NULL)",
            ],
            [
                'key' => 'leave_session_with_live_learning_record',
                'label' => 'Leave sessions carrying a live LearningRecord (wrongly in 待審核評量)',
                'issue' => '#170', 'enforced' => true,
                'sql' => "SELECT COUNT(*) AS c FROM ClassSession cs
                          WHERE LOWER(cs.Status) IN ('leave','leave_adjusted')
                            AND EXISTS (SELECT 1 FROM LearningRecord lr
                                        WHERE lr.ClassSessionID = cs.id AND lr.VoidedAt IS NULL
                                          AND LOWER(lr.Status) <> 'approved')",
            ],
            [
                'key' => 'stranded_prepaid_course',
                'label' => 'Active count-mode course, remaining>0, no upcoming session (stranded)',
                'issue' => '#1062', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM StudentClass sc
                          WHERE (sc.Stop = 0 OR sc.Stop IS NULL) AND sc.ScheduleMode = 'count'
                            AND sc.RemainingSessions > 0
                            AND NOT EXISTS (SELECT 1 FROM ClassSession cs
                                            WHERE cs.StudentClassID = sc.ID AND cs.SessionDate >= CURDATE()
                                              AND LOWER(cs.Status) NOT IN ('cancelled','voided'))",
            ],
            [
                'key' => 'duplicate_session_same_slot',
                'label' => 'Duplicate non-cancelled ClassSession for same (course,date,start)',
                'issue' => '#957/#173', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM (
                            SELECT 1 FROM ClassSession cs WHERE LOWER(cs.Status) NOT IN ('cancelled','voided')
                            GROUP BY cs.StudentClassID, cs.SessionDate, SUBSTRING(cs.StartTime, 1, 5)
                            HAVING COUNT(*) > 1) d",
            ],
            [
                'key' => 'remaining_sessions_diverges_from_derived',
                'label' => 'Stored RemainingSessions <> SessionCount - UsedSessions (count truth)',
                'issue' => '#964/#172', 'enforced' => false,
                'sql' => "SELECT COUNT(*) AS c FROM StudentClass sc
                          WHERE sc.ScheduleMode = 'count'
                            AND sc.SessionCount IS NOT NULL AND sc.RemainingSessions IS NOT NULL
                            AND sc.UsedSessions IS NOT NULL
                            AND sc.RemainingSessions <> (sc.SessionCount - sc.UsedSessions)",
            ],
        ];
    }

    public function handle(): int
    {
        $results = [];
        $regressed = 0;

        foreach ($this->conditions() as $cond) {
            $count = (int) (DB::selectOne($cond['sql'])->c ?? 0);
            $isRegression = $cond['enforced'] && $count > 0;
            if ($isRegression) {
                $regressed++;
            }
            $results[] = [
                'key' => $cond['key'],
                'issue' => $cond['issue'],
                'enforced' => $cond['enforced'],
                'count' => $count,
                'state' => $cond['enforced']
                    ? ($count === 0 ? 'FIXED-OK' : 'REGRESSED')
                    : ($count === 0 ? 'OPEN-CLEARED' : 'OPEN'),
                'label' => $cond['label'],
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'as_of' => now()->toIso8601String(),
                'regressed' => $regressed,
                'conditions' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['condition', 'issue', 'enforced', 'count', 'state'],
                array_map(fn ($r) => [$r['key'], $r['issue'], $r['enforced'] ? 'yes' : 'no', $r['count'], $r['state']], $results)
            );
            if ($regressed > 0) {
                $this->error(sprintf('%d FIXED bug(s) regressed in production — investigate + reopen.', $regressed));
            } else {
                $this->info('No FIXED bug has regressed. Open divergences are tracked by their issue.');
            }
        }

        // Non-zero exit when a verified-fixed bug reappears: the auto-reopen / alert signal.
        return $regressed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
