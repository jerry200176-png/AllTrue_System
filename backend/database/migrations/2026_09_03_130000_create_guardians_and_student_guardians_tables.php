<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive multi-guardian foundation (ADR Parent Identity / PB-04 aligned naming).
 *
 * - guardians: person + contact + optional LINE identity (independent of Student.parent_phone)
 * - student_guardians: Student 1:N relationship with role / primary / status / notify prefs
 *
 * No backfill, no cutover, no drop of parent_phone / LineID / student_line_bindings.
 * Rollback: drop these two tables only.
 *
 * Governance: merge to main ≠ production migration activation. Applying this
 * migration in production requires an explicit Founder activation GO.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('guardians')) {
            Schema::create('guardians', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('display_name', 64)->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('phone_normalized', 20)->nullable()->index();
                $table->string('line_user_id', 64)->nullable();
                $table->timestamps();

                $table->unique('line_user_id', 'guardians_line_user_id_unique');
            });
        }

        if (!Schema::hasTable('student_guardians')) {
            Schema::create('student_guardians', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('guardian_id')->index();
                $table->unsignedBigInteger('campus_id')->nullable()->index();
                $table->string('role', 32)->default('guardian'); // father|mother|guardian|other
                $table->boolean('is_primary')->default(false)->index();
                $table->string('status', 16)->default('active')->index(); // pending|active|read_only|suspended|revoked
                $table->boolean('notify_learning_feedback')->default(true);
                $table->boolean('notify_tuition')->default(true);
                $table->string('source', 32)->default('staff'); // staff|legacy_phone|line_binding|import
                $table->unsignedBigInteger('student_line_binding_id')->nullable()->index();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['student_id', 'guardian_id'], 'student_guardians_student_guardian_unique');
                $table->index(['student_id', 'status'], 'student_guardians_student_status_idx');
                $table->index(['student_id', 'is_primary', 'status'], 'student_guardians_primary_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('guardians');
    }
};
