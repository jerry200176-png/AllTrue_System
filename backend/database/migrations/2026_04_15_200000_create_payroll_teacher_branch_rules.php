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
            Schema::create('payroll_teacher_branch_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('teacher_user_id');
                $table->unsignedInteger('branch_id');
                $table->json('base_rates');
                $table->unsignedInteger('headcount_bonus')->default(50);
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['branch_id', 'teacher_user_id', 'id'], 'ptbr_branch_teacher_id');
            });
        }

        if (Schema::hasTable('payroll_month_status') && !Schema::hasColumn('payroll_month_status', 'teacher_rule_snapshots')) {
            Schema::table('payroll_month_status', function (Blueprint $table) {
                $table->json('teacher_rule_snapshots')->nullable()->after('rule_version_id');
            });
        }

        if (Schema::hasTable('payroll_audit_log')) {
            try {
                DB::statement("ALTER TABLE payroll_audit_log MODIFY COLUMN `action` ENUM('lock','reopen','reviewed','rule_update','teacher_rule_update','teacher_rule_revert') NOT NULL");
            } catch (\Exception $e) {
                // ENUM may already include these values
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_teacher_branch_rules');

        if (Schema::hasTable('payroll_month_status') && Schema::hasColumn('payroll_month_status', 'teacher_rule_snapshots')) {
            Schema::table('payroll_month_status', function (Blueprint $table) {
                $table->dropColumn('teacher_rule_snapshots');
            });
        }

        if (Schema::hasTable('payroll_audit_log')) {
            try {
                DB::statement("ALTER TABLE payroll_audit_log MODIFY COLUMN `action` ENUM('lock','reopen','reviewed','rule_update') NOT NULL");
            } catch (\Exception $e) {}
        }
    }
};
