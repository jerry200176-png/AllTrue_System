<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id');
                $table->char('month', 7);
                $table->string('status', 16)->default('locked');
                $table->unsignedInteger('locked_by')->nullable();
                $table->dateTime('locked_at')->nullable();
                $table->unsignedBigInteger('rule_version_id')->nullable();
                $table->json('teacher_rule_snapshots')->nullable();
                $table->json('anomalies')->nullable();
                $table->unsignedInteger('anomaly_count')->default(0);
                $table->unsignedInteger('reopened_by')->nullable();
                $table->dateTime('reopened_at')->nullable();
                $table->text('reopen_reason')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'month', 'status']);
            });
        }

        if (!Schema::hasTable('payroll_run_lines')) {
            Schema::create('payroll_run_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('class_session_id');
                $table->unsignedBigInteger('student_sign_in_id')->nullable();
                $table->unsignedInteger('teacher_id');
                $table->date('session_date')->nullable();
                $table->decimal('hours', 8, 2)->default(0);
                $table->decimal('base_rate', 10, 2)->default(0);
                $table->decimal('headcount_bonus', 10, 2)->default(0);
                $table->decimal('concurrency_bonus_amount', 10, 2)->default(0);
                $table->decimal('effective_rate', 10, 2)->default(0);
                $table->decimal('session_salary', 12, 2)->default(0);
                $table->string('attendance_status', 32)->nullable();
                $table->string('rule_source', 32)->nullable();
                $table->json('payload');
                $table->timestamps();

                $table->index(['run_id', 'teacher_id']);
                $table->unique(['run_id', 'class_session_id', 'teacher_id'], 'payroll_run_line_session_teacher_unique');
            });
        }

        if (Schema::hasTable('payroll_month_status') && !Schema::hasColumn('payroll_month_status', 'current_run_id')) {
            Schema::table('payroll_month_status', function (Blueprint $table) {
                $table->unsignedBigInteger('current_run_id')->nullable()->after('teacher_rule_snapshots');
                $table->index('current_run_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_month_status') && Schema::hasColumn('payroll_month_status', 'current_run_id')) {
            Schema::table('payroll_month_status', function (Blueprint $table) {
                $table->dropIndex(['current_run_id']);
                $table->dropColumn('current_run_id');
            });
        }
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
    }
};
