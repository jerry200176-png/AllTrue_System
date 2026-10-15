<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app #413 / #4154 (Founder): "the system confirmed the source problem is resolved" is a
 * different fact from "a user read it". Notifications.ResolvedAt already holds the first; this adds
 * who or what resolved it, plus an append-only processing record so a resolve → recur → resolve
 * history survives re-opening. Additive only: nullable columns and a new table, no backfill.
 * Rollback: down() drops exactly what up() added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Notifications', function (Blueprint $table) {
            $table->string('ResolvedBy', 64)->nullable();
            $table->string('ResolvedReason', 255)->nullable();
        });

        Schema::create('NotificationResolutionEvents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('NotificationID');
            $table->string('Event', 16);
            $table->string('Actor', 64);
            $table->string('Reason', 255)->nullable();
            $table->dateTime('OccurredAt');
            $table->timestamps();
            $table->index(['NotificationID', 'OccurredAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('NotificationResolutionEvents');
        Schema::table('Notifications', function (Blueprint $table) {
            $table->dropColumn(['ResolvedBy', 'ResolvedReason']);
        });
    }
};
