<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_teacher_branch_rules')) {
            return;
        }

        Schema::table('payroll_teacher_branch_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_teacher_branch_rules', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('headcount_bonus');
            }
            if (!Schema::hasColumn('payroll_teacher_branch_rules', 'use_branch_default')) {
                $table->boolean('use_branch_default')->default(false)->after('effective_from');
            }
        });

        DB::table('payroll_teacher_branch_rules')
            ->whereNull('effective_from')
            ->update(['effective_from' => '1970-01-01']);

        Schema::table('payroll_teacher_branch_rules', function (Blueprint $table) {
            $table->index(['branch_id', 'teacher_user_id', 'effective_from'], 'ptbr_branch_teacher_effective_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payroll_teacher_branch_rules')) {
            return;
        }

        Schema::table('payroll_teacher_branch_rules', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_teacher_branch_rules', 'effective_from')) {
                $table->dropIndex('ptbr_branch_teacher_effective_idx');
                $table->dropColumn('effective_from');
            }
            if (Schema::hasColumn('payroll_teacher_branch_rules', 'use_branch_default')) {
                $table->dropColumn('use_branch_default');
            }
        });
    }
};
