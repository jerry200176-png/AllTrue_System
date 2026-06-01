<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe / merge-window log for learning-record feedback push notifications.
 * One row per (feedback_id, direction); last_pushed_at gates the merge window so
 * a burst of replies within N minutes only triggers a single push.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('feedback_push_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('feedback_id');
            // 'to_staff' = parent submit/reply notifies teacher+director (in-app)
            // 'to_parent' = staff reply notifies parent (LINE)
            $table->string('direction', 16);
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('learning_record_id')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['feedback_id', 'direction'], 'feedback_push_log_feedback_direction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_push_log');
    }
};
