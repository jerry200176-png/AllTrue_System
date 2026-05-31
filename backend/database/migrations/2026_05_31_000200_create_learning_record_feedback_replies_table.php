<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 家長回饋雙向回覆對話串（System A：learning_record_feedbacks）。
 *  - 新表 learning_record_feedback_replies：老師/主任/家長的逐則回覆。
 *  - learning_record_feedbacks 加 last_read_by_parent_at：家長端「有新回覆」紅點。
 * idempotent：可重複執行；含 down() 回滾。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('learning_record_feedback_replies')) {
            Schema::create('learning_record_feedback_replies', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('feedback_id');
                // 員工回覆時為 User.id；家長回覆時為 null（以 parent_session_id 記錄）
                $table->unsignedBigInteger('author_user_id')->nullable();
                // teacher / director / parent
                $table->string('author_role', 20);
                $table->unsignedBigInteger('parent_session_id')->nullable();
                $table->text('content');
                $table->timestamps();

                $table->index(['feedback_id', 'id'], 'lrfr_feedback_idx');
            });
        }

        if (
            Schema::hasTable('learning_record_feedbacks')
            && !Schema::hasColumn('learning_record_feedbacks', 'last_read_by_parent_at')
        ) {
            Schema::table('learning_record_feedbacks', function (Blueprint $table) {
                $table->dateTime('last_read_by_parent_at')->nullable()->after('last_read_by_director_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_record_feedback_replies');

        if (
            Schema::hasTable('learning_record_feedbacks')
            && Schema::hasColumn('learning_record_feedbacks', 'last_read_by_parent_at')
        ) {
            Schema::table('learning_record_feedbacks', function (Blueprint $table) {
                $table->dropColumn('last_read_by_parent_at');
            });
        }
    }
};
