<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('CampusID');
            $table->enum('type', ['dm', 'group'])->default('dm');
            $table->string('name', 100)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['CampusID', 'last_message_at']);
        });

        Schema::create('chat_thread_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['owner', 'member'])->default('member');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();

            $table->unique(['thread_id', 'user_id']);
            $table->index('user_id');

            $table->foreign('thread_id')->references('id')->on('chat_threads')->onDelete('cascade');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('sender_user_id');
            $table->string('sender_name_snapshot', 100);
            $table->text('body');
            $table->string('message_type', 20)->default('text');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['thread_id', 'id']);

            $table->foreign('thread_id')->references('id')->on('chat_threads')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_thread_members');
        Schema::dropIfExists('chat_threads');
    }
};
