<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('User')) {
            return;
        }
        if (!Schema::hasColumn('User', 'status')) {
            Schema::table('User', function (Blueprint $table) {
                $table->string('status', 32)->nullable()->default('active')->after('type');
            });
        }
        if (!Schema::hasColumn('User', 'employment_type')) {
            Schema::table('User', function (Blueprint $table) {
                $table->string('employment_type', 32)->nullable()->default('full_time')->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('User') && Schema::hasColumn('User', 'status')) {
            Schema::table('User', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
        if (Schema::hasTable('User') && Schema::hasColumn('User', 'employment_type')) {
            Schema::table('User', function (Blueprint $table) {
                $table->dropColumn('employment_type');
            });
        }
    }
};
