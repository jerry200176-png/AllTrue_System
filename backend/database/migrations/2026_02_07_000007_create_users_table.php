<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('User')) {
            return;
        }
        Schema::create('User', function (Blueprint $table) {
            $table->increments('id');
            $table->string('LoginName', 64);
            $table->string('Name', 32);
            $table->string('PSW', 32);
            $table->char('type', 1)->default('U');
            $table->integer('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('User');
    }
};
