<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: per-school 段考 date ranges (#4970 step 1). New table only.
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_exam_periods')) {
            return;
        }
        Schema::create('school_exam_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('campus_id');
            $table->string('school_name', 64);
            $table->unsignedSmallInteger('academic_year');
            $table->unsignedTinyInteger('semester');
            $table->unsignedTinyInteger('seq');
            $table->string('label', 40);
            $table->date('start_date');
            $table->date('end_date');
            $table->json('grade_ids')->nullable();
            // 'all' or sorted "1,2,3"; lets the unique key cover grade scope.
            $table->string('grade_key', 40)->default('all');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['campus_id', 'school_name', 'academic_year', 'semester', 'seq', 'grade_key'],
                'school_exam_periods_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_exam_periods');
    }
};
