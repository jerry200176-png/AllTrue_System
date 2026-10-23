<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * in-app #426 (Founder 2026-10-21): which regular class type a trial course is for.
 * Additive, nullable, no default, no backfill: NULL keeps today's trial behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('StudentClass') && !Schema::hasColumn('StudentClass', 'trial_target_class_type')) {
            Schema::table('StudentClass', function (Blueprint $table): void {
                $table->string('trial_target_class_type', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('StudentClass') && Schema::hasColumn('StudentClass', 'trial_target_class_type')) {
            Schema::table('StudentClass', function (Blueprint $table): void {
                $table->dropColumn('trial_target_class_type');
            });
        }
    }
};
