<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            $table->text('messaging_channel_token')->nullable()->after('LIFF_URL');
            $table->string('messaging_channel_secret', 64)->nullable()->after('messaging_channel_token');
        });
    }

    public function down(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            $table->dropColumn(['messaging_channel_token', 'messaging_channel_secret']);
        });
    }
};
