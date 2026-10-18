<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** Founder D3/B1 (#4132): additive permanent void→replacement link; UNIQUE(original); rows never deleted; R5 Founder before prod. */
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
        if (Schema::hasTable('invoice_corrections') && DB::table('invoice_corrections')->exists()) {
            throw new RuntimeException('Retain permanent invoice correction history; disable application code instead.');
        }
        Schema::dropIfExists('invoice_corrections');
    }
};
