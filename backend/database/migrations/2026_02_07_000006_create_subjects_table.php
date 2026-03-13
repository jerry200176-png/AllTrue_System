<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Subject')) {
            return;
        }
        Schema::create('Subject', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('School_id');
            $table->integer('Grade_no');
            $table->string('Subject_Name', 16);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Subject');
    }
};
