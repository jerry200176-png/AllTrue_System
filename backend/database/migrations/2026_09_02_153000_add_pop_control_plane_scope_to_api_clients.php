<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ApiClient')) {
            return;
        }

        Schema::table('ApiClient', function (Blueprint $table): void {
            if (!Schema::hasColumn('ApiClient', 'Purpose')) {
                $table->string('Purpose', 32)->default('attendance_device')->after('Active');
            }
            if (!Schema::hasColumn('ApiClient', 'Scopes')) {
                $table->json('Scopes')->nullable()->after('Purpose');
            }
        });

        DB::table('ApiClient')->whereNull('Purpose')->update(['Purpose' => 'attendance_device']);
        DB::table('ApiClient')->whereNull('Scopes')->update([
            'Scopes' => json_encode(['attendance:swipe'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('ApiClient')) {
            return;
        }

        Schema::table('ApiClient', function (Blueprint $table): void {
            if (Schema::hasColumn('ApiClient', 'Scopes')) {
                $table->dropColumn('Scopes');
            }
            if (Schema::hasColumn('ApiClient', 'Purpose')) {
                $table->dropColumn('Purpose');
            }
        });
    }
};
