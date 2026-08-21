<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('question_bank_items')) {
            return;
        }

        Schema::table('question_bank_items', function (Blueprint $table) {
            $table->string('source_question_key', 191)->nullable()->after('question_key');
            $table->string('source_name', 120)->nullable()->after('source_type');
            $table->string('source_version', 120)->nullable()->after('source_name');
            $table->string('grade_level', 60)->nullable()->after('source_version');
            $table->string('subject_name', 120)->nullable()->after('grade_level');
            $table->string('license_ref', 255)->nullable()->after('source_ref');
            $table->index(['question_bank_id', 'source_name', 'source_question_key'], 'question_items_provenance_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('question_bank_items')) {
            return;
        }

        Schema::table('question_bank_items', function (Blueprint $table) {
            $table->dropIndex('question_items_provenance_idx');
            $table->dropColumn([
                'source_question_key', 'source_name', 'source_version', 'grade_level',
                'subject_name', 'license_ref',
            ]);
        });
    }
};
