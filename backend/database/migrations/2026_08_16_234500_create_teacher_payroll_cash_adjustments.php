<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_payroll_cash_adjustments')) {
            return;
        }
        Schema::create('teacher_payroll_cash_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedInteger('branch_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->text('reason');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('director_confirmed_by')->nullable();
            $table->dateTime('director_confirmed_at')->nullable();
            $table->unsignedBigInteger('hq_approved_by')->nullable();
            $table->dateTime('hq_approved_at')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['teacher_id', 'starts_on', 'ends_on'], 'tpca_teacher_period_idx');
            $table->index(['branch_id', 'status'], 'tpca_branch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payroll_cash_adjustments');
    }
};
