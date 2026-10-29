<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app #412 / #4153: move a contract's remaining sessions to a sibling at the same campus, pro-rata of what was
 * actually paid. One append-only row per transfer links the source contract, the new target contract, the amount
 * and the actor; the money itself stays in the existing transfer_out / transfer_in Payment pair.
 * New table only; nothing existing is altered. Plain indexes only: SiblingContractTransferService enforces one
 * live transfer per source contract and one row per idempotency key (stored hashed with the source contract id, so
 * keys never collide across contracts) under the source contract's row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_sibling_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('idempotency_key', 64);
            $table->unsignedInteger('campus_id');
            $table->unsignedBigInteger('source_student_class_id');
            $table->unsignedBigInteger('target_student_class_id');
            $table->unsignedBigInteger('source_student_id');
            $table->unsignedBigInteger('target_student_id');
            $table->unsignedInteger('purchased_sessions');
            $table->unsignedInteger('moved_sessions');
            $table->unsignedInteger('paid_amount');
            $table->unsignedInteger('amount');
            $table->unsignedBigInteger('transfer_out_payment_id');
            $table->unsignedBigInteger('transfer_in_payment_id');
            $table->string('reason', 100);
            $table->unsignedBigInteger('created_by');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index('idempotency_key', 'cst_idempotency_key_idx');
            $table->index('source_student_class_id', 'cst_source_idx');
            $table->index('target_student_class_id', 'cst_target_idx');
            $table->index(['campus_id', 'created_at'], 'cst_campus_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_sibling_transfers');
    }
};
