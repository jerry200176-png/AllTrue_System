<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app #439: pass-through fees (代收: books, materials, sundry) and their payouts (代付), kept in their own
 * ledger so tuition money (Invoice / Payment / course paid state, GL 學費收入) never sees them.
 * A charge only moves forward (unpaid -> paid -> voided, or unpaid -> voided); each step keeps who and when.
 * New tables only; nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fee_charges')) {
            Schema::create('fee_charges', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('campus_id');
                $table->unsignedInteger('student_id');
                $table->string('category', 16);
                $table->string('title', 60);
                $table->unsignedInteger('amount');
                $table->date('charged_on');
                $table->unsignedBigInteger('created_by');
                $table->date('paid_on')->nullable();
                $table->string('paid_method', 16)->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 100)->nullable();
                $table->timestamps();
                $table->index(['campus_id', 'charged_on'], 'fee_charges_campus_date_idx');
                $table->index('student_id', 'fee_charges_student_idx');
            });
        }
        if (!Schema::hasTable('fee_payouts')) {
            Schema::create('fee_payouts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('campus_id');
                $table->string('category', 16);
                $table->string('title', 60);
                $table->unsignedInteger('amount');
                $table->date('paid_on');
                $table->unsignedBigInteger('created_by');
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 100)->nullable();
                $table->timestamps();
                $table->index(['campus_id', 'paid_on'], 'fee_payouts_campus_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payouts');
        Schema::dropIfExists('fee_charges');
    }
};
