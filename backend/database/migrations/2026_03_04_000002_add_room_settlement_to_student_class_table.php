<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }
        if (!Schema::hasColumn('StudentClass', 'room_id')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->unsignedBigInteger('room_id')->nullable()->after('RoomID');
                $table->foreign('room_id')->references('id')->on('rooms')->nullOnDelete();
            });
        }
        if (!Schema::hasColumn('StudentClass', 'settlement_day')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->unsignedTinyInteger('settlement_day')->nullable()->after('room_id');
            });
        }
        if (!Schema::hasColumn('StudentClass', 'monthly_sessions')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->unsignedInteger('monthly_sessions')->nullable()->after('settlement_day');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('StudentClass')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                if (Schema::hasColumn('StudentClass', 'room_id')) {
                    $table->dropForeign(['room_id']);
                    $table->dropColumn('room_id');
                }
                if (Schema::hasColumn('StudentClass', 'settlement_day')) {
                    $table->dropColumn('settlement_day');
                }
                if (Schema::hasColumn('StudentClass', 'monthly_sessions')) {
                    $table->dropColumn('monthly_sessions');
                }
            });
        }
    }
};
