<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_payroll_events')) {
            Schema::create('teacher_payroll_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_id')->nullable();
                $table->unsignedInteger('branch_id')->nullable();
                $table->date('event_date');
                $table->string('event_type', 32); // holiday, official_closure, leave
                $table->decimal('hours', 6, 2)->nullable();
                $table->string('leave_type', 32)->nullable();
                $table->decimal('holiday_leave_hours', 6, 2)->nullable();
                $table->boolean('makeup_completed')->default(false);
                $table->string('status', 24)->default('approved');
                $table->text('evidence')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'event_date'], 'tpe_teacher_date_idx');
                $table->index(['branch_id', 'event_date', 'event_type'], 'tpe_branch_date_type_idx');
            });
        }

        if (!Schema::hasTable('teacher_payroll_achievements')) {
            Schema::create('teacher_payroll_achievements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_id');
                $table->unsignedInteger('branch_id')->nullable();
                $table->unsignedBigInteger('student_id')->nullable();
                $table->string('outcome_key', 96);
                $table->string('subject', 96)->nullable();
                $table->text('evidence')->nullable();
                $table->string('status', 24)->default('pending');
                $table->unsignedBigInteger('verified_by')->nullable();
                $table->dateTime('verified_at')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'starts_on', 'ends_on'], 'tpa_teacher_period_idx');
                $table->index(['branch_id', 'status'], 'tpa_branch_status_idx');
            });
        }

        if (!Schema::hasTable('teacher_payroll_deductions')) {
            Schema::create('teacher_payroll_deductions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_id');
                $table->unsignedInteger('branch_id')->nullable();
                $table->string('deduction_key', 96);
                $table->text('reason')->nullable();
                $table->string('status', 24)->default('pending');
                $table->unsignedBigInteger('director_confirmed_by')->nullable();
                $table->dateTime('director_confirmed_at')->nullable();
                $table->unsignedBigInteger('hq_approved_by')->nullable();
                $table->dateTime('hq_approved_at')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'starts_on', 'ends_on'], 'tpd_teacher_period_idx');
                $table->index(['branch_id', 'status'], 'tpd_branch_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payroll_deductions');
        Schema::dropIfExists('teacher_payroll_achievements');
        Schema::dropIfExists('teacher_payroll_events');
    }
};
