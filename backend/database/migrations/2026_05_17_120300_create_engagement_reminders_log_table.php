<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_reminders_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('channel', 20);
            $table->string('template_key', 50);
            $table->timestamp('sent_at');
            $table->index(['user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_reminders_log');
    }
};
