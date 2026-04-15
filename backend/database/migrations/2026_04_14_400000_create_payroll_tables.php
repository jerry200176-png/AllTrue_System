<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_month_status')) {
            Schema::create('payroll_month_status', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id');
                $table->char('month', 7); // YYYY-MM
                $table->enum('status', ['draft', 'reviewed', 'locked'])->default('draft');
                $table->unsignedInteger('locked_by')->nullable();
                $table->datetime('locked_at')->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'month']);
            });
        }

        if (!Schema::hasTable('payroll_audit_log')) {
            Schema::create('payroll_audit_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id');
                $table->char('month', 7);
                $table->enum('action', ['lock', 'reopen', 'reviewed']);
                $table->unsignedInteger('user_id');
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // Performance index for payroll queries
        try {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->index(['TeacherID', 'Status', 'SessionDate'], 'lr_payroll_teacher_status_date');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }

        // Index for filtering part-time teachers
        try {
            Schema::table('User', function (Blueprint $table) {
                $table->index(['employment_type', 'type'], 'user_employment_type_idx');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_audit_log');
        Schema::dropIfExists('payroll_month_status');

        try {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->dropIndex('lr_payroll_teacher_status_date');
            });
        } catch (\Exception $e) {}

        try {
            Schema::table('User', function (Blueprint $table) {
                $table->dropIndex('user_employment_type_idx');
            });
        } catch (\Exception $e) {}
    }
};
