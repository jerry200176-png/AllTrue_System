<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TD-016: 掃描並修復停用課程（Stop=1）殘留的未來 scheduled 堂次。
 *
 * 孤兒定義：StudentClass.Stop=1 且存在 SessionDate >= today、Status='scheduled' 的 ClassSession
 *
 * 使用方式：
 *   php artisan fix:orphan-scheduled-sessions            # 實際執行
 *   php artisan fix:orphan-scheduled-sessions --dry-run  # 只報告，不修改
 *
 * 觸發時機（歷史孤兒清除）：
 *   在 Pi 上 PR merge 後執行一次，之後作為診斷工具按需使用。
 *   新的 Stop=1 操作已由 StudentClassController::cancelFutureScheduledSessions() 即時處理。
 */
class FixOrphanScheduledSessions extends Command
{
    protected $signature = 'fix:orphan-scheduled-sessions
                            {--dry-run : 只報告孤兒堂次，不實際修改}';

    protected $description = 'Cancel future scheduled sessions belonging to stopped (Stop=1) courses (TD-016)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today  = now()->toDateString();

        $orphans = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'cs.StudentClassID', '=', 'sc.ID')
            ->where('sc.Stop', 1)
            ->where('cs.Status', 'scheduled')
            ->where('cs.SessionDate', '>=', $today)
            ->select('cs.id', 'cs.StudentClassID', 'cs.SessionDate', 'cs.StartTime', 'sc.StudentID')
            ->orderBy('cs.SessionDate')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('[TD-016] No orphan sessions found. All clean.');
            return self::SUCCESS;
        }

        $this->warn("[TD-016] Found {$orphans->count()} orphan session(s):");
        foreach ($orphans as $row) {
            $this->line("  ClassSession#{$row->id}  Course#{$row->StudentClassID}  Student#{$row->StudentID}  {$row->SessionDate} {$row->StartTime}");
        }

        if ($dryRun) {
            $this->info('[dry-run] No changes made.');
            return self::SUCCESS;
        }

        $ids = $orphans->pluck('id')->all();

        DB::table('ClassSession')
            ->whereIn('id', $ids)
            ->update([
                'Status'     => 'cancelled',
                'Note'       => DB::raw("CONCAT(COALESCE(Note,''), ' [孤兒停用取消]')"),
                'updated_at' => now(),
            ]);

        $count = count($ids);
        $this->info("[TD-016] Cancelled {$count} orphan session(s).");
        Log::info("fix:orphan-scheduled-sessions: cancelled {$count} sessions", ['ids' => $ids]);

        return self::SUCCESS;
    }
}
