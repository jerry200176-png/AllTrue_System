<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Teacher')) {
            return;
        }
        Schema::create('Teacher', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('CampusID');
            $table->string('T_Name', 32);
            $table->string('Phone', 12)->nullable();
            $table->string('RFID', 16)->nullable();
            $table->string('LineID', 64)->nullable();
            $table->string('TelegramID', 32)->default('');
            $table->string('TelegramID1', 32)->nullable();
            $table->string('TelegramID2', 32)->nullable();
            $table->string('Notify_Token', 64)->nullable();
            $table->integer('Enable')->default(1);
            $table->dateTime('MDT')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Teacher');
    }
};
