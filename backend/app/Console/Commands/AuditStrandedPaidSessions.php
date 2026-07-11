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
                            {--csv : Emit the full prepaid dormant-liability decision worksheet (#1152) as CSV}
                            {--limit=50 : Max rows to list}';

    protected $description = 'Flag active, prepaid courses with remaining sessions but no upcoming class session (revenue-integrity guard, #1062).';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $branchId = (int) ($this->option('branch_id') ?? 0);

        if ($this->option('csv')) {
            return $this->emitWorksheetCsv($branchId, $today);
        }

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

    /**
     * #1152 prepaid dormant-liability decision worksheet (read-only CSV to stdout).
     *
     * Scoped to count-mode prepaid courses with a remaining balance but no upcoming
     * session, enriched for a director's per-course decision. Segments each row:
     *   - active      : a session in the last 21d, or freshly enrolled (StartDate ≤42d, never materialised)
     *   - dormant     : has a fixed weekday but no recent activity (auto-generation withheld — #1062 design)
     *   - no_schedule : no weekday template at all → needs a director schedule or dormant policy
     * Default liability policy is preserve-balances (no auto-refund/write-off).
     */
    private function emitWorksheetCsv(int $branchId, string $today): int
    {
        $data = $this->worksheetRows($branchId, $today);

        $this->line($this->csvRow(['course_id', 'student_id', 'student_name', 'campus_id', 'remaining', 'rate', 'amount_ntd', 'last_activity', 'days_since', 'weekday_set', 'segment']));
        foreach ($data['rows'] as $row) {
            $this->line($this->csvRow($row));
        }
        $this->line(sprintf(
            '# %d courses · NT$%s total · active=%d dormant=%d no_schedule=%d · policy=preserve-balances (no auto-refund/write-off)',
            count($data['rows']), number_format($data['total']), $data['seg']['active'], $data['seg']['dormant'], $data['seg']['no_schedule']
        ));

        return self::SUCCESS;
    }

    /**
     * Read-only computation of the #1152 worksheet. Returns structured rows + totals so the
     * segmentation logic is directly testable without capturing console output.
     *
     * @return array{rows: list<array<int,mixed>>, total:int, seg: array<string,int>}
     */
    public function worksheetRows(int $branchId, string $today): array
    {
        $todayC = Carbon::parse($today)->startOfDay();

        $rows = DB::table('StudentClass as sc')
            ->leftJoin('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where(fn ($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->where('sc.ScheduleMode', 'count')
            ->where('sc.RemainingSessions', '>', 0)
            ->whereNotExists(function ($q) use ($today) {
                $q->select(DB::raw(1))->from('ClassSession as cs')
                    ->whereColumn('cs.StudentClassID', 'sc.ID')
                    ->where('cs.SessionDate', '>=', $today)
                    ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')");
            })
            ->when($branchId > 0, fn ($q) => $q->where('st.CampusID', $branchId))
            ->orderByDesc(DB::raw('sc.RemainingSessions * COALESCE(sc.Rate, 0)'))
            ->get([
                'sc.ID', 'sc.StudentID', 'st.name as student_name', 'st.CampusID',
                'sc.RemainingSessions', 'sc.Rate', 'sc.week', 'sc.StartDate',
                DB::raw("(SELECT MAX(cs2.SessionDate) FROM ClassSession cs2 WHERE cs2.StudentClassID = sc.ID AND LOWER(cs2.Status) NOT IN ('cancelled','voided')) AS last_activity"),
            ]);

        $out = [];
        $total = 0;
        $seg = ['active' => 0, 'dormant' => 0, 'no_schedule' => 0];
        foreach ($rows as $r) {
            $amount = (int) $r->RemainingSessions * (int) ($r->Rate ?? 0);
            $total += $amount;
            $hasWeekday = ((int) ($r->week ?? 0) >= 1 && (int) ($r->week ?? 0) <= 7);
            $last = $r->last_activity ? Carbon::parse($r->last_activity)->startOfDay() : null;
            $daysSince = $last ? $last->diffInDays($todayC) : null;
            $startFresh = !$last && $r->StartDate && Carbon::parse($r->StartDate)->gte($todayC->copy()->subDays(42));

            if (($last && $daysSince <= 21) || $startFresh) {
                $segment = 'active';
            } elseif (!$hasWeekday) {
                $segment = 'no_schedule';
            } else {
                $segment = 'dormant';
            }
            $seg[$segment]++;

            $out[] = [
                (int) $r->ID, (int) $r->StudentID, $r->student_name, $r->CampusID !== null ? (int) $r->CampusID : null,
                (int) $r->RemainingSessions, (int) ($r->Rate ?? 0), $amount,
                $last ? $last->toDateString() : 'never',
                $daysSince ?? '',
                $hasWeekday ? 'yes' : 'no',
                $segment,
            ];
        }

        return ['rows' => $out, 'total' => $total, 'seg' => $seg];
    }

    /** RFC-4180-ish CSV row: quote a cell only when it contains a comma, quote, or newline. */
    private function csvRow(array $cells): string
    {
        return implode(',', array_map(function ($v) {
            $s = (string) $v;
            if (preg_match('/[",\r\n]/', $s)) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            return $s;
        }, $cells));
    }
}
