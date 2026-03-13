<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ClassSession')) {
            return;
        }
        Schema::create('ClassSession', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('StudentClassID');
            $table->date('SessionDate');
            $table->time('StartTime');
            $table->time('EndTime');
            $table->string('Status', 16)->default('scheduled');
            $table->string('Note', 255)->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ClassSession');
    }
};
