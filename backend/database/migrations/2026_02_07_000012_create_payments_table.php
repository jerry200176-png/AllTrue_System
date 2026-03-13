<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Payment')) {
            return;
        }
        Schema::create('Payment', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('InvoiceID');
            $table->integer('Amount');
            $table->dateTime('PaidAt');
            $table->string('Method', 32)->default('cash');
            $table->string('Note', 255)->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Payment');
    }
};
