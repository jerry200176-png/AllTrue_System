<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('TeacherSingIn')) {
            return;
        }
        Schema::create('TeacherSingIn', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('TeacherID');
            $table->integer('CampusID');
            $table->dateTime('SignInDT');
            $table->dateTime('SignOutDT')->nullable();
            $table->dateTime('MDT')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TeacherSingIn');
    }
};
