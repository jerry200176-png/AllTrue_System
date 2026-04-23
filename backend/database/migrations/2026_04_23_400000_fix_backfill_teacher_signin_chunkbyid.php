<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 修正 2026_04_23_200000_backfill_teacher_signin_status 的 chunk mutation bug。
 *
 * 原 migration 使用 chunk()，在 callback 內修改迭代基準的 Status，
 * 導致 OFFSET 跳行，約 600 筆 pending_review 記錄未被更新。
 *
 * 本 migration 改用 chunkById()（以 id > last_id 方式分頁），
 * 對修改基準 Status 的 mutation 安全。
 */
return new class extends Migration
{
    private const LATE_THRESHOLD_MINUTES = 10;
    private const CHUNK_SIZE = 200;

    public function up(): void
    {
        if (! Schema::hasTable('TeacherSingIn') || ! Schema::hasTable('schedules')) {
            Log::warning('fix_backfill_teacher_signin_chunkbyid: required tables missing, skipping');
            return;
        }

        $total = DB::table('TeacherSingIn')->where('Status', 'pending_review')->count();

        if ($total === 0) {
            Log::info('fix_backfill_teacher_signin_chunkbyid: no pending_review rows, nothing to do');
            return;
        }

        Log::info("fix_backfill_teacher_signin_chunkbyid: start, total={$total}");

        $processed  = 0;
        $sourceOnly = 0;
        $normal     = 0;
        $late       = 0;
        $skipped    = 0;

        // chunkById uses WHERE id > last_id instead of OFFSET — safe for mutation
        DB::table('TeacherSingIn')
            ->where('Status', 'pending_review')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (
                &$processed, &$sourceOnly, &$normal, &$late, &$skipped
            ) {
                foreach ($rows as $row) {
                    $signInDate = $row->SignInDT
                        ? substr((string) $row->SignInDT, 0, 10)
                        : null;

                    if (! $signInDate || ! $row->TeacherID) {
                        $skipped++;
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
                            if ($newStatus === 'normal') {
                                $normal++;
                            } else {
                                $late++;
                            }
                        } catch (\Throwable $e) {
                            Log::warning('fix_backfill_teacher_signin_chunkbyid: parse error, skipping', [
                                'id'    => $row->id,
                                'error' => $e->getMessage(),
                            ]);
                            $skipped++;
                            continue;
                        }
                    }

                    DB::table('TeacherSingIn')
                        ->where('id', $row->id)
                        ->update(['Status' => $newStatus]);

                    $processed++;
                }
            });

        Log::info('fix_backfill_teacher_signin_chunkbyid: done', [
            'processed'   => $processed,
            'source_only' => $sourceOnly,
            'normal'      => $normal,
            'late'        => $late,
            'skipped'     => $skipped,
        ]);
    }

    public function down(): void
    {
        // no-op
    }
};
