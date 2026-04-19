<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBackfillNoteToPaymentReportsTable extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reports', function (Blueprint $table) {
            $table->string('backfill_note', 500)->nullable()->after('void_reason');
        });
    }

    public function down(): void
    {
        Schema::table('payment_reports', function (Blueprint $table) {
            $table->dropColumn('backfill_note');
        });
    }
}
