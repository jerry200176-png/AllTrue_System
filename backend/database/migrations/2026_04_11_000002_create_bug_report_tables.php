<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('CampusID');
            $table->unsignedBigInteger('reporter_user_id');
            $table->string('title', 200);
            $table->text('description');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['new', 'triaged', 'in_progress', 'resolved', 'closed'])->default('new');
            $table->string('page_key', 50)->nullable();
            $table->string('url', 500)->nullable();
            $table->text('client_info')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamps();

            $table->index(['CampusID', 'status', 'severity', 'created_at']);
            $table->index('reporter_user_id');
        });

        Schema::create('bug_report_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bug_report_id');
            $table->unsignedBigInteger('author_user_id');
            $table->text('body');
            $table->boolean('is_internal_note')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bug_report_id', 'id']);

            $table->foreign('bug_report_id')->references('id')->on('bug_reports')->onDelete('cascade');
        });

        Schema::create('bug_report_status_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bug_report_id');
            $table->unsignedBigInteger('changed_by');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bug_report_id', 'created_at']);

            $table->foreign('bug_report_id')->references('id')->on('bug_reports')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bug_report_status_logs');
        Schema::dropIfExists('bug_report_comments');
        Schema::dropIfExists('bug_reports');
    }
};
