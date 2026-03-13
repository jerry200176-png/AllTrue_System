<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Student')) {
            return;
        }
        Schema::create('Student', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 32);
            $table->integer('CampusID');
            $table->integer('ClassID');
            $table->string('SchoolName', 32)->nullable();
            $table->string('RFID', 16)->nullable();
            $table->string('LineID', 64)->nullable();
            $table->string('TelegramID', 32)->default('');
            $table->string('TelegramID1', 32)->nullable();
            $table->string('TelegramID2', 32)->nullable();
            $table->integer('enable')->default(1);
            $table->dateTime('MDT')->useCurrent();
            $table->string('Notify_Token', 64)->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Student');
    }
};
