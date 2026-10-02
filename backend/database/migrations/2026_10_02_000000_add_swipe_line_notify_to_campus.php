<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 刷卡到班 LINE 通知改成分校開關；已設 LINE 頻道的分校預設開，避免上線瞬間停推。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            $table->boolean('swipe_line_notify')->default(false)->after('messaging_channel_secret');
        });

        DB::table('Campus')
            ->whereNotNull('messaging_channel_token')
            ->where('messaging_channel_token', '!=', '')
            ->update(['swipe_line_notify' => true]);
    }

    public function down(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            $table->dropColumn('swipe_line_notify');
        });
    }
};
