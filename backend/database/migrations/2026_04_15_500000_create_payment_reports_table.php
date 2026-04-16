<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_reports')) {
            return;
        }

        Schema::create('payment_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('StudentID');
            $table->unsignedBigInteger('StudentClassID');
            $table->unsignedBigInteger('InvoiceID')->nullable();
            $table->string('reported_by_name', 64);
            $table->date('payment_date');
            $table->string('payment_method', 16)->default('transfer');
            $table->decimal('reported_amount', 10, 2);
            $table->string('account_last5', 5)->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->string('report_token_hash', 128);
            $table->timestamp('token_expires_at');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamps();

            $table->index('StudentID');
            $table->index('StudentClassID');
            $table->index('status');
            $table->index('report_token_hash');
        });

        if (!Schema::hasColumn('Payment', 'receipt_url')) {
            Schema::table('Payment', function (Blueprint $table) {
                $table->string('receipt_url', 255)->nullable()->after('Note');
                $table->unsignedBigInteger('payment_report_id')->nullable()->after('receipt_url');
            });
        }

        if (!Schema::hasColumn('Invoice', 'reconciled_at')) {
            Schema::table('Invoice', function (Blueprint $table) {
                $table->timestamp('reconciled_at')->nullable()->after('Note');
                $table->unsignedBigInteger('reconciled_by')->nullable()->after('reconciled_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reports');

        if (Schema::hasColumn('Payment', 'receipt_url')) {
            Schema::table('Payment', function (Blueprint $table) {
                $table->dropColumn(['receipt_url', 'payment_report_id']);
            });
        }

        if (Schema::hasColumn('Invoice', 'reconciled_at')) {
            Schema::table('Invoice', function (Blueprint $table) {
                $table->dropColumn(['reconciled_at', 'reconciled_by']);
            });
        }
    }
};
