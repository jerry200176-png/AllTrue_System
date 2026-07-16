<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable ClassSession corrections (in-app #173 supersede-after-renewal).
 *
 * Structured metadata — do not rely on ClassSession.Note free text alone.
 * Rows are append-only; rollback sets rolled_back_at and restores session Status
 * from previous_status (never DELETE historical correction rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('session_corrections')) {
            return;
        }

        Schema::create('session_corrections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id')->comment('Superseded ClassSession.id');
            $table->unsignedBigInteger('replaced_by_session_id')->comment('Keeper ClassSession.id');
            $table->string('correction_reason', 64);
            $table->string('decision_reference', 64);
            $table->timestamp('decided_at');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->string('decided_by_actor', 128)->nullable();
            $table->string('previous_status', 32);
            $table->string('new_status', 32);
            $table->unsignedBigInteger('preserved_learning_record_id')->nullable()
                ->comment('LR stays on superseded session; never moved');
            $table->unsignedBigInteger('keeper_learning_record_id')->nullable()
                ->comment('Trace link to LR on keeper session');
            $table->json('snapshot_before')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('replaced_by_session_id');
            $table->index('decision_reference');
            $table->index(['session_id', 'decision_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_corrections');
    }
};
