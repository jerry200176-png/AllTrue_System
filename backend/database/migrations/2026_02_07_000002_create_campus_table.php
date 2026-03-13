<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Campus')) {
            return;
        }
        Schema::create('Campus', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 32);
            $table->integer('Current')->default(0);
            $table->string('LineNotifyID', 128);
            $table->string('Client_ID', 32);
            $table->string('Client_Secret', 64);
            $table->string('LIFFID', 32);
            $table->string('LIFF_URL', 64);
            $table->string('URL', 64);
            $table->string('Token', 64)->nullable();
            $table->string('TelegramToken', 64)->nullable();
            $table->string('TelegramChatID', 32)->nullable();
            $table->string('TelegramURL', 64);
            $table->string('TeachLIFFID', 64);
            $table->string('TeachLIFF_URL', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Campus');
    }
};
