<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bug_report_user_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('bug_report_id');
            $table->timestamp('read_at')->useCurrent();

            $table->unique(['user_id', 'bug_report_id']);
            $table->index('bug_report_id');

            $table->foreign('bug_report_id')->references('id')->on('bug_reports')->onDelete('cascade');
        });

        Schema::table('User', function (Blueprint $table) {
            $table->timestamp('bug_inbox_last_seen_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('User', function (Blueprint $table) {
            $table->dropColumn('bug_inbox_last_seen_at');
        });
        Schema::dropIfExists('bug_report_user_reads');
    }
};
