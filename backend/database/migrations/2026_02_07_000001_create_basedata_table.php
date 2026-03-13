<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('BaseData')) {
            return;
        }
        Schema::create('BaseData', function (Blueprint $table) {
            $table->increments('id');
            $table->string('Name', 32);
            $table->string('Val', 32);
            $table->integer('TypeID')->nullable();
            $table->integer('OrderID');
            $table->unique(['Name', 'Val']);
            $table->unique(['Name', 'Val', 'TypeID']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('BaseData');
    }
};
