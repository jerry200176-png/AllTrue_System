<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('campus_id');
            $table->date('transaction_date');
            $table->integer('amount');
            $table->string('reference', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('bank_name', 50)->nullable();
            $table->unsignedBigInteger('matched_payment_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedInteger('reconciled_by')->nullable();
            $table->string('status', 20)->default('unmatched');
            $table->timestamps();
            $table->index(['campus_id', 'status']);
            $table->index(['transaction_date']);
            $table->unique(['campus_id', 'transaction_date', 'amount', 'reference'], 'bank_txn_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
