<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ImportJob')) {
            return;
        }
        Schema::create('ImportJob', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Type', 32);
            $table->string('Status', 16)->default('pending');
            $table->string('FileName', 255);
            $table->string('Summary', 255)->default('');
            $table->text('ErrorLog')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ImportJob');
    }
};
