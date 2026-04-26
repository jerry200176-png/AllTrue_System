<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_feedback', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedInteger('campus_id');
            $table->string('category', 32)->default('other');
            $table->tinyInteger('rating')->nullable();
            $table->text('content');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['campus_id', 'is_read']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_feedback');
    }
};
