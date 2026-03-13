<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('InvoiceItem')) {
            return;
        }
        Schema::create('InvoiceItem', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('InvoiceID');
            $table->string('Description', 255);
            $table->integer('Amount');
            $table->date('PeriodStart')->nullable();
            $table->date('PeriodEnd')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('InvoiceItem');
    }
};
