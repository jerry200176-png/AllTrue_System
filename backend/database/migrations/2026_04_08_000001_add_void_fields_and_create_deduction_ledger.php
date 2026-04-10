<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add void columns to StudentSingIn
        if (Schema::hasTable('StudentSingIn') && !Schema::hasColumn('StudentSingIn', 'VoidedAt')) {
            Schema::table('StudentSingIn', function (Blueprint $table) {
                $table->timestamp('VoidedAt')->nullable()->after('SessionDeducted');
                $table->unsignedBigInteger('VoidedByUserID')->nullable()->after('VoidedAt');
                $table->string('VoidReason', 255)->nullable()->after('VoidedByUserID');
            });
        }

        // Add void columns to LearningRecord
        if (Schema::hasTable('LearningRecord') && !Schema::hasColumn('LearningRecord', 'VoidedAt')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->timestamp('VoidedAt')->nullable();
                $table->unsignedBigInteger('VoidedByUserID')->nullable();
                $table->string('VoidReason', 255)->nullable();
            });
        }

        // Create session_deduction_ledger
        if (!Schema::hasTable('session_deduction_ledger')) {
            Schema::create('session_deduction_ledger', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('student_class_id')->index();
                $table->unsignedBigInteger('class_session_id')->nullable()->index();
                $table->string('event_type', 16); // deduct / reverse
                $table->string('source', 32);     // attendance / learning_record / retro_leave
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->index(['student_class_id', 'class_session_id', 'event_type'], 'ledger_unique_check');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('session_deduction_ledger');

        if (Schema::hasTable('LearningRecord') && Schema::hasColumn('LearningRecord', 'VoidedAt')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->dropColumn(['VoidedAt', 'VoidedByUserID', 'VoidReason']);
            });
        }

        if (Schema::hasTable('StudentSingIn') && Schema::hasColumn('StudentSingIn', 'VoidedAt')) {
            Schema::table('StudentSingIn', function (Blueprint $table) {
                $table->dropColumn(['VoidedAt', 'VoidedByUserID', 'VoidReason']);
            });
        }
    }
};
