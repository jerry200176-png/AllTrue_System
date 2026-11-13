<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: 教材範本庫 (#4970 step 2). New tables only; no unique/foreign keys
 * (ordering and ownership are enforced in the controller, like exam_round_subjects).
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('campus_id');
            $table->string('subject', 40);
            // Same codes as frontend GRADES: P1..P6, J1..J3, H1..H3.
            $table->string('grade', 8);
            $table->string('publisher', 40)->nullable();
            $table->unsignedSmallInteger('academic_year')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->string('title', 120);
            $table->string('status', 12)->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'status', 'subject', 'grade'], 'curriculum_templates_campus_status_idx');
        });
        Schema::create('curriculum_template_units', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title', 120);
            $table->string('note', 255)->nullable();
            // Which 段考 of the semester (1..3, 3 = 期末) the unit leads to; null = none.
            $table->unsignedTinyInteger('exam_sequence')->nullable();
            $table->timestamps();

            $table->index(['template_id', 'sort_order'], 'curriculum_template_units_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_template_units');
        Schema::dropIfExists('curriculum_templates');
    }
};
