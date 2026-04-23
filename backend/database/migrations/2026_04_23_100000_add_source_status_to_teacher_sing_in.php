<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TeacherSingIn', function (Blueprint $table) {
            $table->enum('Source', ['rfid', 'manual'])->default('rfid')->after('MDT');
            $table->enum('Status', [
                'normal',
                'late',
                'early_leave',
                'missed',
                'adjusted',
                'pending_review',
                'source_only',
            ])->default('pending_review')->after('Source');

            $table->index(['CampusID', 'SignInDT'], 'idx_teacher_signin_campus_dt');
            $table->index(['TeacherID', 'SignInDT'], 'idx_teacher_signin_teacher_dt');
        });
    }

    public function down(): void
    {
        Schema::table('TeacherSingIn', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_signin_campus_dt');
            $table->dropIndex('idx_teacher_signin_teacher_dt');
            $table->dropColumn(['Source', 'Status']);
        });
    }
};
