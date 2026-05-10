<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_engagement_xp_events')) {
            return;
        }

        Schema::create('user_engagement_xp_events', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedInteger('user_id');
            $table->string('event_type', 64);
            $table->integer('xp_delta');
            $table->unsignedBigInteger('learning_record_id')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('learning_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_engagement_xp_events');
    }
};
