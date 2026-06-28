<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            if (!Schema::hasColumn('Campus', 'TelegramWebhookSecret')) {
                $table->string('TelegramWebhookSecret', 256)->nullable()->after('TelegramChatID');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            if (Schema::hasColumn('Campus', 'TelegramWebhookSecret')) {
                $table->dropColumn('TelegramWebhookSecret');
            }
        });
    }
};
