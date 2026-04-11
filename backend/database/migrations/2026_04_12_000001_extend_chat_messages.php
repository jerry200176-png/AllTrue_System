<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // 軟刪除（手動維護，ChatMessage 無 timestamps）
            $table->timestamp('deleted_at')->nullable()->after('created_at');
            // 附件
            $table->string('media_url', 500)->nullable()->after('deleted_at');
            $table->string('media_type', 50)->nullable()->after('media_url');
            $table->string('media_name', 255)->nullable()->after('media_type');
            // 回覆引用（不加 FK，避免軟刪除訊息造成 constraint 問題）
            $table->unsignedBigInteger('reply_to_message_id')->nullable()->after('media_name');

            $table->index('reply_to_message_id');
            $table->index('deleted_at');
        });
    }

    public function down()
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['reply_to_message_id']);
            $table->dropIndex(['deleted_at']);
            $table->dropColumn([
                'deleted_at', 'media_url', 'media_type', 'media_name', 'reply_to_message_id',
            ]);
        });
    }
};
