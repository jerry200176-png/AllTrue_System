<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rooms')) {
            return;
        }
        if (!Schema::hasColumn('rooms', 'memo')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->string('memo', 512)->nullable()->after('capacity');
            });
        }
        if (!Schema::hasColumn('rooms', 'is_active')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('memo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rooms')) {
            Schema::table('rooms', function (Blueprint $table) {
                if (Schema::hasColumn('rooms', 'memo')) {
                    $table->dropColumn('memo');
                }
                if (Schema::hasColumn('rooms', 'is_active')) {
                    $table->dropColumn('is_active');
                }
            });
        }
    }
};
