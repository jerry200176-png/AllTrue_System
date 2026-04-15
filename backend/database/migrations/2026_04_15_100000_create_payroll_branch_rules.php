<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_branch_rules')) {
            Schema::create('payroll_branch_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id');
                $table->json('base_rates');
                $table->unsignedInteger('headcount_bonus')->default(50);
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['branch_id', 'id']);
            });
        }

        // Add rule_version_id to payroll_month_status for lock snapshots
        if (Schema::hasTable('payroll_month_status') && !Schema::hasColumn('payroll_month_status', 'rule_version_id')) {
            Schema::table('payroll_month_status', function (Blueprint $table) {
                $table->unsignedBigInteger('rule_version_id')->nullable()->after('locked_at');
            });
        }

        // Expand audit log action enum to include rule_update
        if (Schema::hasTable('payroll_audit_log')) {
            DB::statement("ALTER TABLE payroll_audit_log MODIFY COLUMN `action` ENUM('lock','reopen','reviewed','rule_update') NOT NULL");
        }

        // Seed: create v1 rule for every existing campus from config defaults
        $campuses = DB::table('Campus')->pluck('id');
        $defaults = [
            'base_rates' => json_encode(config('payroll.base_rates', [
                'high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200,
            ])),
            'headcount_bonus' => config('payroll.headcount_bonus', 50),
        ];

        foreach ($campuses as $campusId) {
            $exists = DB::table('payroll_branch_rules')->where('branch_id', $campusId)->exists();
            if (!$exists) {
                DB::table('payroll_branch_rules')->insert([
                    'branch_id'       => $campusId,
                    'base_rates'      => $defaults['base_rates'],
                    'headcount_bonus' => $defaults['headcount_bonus'],
                    'created_by'      => null,
                    'created_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_branch_rules');

        if (Schema::hasTable('payroll_month_status') && Schema::hasColumn('payroll_month_status', 'rule_version_id')) {
            Schema::table('payroll_month_status', function (Blueprint $table) {
                $table->dropColumn('rule_version_id');
            });
        }

        if (Schema::hasTable('payroll_audit_log')) {
            try {
                DB::statement("ALTER TABLE payroll_audit_log MODIFY COLUMN `action` ENUM('lock','reopen','reviewed') NOT NULL");
            } catch (\Exception $e) {}
        }
    }
};
