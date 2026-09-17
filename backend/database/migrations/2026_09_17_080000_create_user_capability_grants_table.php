<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_capability_grants')) {
            return;
        }

        Schema::create('user_capability_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // director | teacher
            $table->string('capability', 32);
            $table->unsignedInteger('campus_id');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->unique(['user_id', 'capability', 'campus_id'], 'user_capability_campus_unique');
            $table->index(['user_id', 'capability']);
            $table->index(['campus_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capability_grants');
    }
};
