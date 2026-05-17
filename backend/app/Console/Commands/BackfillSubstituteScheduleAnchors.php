<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillSubstituteScheduleAnchors extends Command
{
    protected $signature = 'schedules:backfill-substitute-anchors
                            {--branch_id= : Scope to one branch/campus}
                            {--date= : Scope to one schedule_date (YYYY-MM-DD)}
                            {--apply : Write changes. Omit for dry-run}';

    protected $description = 'Backfill missing original_schedule_id on substitute scheduled rows; dry-run by default.';

    public function handle(): int
    {
        if (!Schema::hasTable('schedules') || !Schema::hasTable('StudentClass')) {
            $this->warn('Required tables missing (schedules/StudentClass), skipping.');
            return self::SUCCESS;
        }

        $branchId = (int) ($this->option('branch_id') ?? 0);
        $date = trim((string) ($this->option('date') ?? ''));
        $apply = (bool) $this->option('apply');

        $query = DB::table('schedules as s')
            ->join('StudentClass as sc', 'sc.ID', '=', 's.student_course_id')
            ->select([
                's.id as id',
                's.student_course_id',
                's.teacher_id',
                's.schedule_date',
                's.start_time',
                's.end_time',
                's.branch_id',
                'sc.TeacherID as contract_teacher_id',
            ])
            ->where('s.status', 'scheduled')
            ->whereNull('s.original_schedule_id')
            ->whereNotNull('s.student_course_id')
            ->whereNotNull('s.teacher_id')
            ->whereColumn('s.teacher_id', '!=', 'sc.TeacherID');

        if ($branchId > 0) {
            $query->where('s.branch_id', $branchId);
        }
        if ($date !== '') {
            $query->whereDate('s.schedule_date', $date);
        }

        $checked = 0;
        $repairable = 0;
        $updated = 0;
        $duplicatesDeleted = 0;
        $skippedNoAnchor = 0;

        $query->orderBy('s.id')->chunkById(200, function ($rows) use (
            &$checked,
            &$repairable,
            &$updated,
            &$duplicatesDeleted,
            &$skippedNoAnchor,
            $apply
        ) {
            foreach ($rows as $row) {
                $checked++;
                $courseId = (int) $row->student_course_id;
                $contractTeacherId = (int) $row->contract_teacher_id;
                $scheduleDate = substr((string) $row->schedule_date, 0, 10);

                $anchor = DB::table('schedules')
                    ->where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $scheduleDate)
                    ->where('status', 'rescheduled')
                    ->where('teacher_id', $contractTeacherId)
                    ->orderByDesc('id')
                    ->first();

                if (!$anchor) {
                    $skippedNoAnchor++;
                    $this->line("[skip:no-anchor] scheduled_id={$row->id} course={$courseId} date={$scheduleDate}");
                    continue;
                }

                $repairable++;
                $ghostQuery = DB::table('schedules')
                    ->where('student_course_id', $courseId)
                    ->whereDate('schedule_date', $scheduleDate)
                    ->where('status', 'rescheduled')
                    ->where('id', '!=', (int) $anchor->id)
                    ->where('teacher_id', '!=', $contractTeacherId)
                    ->where('start_time', $anchor->start_time)
                    ->where('end_time', $anchor->end_time);
                $ghostCount = (int) (clone $ghostQuery)->count();

                $this->line(sprintf(
                    '[%s] scheduled_id=%d -> anchor_id=%d course=%d date=%s ghosts=%d',
                    $apply ? 'apply' : 'dry-run',
                    (int) $row->id,
                    (int) $anchor->id,
                    $courseId,
                    $scheduleDate,
                    $ghostCount
                ));

                if (!$apply) {
                    continue;
                }

                DB::table('schedules')->where('id', (int) $row->id)->update([
                    'original_schedule_id' => (int) $anchor->id,
                    'updated_at' => now(),
                ]);
                $updated++;

                if ($ghostCount > 0) {
                    $duplicatesDeleted += (int) $ghostQuery->delete();
                }
            }
        }, 's.id', 'id');

        $mode = $apply ? 'APPLY' : 'DRY-RUN';
        $this->info("[{$mode}] checked={$checked}, repairable={$repairable}, updated={$updated}, duplicates_deleted={$duplicatesDeleted}, skipped_no_anchor={$skippedNoAnchor}");

        return self::SUCCESS;
    }
}
