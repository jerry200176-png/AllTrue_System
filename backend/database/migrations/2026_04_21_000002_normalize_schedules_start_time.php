<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug Fix C1 (2026-04-21)：標準化 schedules.start_time 為 HH:MM 格式
 *
 * 背景：schedules.start_time 欄位型別為 VARCHAR，預期統一以 HH:MM（len=5）儲存。
 * ClassSessionController::index 的 sub_sched LEFT JOIN 以此格式比對 SUBSTRING(cs.StartTime,1,5)。
 * 實測發現 1 筆代課記錄（id=611，student_course_id=143，2026-04-22 18:30）
 * 誤存為 '18:30:00'（HH:MM:SS，len=8），導致 join 失效、單堂檢視顯示錯師。
 *
 * 本 migration 將所有 LENGTH(start_time) > 5 的記錄標準化為 HH:MM。
 * 僅針對 len > 5 的記錄處理，HH:MM 格式記錄不動（冪等）。
 *
 * 程式碼面同時在 join 兩側加 SUBSTRING(...,1,5)（ClassSessionController 已修），
 * 形成「資料修正 + 防禦性 query」雙重保障。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('schedules')) {
            return;
        }

        $beforeCount = (int) DB::table('schedules')
            ->whereRaw('LENGTH(start_time) > 5')
            ->count();

        if ($beforeCount > 0) {
            DB::table('schedules')
                ->whereRaw('LENGTH(start_time) > 5')
                ->update([
                    'start_time' => DB::raw('SUBSTRING(start_time, 1, 5)'),
                ]);
        }

        $afterCount = (int) DB::table('schedules')
            ->whereRaw('LENGTH(start_time) > 5')
            ->count();

        if ($afterCount > 0) {
            throw new \RuntimeException(
                "normalize_schedules_start_time failed: {$afterCount} rows still have LENGTH(start_time) > 5"
            );
        }

        // 同步處理 end_time 欄位（若有同樣異常格式則一併修正，避免後續 join/顯示問題）
        $endCount = (int) DB::table('schedules')
            ->whereRaw('LENGTH(end_time) > 5')
            ->count();

        if ($endCount > 0) {
            DB::table('schedules')
                ->whereRaw('LENGTH(end_time) > 5')
                ->update([
                    'end_time' => DB::raw('SUBSTRING(end_time, 1, 5)'),
                ]);
        }
    }

    /**
     * down() 為 no-op：
     * - 舊格式（HH:MM:SS）無業務價值且會重新觸發 bug
     * - 已失去原始秒數資訊無法還原
     */
    public function down(): void
    {
    }
};
