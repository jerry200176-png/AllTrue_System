<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('LearningRecord')) {
            return;
        }
        Schema::create('LearningRecord', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('StudentClassID');
            $table->bigInteger('ClassSessionID');
            $table->integer('TeacherID');
            $table->text('Content');
            $table->string('AttachmentUrl', 255)->nullable();
            $table->string('Status', 16)->default('pending');
            $table->integer('ApprovedBy')->nullable();
            $table->dateTime('ApprovedAt')->nullable();
            $table->timestamps();
            $table->unique('ClassSessionID');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LearningRecord');
    }
};
