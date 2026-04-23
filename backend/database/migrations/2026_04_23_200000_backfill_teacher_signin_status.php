<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 回填 TeacherSingIn.Status（2026-04-23）
 *
 * 背景：Migration add_source_status_to_teacher_sing_in 以 DEFAULT 'pending_review'
 * 新增 Status 欄位，MySQL 自動將所有既有行回填為 'pending_review'。
 * 這導致行政出勤（當日無排課）的老師在 Dashboard 顯示「系統待確認」。
 *
 * 本 Migration 對所有 Status='pending_review' 的記錄，依當日 schedules 重新判斷：
 *   - 當日無課 → source_only（行政出勤）
 *   - 有課且刷卡在首堂 +10 分鐘以內 → normal
 *   - 有課且刷卡超過首堂 +10 分鐘 → late
 *
 * down() 為 no-op：無法區分真正的 pending_review（exception fallback）
 * vs 已修正記錄，不做還原。
 */
return new class extends Migration
{
    private const LATE_THRESHOLD_MINUTES = 10;
    private const CHUNK_SIZE = 200;

    public function up(): void
    {
        if (! Schema::hasTable('TeacherSingIn') || ! Schema::hasTable('schedules')) {
            Log::warning('backfill_teacher_signin_status: required tables missing, skipping');
            return;
        }

        $total = DB::table('TeacherSingIn')->where('Status', 'pending_review')->count();

        if ($total === 0) {
            Log::info('backfill_teacher_signin_status: no pending_review rows, nothing to do');
            return;
        }

        Log::info("backfill_teacher_signin_status: start, total={$total}");

        $processed  = 0;
        $sourceOnly = 0;
        $normal     = 0;
        $late       = 0;

        DB::table('TeacherSingIn')
            ->where('Status', 'pending_review')
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function ($rows) use (&$processed, &$sourceOnly, &$normal, &$late, $total) {
                foreach ($rows as $row) {
                    $signInDate = $row->SignInDT
                        ? substr((string) $row->SignInDT, 0, 10)
                        : null;

                    if (! $signInDate || ! $row->TeacherID) {
                        continue;
                    }

                    $firstClass = DB::table('schedules')
                        ->where('teacher_id', (int) $row->TeacherID)
                        ->where('schedule_date', $signInDate)
                        ->where('status', '!=', 'cancelled')
                        ->orderBy('start_time')
                        ->first();

                    if (! $firstClass) {
                        $newStatus = 'source_only';
                        $sourceOnly++;
                    } else {
                        try {
                            $classStart = Carbon::parse("{$signInDate} {$firstClass->start_time}");
                            $swipeAt    = Carbon::parse($row->SignInDT);
                            $threshold  = $classStart->copy()->addMinutes(self::LATE_THRESHOLD_MINUTES);
                            $newStatus  = $swipeAt->lte($threshold) ? 'normal' : 'late';
                        } catch (\Throwable $e) {
                            Log::warning('backfill_teacher_signin_status: parse error, skipping row', [
                                'id'    => $row->id,
                                'error' => $e->getMessage(),
                            ]);
                            continue;
                        }

                        if ($newStatus === 'normal') {
                            $normal++;
                        } else {
                            $late++;
                        }
                    }

                    DB::table('TeacherSingIn')
                        ->where('id', $row->id)
                        ->update(['Status' => $newStatus]);

                    $processed++;
                }

                if ($processed > 0 && $processed % 1000 === 0) {
                    Log::info("backfill_teacher_signin_status: progress {$processed}/{$total}");
                }
            });

        Log::info('backfill_teacher_signin_status: done', [
            'processed'   => $processed,
            'source_only' => $sourceOnly,
            'normal'      => $normal,
            'late'        => $late,
            'skipped'     => $total - $processed,
        ]);
    }

    public function down(): void
    {
        // no-op：回填後無法區分真正的 pending_review vs 已修正記錄
    }
};
