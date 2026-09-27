<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * in-app #295: staff can dismiss "awaiting reply" without posting a public reply.
 * Parent activity after dismiss clears the flag so awaiting can reopen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('learning_record_feedbacks')) {
            return;
        }
        Schema::table('learning_record_feedbacks', function (Blueprint $table) {
            if (!Schema::hasColumn('learning_record_feedbacks', 'awaiting_dismissed_at')) {
                $table->timestamp('awaiting_dismissed_at')->nullable();
            }
            if (!Schema::hasColumn('learning_record_feedbacks', 'awaiting_dismissed_by')) {
                $table->unsignedBigInteger('awaiting_dismissed_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('learning_record_feedbacks')) {
            return;
        }
        Schema::table('learning_record_feedbacks', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('learning_record_feedbacks', 'awaiting_dismissed_by')) {
                $cols[] = 'awaiting_dismissed_by';
            }
            if (Schema::hasColumn('learning_record_feedbacks', 'awaiting_dismissed_at')) {
                $cols[] = 'awaiting_dismissed_at';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
