<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }

        if (Schema::hasColumn('StudentClass', 'RoomID') && Schema::hasColumn('StudentClass', 'room_id') && Schema::hasTable('rooms')) {
            DB::statement("
                UPDATE StudentClass sc
                JOIN rooms r ON r.id = CAST(sc.RoomID AS UNSIGNED)
                SET sc.room_id = r.id
                WHERE sc.room_id IS NULL
                  AND sc.RoomID IS NOT NULL
                  AND sc.RoomID REGEXP '^[0-9]+$'
            ");
        }

        if (Schema::hasColumn('StudentClass', 'RoomID')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->dropColumn('RoomID');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('StudentClass') || Schema::hasColumn('StudentClass', 'RoomID')) {
            return;
        }

        Schema::table('StudentClass', function (Blueprint $table) {
            $table->string('RoomID', 32)->nullable()->after('LearnTimeID');
        });

        if (Schema::hasColumn('StudentClass', 'room_id')) {
            DB::statement("
                UPDATE StudentClass
                SET RoomID = CAST(room_id AS CHAR)
                WHERE room_id IS NOT NULL
            ");
        }
    }
};
