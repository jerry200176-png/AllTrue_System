<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #851/#165 軍階系統重新校準：重置所有使用者軍階。
 *
 * 背景：升官曲線由「升太快」（士官長僅需 275 XP ≈ 數日）重設為長期養成曲線
 * （士官長需數月、將官為長期榮譽）。為了讓所有人公平地在新曲線上重新起跑，
 * 一次性把 xp_total 歸零、rank_key 還原為預設（二等兵），並清空 XP 事件歷史
 * （避免 xpHistory 顯示與重置後階級不一致的舊紀錄）。
 *
 * 這是刻意的一次性資料重置（gamification relaunch），不可逆；down() 為 no-op。
 * 以 Schema::hasTable 防護，且為冪等（重跑只是再次歸零，無副作用）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_engagement_xp_events')) {
            // 清空 XP 事件歷史，使重置後 totals 與顯示一致（DELETE 而非 TRUNCATE，
            // 保留 FK/交易語意，且不重置自增以免影響既有 idempotency_key 唯一性）。
            DB::table('user_engagement_xp_events')->delete();
        }

        if (Schema::hasTable('user_engagement')) {
            DB::table('user_engagement')->update([
                'xp_total' => 0,
                'rank_key' => 'private_second',
            ]);
        }
    }

    public function down(): void
    {
        // 一次性資料重置，無法還原原始 XP；刻意 no-op。
    }
};
