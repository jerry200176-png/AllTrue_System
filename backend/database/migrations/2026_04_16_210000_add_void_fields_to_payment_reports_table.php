<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payment_reports', 'voided_by')) {
            Schema::table('payment_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('voided_by')->nullable()->after('rejection_note');
                $table->timestamp('voided_at')->nullable()->after('voided_by');
                $table->string('void_reason', 500)->nullable()->after('voided_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_reports', 'voided_by')) {
            Schema::table('payment_reports', function (Blueprint $table) {
                $table->dropColumn(['voided_by', 'voided_at', 'void_reason']);
            });
        }
    }
};
