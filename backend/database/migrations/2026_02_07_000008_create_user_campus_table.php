<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('UserCampus')) {
            return;
        }
        Schema::create('UserCampus', function (Blueprint $table) {
            $table->integer('CampusID');
            $table->integer('UserID');
            $table->integer('Admin')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('UserCampus');
    }
};
