<?php

namespace App\Console\Commands;

use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revenue-integrity guard (#1062).
 *
 * Prepaid sessions are a liability owed to the customer. They must never
 * silently disappear from the calendar. This command flags every ACTIVE course
 * that still holds unused sessions (`RemainingSessions > 0`) but has NO upcoming
 * materialized {@see \App\Models\ClassSession} (today onward, excluding
 * cancelled) — i.e. a paid class that is not scheduled anywhere ahead.
 *
 * Read-only: it never writes. Intended to run on the nightly schedule so the
 * 2026-06 incident (372 paid sessions stranded at 大直 after forward session
 * generation stopped) can never go unnoticed again.
 */
class AuditStrandedPaidSessions extends Command
{
    protected $signature = 'sessions:audit-stranded
                            {--branch_id= : Scope to one campus (Student.CampusID)}
                            {--json : Emit a JSON summary instead of a table}
                            {--limit=50 : Max rows to list}';

    protected $description = 'Flag active, prepaid courses with remaining sessions but no upcoming class session (revenue-integrity guard, #1062).';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $branchId = (int) ($this->option('branch_id') ?? 0);

        $query = StudentClass::query()
            ->where(function ($w) {
                $w->where('Stop', 0)->orWhereNull('Stop');
            })
            ->where('RemainingSessions', '>', 0)
            ->whereNotExists(function ($sub) use ($today) {
                $sub->select(DB::raw(1))
                    ->from('ClassSession')
                    ->whereColumn('ClassSession.StudentClassID', 'StudentClass.ID')
                    ->where('ClassSession.SessionDate', '>=', $today)
                    ->where('ClassSession.Status', '<>', 'cancelled');
            });

        if ($branchId > 0) {
            $query->whereExists(function ($sub) use ($branchId) {
                $sub->select(DB::raw(1))
                    ->from('Student')
                    ->whereColumn('Student.id', 'StudentClass.StudentID')
                    ->where('Student.CampusID', $branchId);
            });
        }

        $rows = $query->orderByDesc('RemainingSessions')
            ->get(['ID', 'StudentID', 'SubjectID', 'RemainingSessions', 'SessionCount', 'UsedSessions', 'EndDate', 'ScheduleMode']);

        $count = $rows->count();
        $strandedSessions = (int) $rows->sum('RemainingSessions');

        if ($this->option('json')) {
            $this->line(json_encode([
                'branch_id' => $branchId ?: null,
                'as_of' => $today,
                'stranded_courses' => $count,
                'stranded_sessions' => $strandedSessions,
            ], JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Stranded paid sessions%s as of %s: %d course(s), %d unused session(s) with no upcoming class.',
            $branchId ? " (campus {$branchId})" : '',
            $today,
            $count,
            $strandedSessions
        ));

        $limit = max(1, (int) $this->option('limit'));
        if ($count > 0) {
            $this->table(
                ['course_id', 'student_id', 'remaining', 'purchased', 'used', 'end_date', 'mode'],
                $rows->take($limit)->map(fn ($r) => [
                    $r->ID,
                    $r->StudentID,
                    $r->RemainingSessions,
                    $r->SessionCount,
                    $r->UsedSessions,
                    $r->EndDate ? Carbon::parse($r->EndDate)->toDateString() : '—',
                    $r->ScheduleMode,
                ])->all()
            );
            if ($count > $limit) {
                $this->line(sprintf('… and %d more (use --limit).', $count - $limit));
            }
        }

        return self::SUCCESS;
    }
}
