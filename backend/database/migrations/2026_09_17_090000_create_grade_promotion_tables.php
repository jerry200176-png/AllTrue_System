<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('grade_promotion_batches')) {
            Schema::create('grade_promotion_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('campus_id');
                $table->unsignedSmallInteger('season_year');
                $table->string('idempotency_key', 64);
                $table->unsignedBigInteger('actor_user_id');
                $table->string('status', 32)->default('completed');
                $table->json('summary')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique('idempotency_key', 'grade_promo_batch_idem_unique');
                $table->index(['campus_id', 'season_year']);
            });
        }

        if (!Schema::hasTable('grade_promotion_results')) {
            Schema::create('grade_promotion_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedSmallInteger('season_year');
                $table->string('from_grade', 8)->nullable();
                $table->string('to_grade', 8)->nullable();
                $table->boolean('graduated')->default(false);
                $table->timestamp('created_at')->useCurrent();

                // Per-student / per-season uniqueness — multiple batches allowed,
                // but the same student cannot be promoted twice in one season.
                $table->unique(['student_id', 'season_year'], 'grade_promo_student_season_unique');
                $table->index('batch_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_promotion_results');
        Schema::dropIfExists('grade_promotion_batches');
    }
};
