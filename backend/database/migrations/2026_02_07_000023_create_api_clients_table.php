<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ApiClient')) {
            return;
        }
        Schema::create('ApiClient', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Name', 64);
            $table->string('ApiKeyHash', 64);
            $table->integer('CampusID');
            $table->boolean('Active')->default(true);
            $table->timestamps();
            $table->unique('ApiKeyHash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ApiClient');
    }
};
