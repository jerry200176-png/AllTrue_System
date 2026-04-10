<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Notifications')) {
            return;
        }

        Schema::create('Notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('CampusID')->nullable();
            $table->string('Type', 32);
            $table->string('Severity', 16)->default('info');
            $table->string('Title', 191);
            $table->text('Body')->nullable();
            $table->string('SourceType', 64)->nullable();
            $table->string('SourceID', 64)->nullable();
            $table->string('SourceKey', 191)->unique();
            $table->json('Payload')->nullable();
            $table->dateTime('OccurredAt')->nullable();
            $table->dateTime('ResolvedAt')->nullable();
            $table->timestamps();

            $table->index(['CampusID', 'Type']);
            $table->index(['ResolvedAt', 'OccurredAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Notifications');
    }
};
