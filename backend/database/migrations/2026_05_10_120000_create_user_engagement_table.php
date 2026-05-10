<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_engagement')) {
            return;
        }

        Schema::create('user_engagement', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->string('role_track', 32);
            $table->string('rank_key', 64)->default('private_second');
            $table->unsignedInteger('xp_total')->default(0);
            $table->boolean('rank_display_opt_out')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_engagement');
    }
};
