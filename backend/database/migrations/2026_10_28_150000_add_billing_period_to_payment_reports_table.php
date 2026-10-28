<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// #4574 (in-app #432): the month a monthly 登記收款 was checked against, so confirm can refuse if it moved.
// Additive only: nullable, no default, no backfill (older pending reports keep the confirm-time rule).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reports', function (Blueprint $table) {
            $table->char('billing_period', 7)->nullable()->after('InvoiceID');
        });
    }

    public function down(): void
    {
        Schema::table('payment_reports', function (Blueprint $table) {
            $table->dropColumn('billing_period');
        });
    }
};
