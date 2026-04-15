<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_session_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('student_class_id');
            $table->unsignedBigInteger('class_session_id')->nullable();
            $table->tinyInteger('delta')->comment('+1 reverse, -1 deduct');
            $table->string('reason', 64)->default('attendance');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['package_id', 'class_session_id', 'reason'],
                'uq_pkg_session_reason'
            );

            $table->index('package_id', 'idx_psl_package');
            $table->index('student_class_id', 'idx_psl_sc');
            $table->index('class_session_id', 'idx_psl_cs');
        });

        Schema::create('course_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('campus_id');
            $table->string('name', 128);
            $table->unsignedInteger('total_sessions');
            $table->integer('remaining_sessions')->default(0);
            $table->unsignedInteger('used_sessions')->default(0);
            $table->decimal('rate', 10, 2)->default(0);
            $table->string('rate_unit', 16)->default('session');
            $table->string('class_type', 32)->default('one_on_one');
            $table->boolean('paid')->default(false);
            $table->date('paid_at')->nullable();
            $table->boolean('stop')->default(false);
            $table->string('closed_reason', 32)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('student_id', 'idx_cp_student');
            $table->index('campus_id', 'idx_cp_campus');
            $table->index(['campus_id', 'stop'], 'idx_cp_campus_stop');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_session_ledger');
        Schema::dropIfExists('course_packages');
    }
};
