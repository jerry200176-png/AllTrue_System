<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_tokens')) {
            return;
        }
        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('token', 64)->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_tokens');
    }
};
