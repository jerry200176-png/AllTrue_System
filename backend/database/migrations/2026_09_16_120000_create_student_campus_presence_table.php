<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('StudentCampusPresence')) {
            return;
        }
        Schema::create('StudentCampusPresence', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->unsignedBigInteger('CampusID'); $table->unsignedBigInteger('StudentID');
            $table->string('Source', 16)->default('rfid'); $table->string('DeviceID', 64)->nullable(); $table->string('RfidUidHash', 64)->nullable();
            $table->dateTime('ArrivedAt'); $table->dateTime('DepartedAt')->nullable(); $table->string('Status', 24)->default('open'); $table->string('CloseReason', 32)->nullable();
            $table->string('IdempotencyKey', 128); $table->dateTime('VoidedAt')->nullable(); $table->unsignedBigInteger('VoidedByUserID')->nullable(); $table->string('VoidReason', 255)->nullable();
            $table->unsignedTinyInteger('OpenSlotFlag')->nullable()->storedAs("IF(Status = 'open' AND DepartedAt IS NULL AND VoidedAt IS NULL, 1, NULL)");
            $table->timestamps();
            $table->unique('IdempotencyKey', 'scp_idempotency_unique'); $table->unique(['StudentID', 'CampusID', 'OpenSlotFlag'], 'scp_one_open_student_campus');
            $table->index(['CampusID', 'Status', 'ArrivedAt'], 'scp_campus_status_arrived'); $table->index(['StudentID', 'CampusID', 'Status'], 'scp_student_campus_status'); $table->index(['StudentID', 'ArrivedAt'], 'scp_student_arrived');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('StudentCampusPresence');
    }
};
