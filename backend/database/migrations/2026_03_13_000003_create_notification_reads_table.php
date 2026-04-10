<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('NotificationReads')) {
            return;
        }

        Schema::create('NotificationReads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('NotificationID');
            $table->unsignedInteger('UserID');
            $table->dateTime('ReadAt')->nullable();
            $table->dateTime('ArchivedAt')->nullable();
            $table->timestamps();

            $table->unique(['NotificationID', 'UserID'], 'notification_reads_unique');
            $table->index(['UserID', 'ReadAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('NotificationReads');
    }
};
