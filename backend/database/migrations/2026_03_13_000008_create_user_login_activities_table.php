<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_login_activities')) {
            return;
        }

        Schema::create('user_login_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_label', 120)->nullable();
            $table->boolean('success')->default(true);
            $table->unsignedBigInteger('auth_token_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'login_at']);
            $table->index('auth_token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_activities');
    }
};
