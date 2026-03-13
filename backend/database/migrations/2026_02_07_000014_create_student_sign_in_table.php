<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('StudentSingIn')) {
            return;
        }
        Schema::create('StudentSingIn', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('StudentClassID')->nullable();
            $table->integer('StudentID');
            $table->integer('TeacherID')->nullable();
            $table->integer('GradeID')->nullable();
            $table->integer('SubjectID')->nullable();
            $table->integer('Get1byID')->nullable();
            $table->integer('Hours')->nullable();
            $table->string('Memo', 512)->nullable();
            $table->dateTime('SignInDT');
            $table->dateTime('SignOutDT')->nullable();
            $table->dateTime('MDT')->useCurrent();
            $table->bigInteger('ClassSessionID')->nullable();
            $table->string('Status', 16)->default('present');
            $table->unique('ClassSessionID');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('StudentSingIn');
    }
};
