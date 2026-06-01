<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parent opt-out flag for learning-feedback LINE notifications (個資退出權).
 * Default 1 = opted in (per owner decision). Parent can disable via ParentPortal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_line_bindings', function (Blueprint $table) {
            $table->boolean('notify_learning_feedback')->default(true)->after('campus_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_line_bindings', function (Blueprint $table) {
            $table->dropColumn('notify_learning_feedback');
        });
    }
};
