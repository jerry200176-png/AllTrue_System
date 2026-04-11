<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('User', function (Blueprint $table) {
            $table->unsignedBigInteger('bug_inbox_last_seen_bug_id')->nullable()->after('bug_inbox_last_seen_at');
        });
    }

    public function down()
    {
        Schema::table('User', function (Blueprint $table) {
            $table->dropColumn('bug_inbox_last_seen_bug_id');
        });
    }
};
