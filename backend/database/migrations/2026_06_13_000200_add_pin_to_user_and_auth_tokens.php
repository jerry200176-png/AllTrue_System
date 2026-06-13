<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #769 敏感頁 PIN 二次驗證 — Phase A schema。
 *
 * 在 `User` 加 PIN 雜湊與「DB-based」失敗計數/鎖定欄位（刻意不使用 Cache，
 * 避開事故 E：生產 CACHE_DRIVER 未設＝file cache，曾造成 owner 污染全站 500）。
 * 在 `auth_tokens` 加 `pin_verified_until`：PIN 解鎖狀態綁定當前 Bearer session。
 *
 * 全部 nullable / 有預設，零行為變更，可逆。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('User')) {
            Schema::table('User', function (Blueprint $table) {
                if (!Schema::hasColumn('User', 'pin_hash')) {
                    $table->string('pin_hash')->nullable();
                }
                if (!Schema::hasColumn('User', 'pin_failed_attempts')) {
                    $table->unsignedTinyInteger('pin_failed_attempts')->default(0);
                }
                if (!Schema::hasColumn('User', 'pin_locked_until')) {
                    $table->timestamp('pin_locked_until')->nullable();
                }
                if (!Schema::hasColumn('User', 'pin_set_at')) {
                    $table->timestamp('pin_set_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('auth_tokens') && !Schema::hasColumn('auth_tokens', 'pin_verified_until')) {
            Schema::table('auth_tokens', function (Blueprint $table) {
                $table->timestamp('pin_verified_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('User')) {
            Schema::table('User', function (Blueprint $table) {
                foreach (['pin_hash', 'pin_failed_attempts', 'pin_locked_until', 'pin_set_at'] as $col) {
                    if (Schema::hasColumn('User', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('auth_tokens') && Schema::hasColumn('auth_tokens', 'pin_verified_until')) {
            Schema::table('auth_tokens', function (Blueprint $table) {
                $table->dropColumn('pin_verified_until');
            });
        }
    }
};
