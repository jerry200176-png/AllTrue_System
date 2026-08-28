<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('StudentClass', 'trial_converted_to_id')) {
            Schema::table('StudentClass', function (Blueprint $table): void {
                $table->unsignedInteger('trial_converted_to_id')->nullable()->index('idx_student_class_trial_conversion');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('StudentClass', 'trial_converted_to_id')) {
            Schema::table('StudentClass', function (Blueprint $table): void {
                $table->dropIndex('idx_student_class_trial_conversion');
                $table->dropColumn('trial_converted_to_id');
            });
        }
    }
};
