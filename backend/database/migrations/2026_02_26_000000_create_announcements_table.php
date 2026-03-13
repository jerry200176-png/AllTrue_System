<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Announcements', function (Blueprint $table) {
            $table->id();
            $table->string('Title', 100);
            $table->text('Content');
            $table->integer('BranchID')->nullable();
            $table->integer('TargetStudentID')->nullable();
            $table->boolean('IsActive')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Announcements');
    }
};
