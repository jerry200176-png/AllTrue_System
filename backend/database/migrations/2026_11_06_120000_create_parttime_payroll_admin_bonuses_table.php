<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// #4971 (in-app #508): hand-entered 行政加給 for part-time payroll. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parttime_payroll_admin_bonuses')) {
            return;
        }
        Schema::create('parttime_payroll_admin_bonuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('campus_id');
            $table->unsignedBigInteger('teacher_id');
            $table->char('month', 7);
            $table->unsignedInteger('amount');
            $table->string('reason', 100);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['teacher_id', 'campus_id', 'month'], 'ppab_teacher_campus_month_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parttime_payroll_admin_bonuses');
    }
};
