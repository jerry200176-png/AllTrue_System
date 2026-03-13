<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('TempRfid')) {
            return;
        }
        Schema::create('TempRfid', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('CampusID');
            $table->string('RFID', 32);
            $table->dateTime('created_at')->useCurrent();
            $table->unique('CampusID');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TempRfid');
    }
};
