<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Founder D3/B1 (#4132): permanent link from a voided bill to the bill that corrects it. Additive (one new table, no
 * data writes). UNIQUE(original_invoice_id): the database refuses a second correction of a bill from any request;
 * key + reason + amount let the same request's retry get its result. No APP_KEY or audit-retention dependence.
 * Rows are never deleted. Rollback = drop the table. Needs Founder approval before production (R5: billing table).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_corrections')) {
            return;
        }
        Schema::create('invoice_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_invoice_id')->unique('uq_invoice_corrections_original');
            $table->unsignedBigInteger('replacement_invoice_id')->unique('uq_invoice_corrections_replacement');
            $table->unsignedInteger('student_class_id')->index();
            $table->unsignedBigInteger('payment_report_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('reason', 80);
            $table->char('idempotency_key', 36);
            $table->unsignedBigInteger('operator_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_corrections');
    }
};
