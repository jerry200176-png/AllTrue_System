<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ParentSession')) {
            return;
        }
        Schema::create('ParentSession', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('StudentID');
            $table->string('TokenHash', 64);
            $table->dateTime('ExpiresAt');
            $table->timestamps();
            $table->index('TokenHash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ParentSession');
    }
};
