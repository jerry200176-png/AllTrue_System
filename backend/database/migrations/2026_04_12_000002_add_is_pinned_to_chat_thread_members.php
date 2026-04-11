<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('chat_thread_members', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('left_at');
        });
    }

    public function down()
    {
        Schema::table('chat_thread_members', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });
    }
};
