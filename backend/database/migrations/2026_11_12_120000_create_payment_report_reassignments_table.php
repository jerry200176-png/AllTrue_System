<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #5113 (in-app #522), design PR 2: link row for "move a confirmed receipt to the correct bill".
 * One append-only row per move: the voided source receipt, the new receipt of record, both bills, both students,
 * the campus, the amount, the reason and the actor. The money itself stays in ordinary Payment rows.
 * New table only; nothing existing is altered and no row is written here. A receipt is moved at most once and
 * a new receipt comes from at most one move (the two unique keys); a wrong move is corrected by moving the new
 * receipt again. No foreign keys, so nothing cascades: history rows outlive whatever they point at.
 * Nothing reads or writes this table until the service PRs of the same design land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_report_reassignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('from_report_id');
            $table->unsignedBigInteger('to_report_id');
            $table->unsignedBigInteger('from_invoice_id')->nullable();
            $table->unsignedBigInteger('to_invoice_id');
            $table->unsignedBigInteger('from_student_id');
            $table->unsignedBigInteger('to_student_id');
            $table->unsignedInteger('campus_id');
            $table->unsignedInteger('amount');
            $table->string('reason', 500);
            $table->char('idempotency_key', 36);
            $table->unsignedBigInteger('actor_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique('from_report_id', 'prr_from_report_uq');
            $table->unique('to_report_id', 'prr_to_report_uq');
            $table->index('idempotency_key', 'prr_idempotency_key_idx');
            $table->index(['campus_id', 'created_at'], 'prr_campus_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_report_reassignments');
    }
};
