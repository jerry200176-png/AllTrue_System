<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #804 行事曆 class-sessions 端點效能：StudentSingIn 缺 ClassSessionID 索引。
 *
 * 根因（2026-06-13 production EXPLAIN/ANALYZE 確認）：
 * `ClassSessionController::index()` 的 `si`（最新非作廢簽到）derived table
 * 因 `StudentSingIn` 只有 PRIMARY(id)、無 ClassSessionID 索引，被以 access_type=ALL
 * 全表掃描，r_loops≈4471 × ~4609 列 ≈ 2060 萬列讀取 → 全 campus 視窗 query ~33.5s。
 * 對照組 `LearningRecord`（同樣 derived table 寫法）因有
 * `learningrecord_classsessionid_unique` 而走 ref，成本極低。
 *
 * 本 migration 在 `StudentSingIn` 補上對應索引（StudentSingIn 一堂可多筆簽到，
 * 故為非唯一複合索引 (ClassSessionID, id)）：
 *  - 外層 join `si.ClassSessionID = cs.id` 可走 ref（取代 ALL）。
 *  - 內層 `GROUP BY ClassSessionID, MAX(id)` 可走索引（免 filesort、免全表掃）。
 *
 * 純索引異動，不改任何查詢語意或回傳結果（byte-identical）。
 */
return new class extends Migration
{
    private string $table = 'StudentSingIn';
    private string $index = 'idx_ssi_classsession_id';

    public function up(): void
    {
        if (!Schema::hasTable($this->table)) {
            return;
        }
        try {
            DB::statement("ALTER TABLE `{$this->table}` ADD INDEX `{$this->index}` (`ClassSessionID`, `id`)");
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name')) {
                return;
            }
            throw $e;
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable($this->table)) {
            return;
        }
        try {
            DB::statement("ALTER TABLE `{$this->table}` DROP INDEX `{$this->index}`");
        } catch (\Throwable $e) {
            // safe to ignore if index doesn't exist
        }
    }
};
