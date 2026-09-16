<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFID-1 (#2809): dedicated student campus-presence store.
 * Additive only — does not alter StudentSingIn or billing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('StudentCampusPresence')) {
            return;
        }

        Schema::create('StudentCampusPresence', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('CampusID');
            $table->unsignedBigInteger('StudentID');
            $table->string('Source', 16)->default('rfid'); // rfid|manual|system
            $table->string('DeviceID', 64)->nullable();
            $table->string('RfidUidHash', 64)->nullable();
            $table->dateTime('ArrivedAt');
            $table->dateTime('DepartedAt')->nullable();
            $table->string('Status', 24)->default('open'); // open|closed|orphan_closed|voided
            $table->string('CloseReason', 32)->nullable(); // swipe_out|orphan_job|manual|void
            $table->string('IdempotencyKey', 128);
            $table->dateTime('VoidedAt')->nullable();
            $table->unsignedBigInteger('VoidedByUserID')->nullable();
            $table->string('VoidReason', 255)->nullable();
            $table->timestamps();

            $table->unique('IdempotencyKey', 'scp_idempotency_unique');
            $table->index(['CampusID', 'Status', 'ArrivedAt'], 'scp_campus_status_arrived');
            $table->index(['StudentID', 'CampusID', 'Status'], 'scp_student_campus_status');
            $table->index(['StudentID', 'ArrivedAt'], 'scp_student_arrived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('StudentCampusPresence');
    }
};
