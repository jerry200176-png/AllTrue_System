<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fulltime_salary_profiles', 'manual_multiplier_pct')) {
            Schema::table('fulltime_salary_profiles', function (Blueprint $table) {
                $table->decimal('manual_multiplier_pct', 6, 2)->nullable()->after('base_salary');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fulltime_salary_profiles', 'manual_multiplier_pct')) {
            Schema::table('fulltime_salary_profiles', function (Blueprint $table) {
                $table->dropColumn('manual_multiplier_pct');
            });
        }
    }
};
