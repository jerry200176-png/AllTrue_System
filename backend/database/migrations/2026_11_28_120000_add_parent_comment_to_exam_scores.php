<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: the teacher's per-subject words to the parent on a 段考 score (老師的話). One nullable column,
 * no default, no index, no backfill. `notes` stays internal.
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('exam_scores', 'parent_comment')) {
            return;
        }
        Schema::table('exam_scores', function (Blueprint $table) {
            $table->string('parent_comment', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('exam_scores', 'parent_comment')) {
            return;
        }
        Schema::table('exam_scores', function (Blueprint $table) {
            $table->dropColumn('parent_comment');
        });
    }
};
