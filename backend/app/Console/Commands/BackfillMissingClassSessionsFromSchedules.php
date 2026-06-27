<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Services\ClassSessionMaterializationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillMissingClassSessionsFromSchedules extends Command
{
    protected $signature = 'schedules:backfill-class-sessions
                            {--branch_id= : Scope to one branch/campus}
                            {--start= : Inclusive schedule_date start (YYYY-MM-DD)}
                            {--end= : Inclusive schedule_date end (YYYY-MM-DD)}
                            {--dry-run : Preview only, do not write}';

    protected $description = 'Backfill missing ClassSession rows from schedules (idempotent).';

    public function handle(): int
    {
        if (!Schema::hasTable('schedules') || !Schema::hasTable('StudentClass') || !Schema::hasTable('ClassSession')) {
            $this->warn('Required tables missing (schedules/StudentClass/ClassSession), skipping.');
            return self::SUCCESS;
        }

        $branchId = (int) ($this->option('branch_id') ?? 0);
        $start = $this->normalizeDateOption($this->option('start'));
        $end = $this->normalizeDateOption($this->option('end'));
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('schedules as s')
            ->select([
                's.id',
                's.student_course_id',
                's.schedule_date',
                's.start_time',
                's.end_time',
                's.branch_id',
            ])
            ->whereNotNull('s.student_course_id')
            ->whereNotNull('s.schedule_date')
            ->whereIn('s.status', ['scheduled', 'rescheduled']);

        if ($branchId > 0) {
            $query->where('s.branch_id', $branchId);
        }
        if ($start) {
            $query->whereDate('s.schedule_date', '>=', $start);
        }
        if ($end) {
            $query->whereDate('s.schedule_date', '<=', $end);
        }

        $scoped = $query
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('StudentClass as sc')
                    ->whereColumn('sc.ID', 's.student_course_id');
            })
            ->orderBy('s.id');

        $checked = 0;
        $created = 0;

        $scoped->chunkById(500, function ($rows) use (&$checked, &$created, $dryRun) {
            foreach ($rows as $row) {
                $checked++;
                $courseId = (int) ($row->student_course_id ?? 0);
                if ($courseId <= 0) {
                    continue;
                }

                $sessionDate = substr((string) ($row->schedule_date ?? ''), 0, 10);
                if ($sessionDate === '') {
                    continue;
                }

                $startTime = $this->normalizeTime((string) ($row->start_time ?? '00:00:00'));
                $endTime = $this->normalizeTime((string) ($row->end_time ?? '00:00:00'));

                $exists = ClassSession::query()
                    ->where('StudentClassID', $courseId)
                    ->whereDate('SessionDate', $sessionDate)
                    ->where('StartTime', $startTime)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $created++;
                if ($dryRun) {
                    continue;
                }

                app(ClassSessionMaterializationService::class)->upsertSlot([
                    'StudentClassID' => $courseId,
                    'SubjectID' => StudentClass::where('ID', $courseId)->value('SubjectID') ?: null,
                    'SessionDate' => $sessionDate,
                    'StartTime' => $startTime,
                    'EndTime' => $endTime,
                    'Status' => 'scheduled',
                    'Note' => 'backfill-from-schedules',
                ]);
            }
        }, 's.id');

        $mode = $dryRun ? 'DRY-RUN' : 'APPLY';
        $this->info("[{$mode}] checked={$checked}, missing_created={$created}");

        return self::SUCCESS;
    }

    private function normalizeDateOption(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTime(string $value): string
    {
        $t = substr(trim($value), 0, 8);
        if (strlen($t) === 5) {
            $t .= ':00';
        }
        if ($t === '' || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) {
            return '00:00:00';
        }
        return $t;
    }
}
