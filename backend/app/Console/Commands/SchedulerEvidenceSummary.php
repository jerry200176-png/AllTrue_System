<?php

namespace App\Console\Commands;

use App\Models\PendingSwipe;
use App\Models\StudentSignIn;
use App\Models\TeacherSignIn;
use App\Support\SchedulerEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emits only aggregate scheduler evidence. It is intentionally safe to run
 * from Pi Health: no writes and no student, teacher, RFID, or course IDs.
 */
class SchedulerEvidenceSummary extends Command
{
    protected $signature = 'scheduler:evidence-summary {--date= : Local Asia/Taipei date (YYYY-MM-DD)}';

    protected $description = 'Summarize PII-free per-job scheduler execution evidence for production health checks.';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: CarbonImmutable::now(SchedulerEvidence::TIMEZONE)->toDateString());
        try {
            $summary = SchedulerEvidence::summarize($date);
        } catch (\Throwable $exception) {
            $this->error(json_encode([
                'date' => $date,
                'timezone' => SchedulerEvidence::TIMEZONE,
                'healthy' => false,
                'error' => 'scheduler_evidence_unreadable',
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $summary['database_checks'] = $this->databaseChecks();
        $summary['healthy'] = $summary['healthy'] && array_sum($summary['database_checks']) === 0;

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $summary['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,int> */
    private function databaseChecks(): array
    {
        $today = CarbonImmutable::now(SchedulerEvidence::TIMEZONE)->startOfDay();

        return [
            'student_orphans_remaining' => StudentSignIn::query()
                ->whereNull('SignOutDT')
                ->whereNull('VoidedAt')
                ->where('SignInDT', '<', $today->toDateTimeString())
                ->count(),
            'teacher_orphans_remaining' => TeacherSignIn::query()
                ->whereNull('SignOutDT')
                ->where('SignInDT', '<', $today->toDateTimeString())
                ->count(),
            'expired_pending_swipes_remaining' => PendingSwipe::query()
                ->where('created_at', '<', $today->subDays(30)->toDateTimeString())
                ->count(),
            'past_attended_sessions_without_learning_record' => (int) (DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS c
                FROM ClassSession cs
                JOIN StudentClass sc ON sc.ID = cs.StudentClassID
                WHERE LOWER(cs.Status) IN ('attended','late','absent')
                  AND CONCAT(cs.SessionDate, ' ', COALESCE(cs.StartTime, '00:00:00')) <= NOW()
                  AND NOT EXISTS (
                    SELECT 1 FROM LearningRecord lr
                    WHERE lr.ClassSessionID = cs.id AND lr.VoidedAt IS NULL
                  )
                SQL
            )->c ?? 0),
        ];
    }
}
