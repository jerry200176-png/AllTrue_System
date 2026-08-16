<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fulltime_payroll_runs')) {
            Schema::create('fulltime_payroll_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id');
                $table->char('month', 7);
                $table->string('status', 16)->default('locked');
                $table->unsignedInteger('locked_by')->nullable();
                $table->dateTime('locked_at')->nullable();
                $table->unsignedInteger('reopened_by')->nullable();
                $table->dateTime('reopened_at')->nullable();
                $table->text('reopen_reason')->nullable();
                $table->unsignedInteger('teacher_count')->default(0);
                $table->decimal('branch_subject_total', 12, 4)->default(0);
                $table->string('policy_version', 16)->nullable();
                $table->timestamps();
                $table->index(['branch_id', 'month', 'status']);
            });
        }

        if (!Schema::hasTable('fulltime_payroll_run_teachers')) {
            Schema::create('fulltime_payroll_run_teachers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedInteger('teacher_id');
                $table->boolean('review_required')->default(false);
                $table->decimal('total_payout', 12, 2)->default(0);
                $table->json('payload');
                $table->timestamps();
                $table->unique(['run_id', 'teacher_id']);
            });
        }

        if (!Schema::hasTable('fulltime_payroll_adjustments')) {
            Schema::create('fulltime_payroll_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id');
                $table->char('month', 7);
                $table->unsignedInteger('teacher_id');
                $table->string('field', 32);
                $table->decimal('delta', 12, 4);
                $table->string('label', 64)->nullable();
                $table->string('note', 500)->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['branch_id', 'month', 'teacher_id']);
            });
        }

        if (!Schema::hasTable('fulltime_payroll_audit_logs')) {
            Schema::create('fulltime_payroll_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('branch_id');
                $table->char('month', 7);
                $table->string('action', 32);
                $table->unsignedInteger('user_id')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['branch_id', 'month']);
            });
        }

        if (!Schema::hasTable('teacher_payroll_admin_allowances')) {
            Schema::create('teacher_payroll_admin_allowances', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('teacher_id');
                $table->unsignedInteger('branch_id')->nullable();
                $table->decimal('rate', 5, 2);
                $table->string('role_label', 64);
                $table->string('reason', 10000)->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->string('status', 24)->default('pending');
                $table->unsignedInteger('director_confirmed_by')->nullable();
                $table->dateTime('director_confirmed_at')->nullable();
                $table->unsignedInteger('hq_approved_by')->nullable();
                $table->dateTime('hq_approved_at')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payroll_admin_allowances');
        Schema::dropIfExists('fulltime_payroll_audit_logs');
        Schema::dropIfExists('fulltime_payroll_adjustments');
        Schema::dropIfExists('fulltime_payroll_run_teachers');
        Schema::dropIfExists('fulltime_payroll_runs');
    }
};
