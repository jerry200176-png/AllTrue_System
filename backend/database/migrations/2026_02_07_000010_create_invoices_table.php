<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Invoice')) {
            return;
        }
        Schema::create('Invoice', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('StudentID');
            $table->bigInteger('StudentClassID')->nullable();
            $table->date('IssueDate');
            $table->date('DueDate')->nullable();
            $table->integer('TotalAmount');
            $table->integer('PaidAmount')->default(0);
            $table->string('Status', 16)->default('unpaid');
            $table->string('Note', 255)->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Invoice');
    }
};
