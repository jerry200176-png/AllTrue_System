<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bug_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bug_report_id');
            $table->string('stored_path', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('bug_report_id');

            $table->foreign('bug_report_id')->references('id')->on('bug_reports')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bug_report_attachments');
    }
};
