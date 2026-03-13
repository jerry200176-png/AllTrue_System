<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('PendingSwipe')) {
            return;
        }
        Schema::create('PendingSwipe', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('RFID', 32);
            $table->integer('StudentID')->nullable();
            $table->integer('CampusID')->nullable();
            $table->dateTime('SwipeAt');
            $table->string('Reason', 64);
            $table->text('Payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PendingSwipe');
    }
};
