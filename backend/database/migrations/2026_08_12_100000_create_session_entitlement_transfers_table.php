<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable one-off entitlement transfer between prepaid course batches.
 *
 * This table records an exceptional repair only. It does not create a second
 * attendance identity: the ClassSession remains the source of truth and the
 * transfer is an explicit, reversible ownership correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('session_entitlement_transfers')) {
            return;
        }

        Schema::create('session_entitlement_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('source_student_class_id');
            $table->unsignedBigInteger('target_student_class_id');
            $table->string('reason', 128);
            $table->string('decision_reference', 128);
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->string('decided_by_actor', 128)->nullable();
            $table->timestamp('transferred_at');
            $table->timestamp('rolled_back_at')->nullable();
            $table->unsignedBigInteger('rolled_back_by_user_id')->nullable();
            $table->string('rolled_back_by_actor', 128)->nullable();
            $table->json('snapshot_before');
            $table->json('snapshot_after')->nullable();
            $table->timestamps();

            $table->unique('class_session_id', 'uq_session_entitlement_transfer_session');
            $table->index(['source_student_class_id', 'target_student_class_id'], 'idx_session_entitlement_transfer_courses');
            $table->index('decision_reference', 'idx_session_entitlement_transfer_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_entitlement_transfers');
    }
};
