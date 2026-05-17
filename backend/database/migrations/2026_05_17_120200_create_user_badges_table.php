<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('badge_key', 50);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'badge_key']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
