<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('InvoiceItem', function (Blueprint $table) {
            $table->unsignedBigInteger('StudentClassID')->nullable()->after('InvoiceID');
            $table->index('StudentClassID');
        });
    }

    public function down(): void
    {
        Schema::table('InvoiceItem', function (Blueprint $table) {
            $table->dropIndex(['StudentClassID']);
            $table->dropColumn('StudentClassID');
        });
    }
};
