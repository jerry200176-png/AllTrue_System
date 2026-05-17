<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_feedback_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feedback_id');
            $table->unsignedInteger('user_id');
            $table->string('replier_role', 20);
            $table->text('content');
            $table->timestamps();
            $table->index('feedback_id');
        });

        if (!Schema::hasColumn('parent_feedback', 'teacher_id')) {
            Schema::table('parent_feedback', function (Blueprint $table) {
                $table->unsignedInteger('teacher_id')->nullable()->after('campus_id');
                $table->timestamp('read_at')->nullable()->after('is_read');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_feedback_replies');
        if (Schema::hasColumn('parent_feedback', 'teacher_id')) {
            Schema::table('parent_feedback', function (Blueprint $table) {
                $table->dropColumn(['teacher_id', 'read_at']);
            });
        }
    }
};
