<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: 試聽暫留 (in-app #575, #5499). New table only, no foreign keys
 * (campus / teacher ownership is checked in the controller).
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_holds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('campus_id');
            $table->unsignedBigInteger('teacher_id');
            $table->date('hold_date');
            $table->time('start_time');
            $table->time('end_time');
            // Internal staff note only; never shown to parents.
            $table->string('note', 100)->nullable();
            // held | converted | cancelled
            $table->string('status', 10)->default('held');
            $table->unsignedBigInteger('converted_student_class_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'hold_date', 'status'], 'trial_holds_teacher_date_idx');
            $table->index(['campus_id', 'hold_date'], 'trial_holds_campus_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_holds');
    }
};
