<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentClass') || !Schema::hasTable('Student') || !Schema::hasTable('rooms')) {
            return;
        }
        if (!Schema::hasColumn('StudentClass', 'room_id')) {
            return;
        }

        DB::statement("
            UPDATE StudentClass sc
            JOIN Student s ON s.id = sc.StudentID
            LEFT JOIN rooms r ON r.id = sc.room_id
            SET sc.room_id = NULL
            WHERE sc.room_id IS NOT NULL
              AND (r.id IS NULL OR r.campus_id <> s.CampusID)
        ");
    }

    public function down(): void
    {
        // Irreversible cleanup: legacy RoomID was dropped, so mismatched room choices cannot be reconstructed safely.
    }
};
