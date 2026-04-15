<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('UserCampus')) {
            return;
        }
        if (!Schema::hasColumn('UserCampus', 'RFID')) {
            Schema::table('UserCampus', function (Blueprint $table) {
                $table->string('RFID', 32)->nullable();
            });
        }

        if (Schema::hasTable('Teacher') && Schema::hasColumn('UserCampus', 'RFID')) {
            $rows = DB::table('Teacher')
                ->whereNotNull('RFID')
                ->where('RFID', '!=', '')
                ->get(['id', 'CampusID', 'RFID']);
            foreach ($rows as $row) {
                $campusId = (int) ($row->CampusID ?? 0);
                if ($campusId <= 0) {
                    continue;
                }
                DB::table('UserCampus')
                    ->where('UserID', (int) $row->id)
                    ->where('CampusID', $campusId)
                    ->update(['RFID' => $row->RFID]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('UserCampus') && Schema::hasColumn('UserCampus', 'RFID')) {
            Schema::table('UserCampus', function (Blueprint $table) {
                $table->dropColumn('RFID');
            });
        }
    }
};
