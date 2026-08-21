<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('question_banks')) {
            Schema::create('question_banks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('campus_id');
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('status', 24)->default('draft');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['campus_id', 'status'], 'question_banks_campus_status_idx');
                $table->index('created_by_user_id', 'question_banks_creator_idx');
            });
        }

        if (!Schema::hasTable('question_bank_items')) {
            Schema::create('question_bank_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('question_bank_id');
                $table->uuid('question_key');
                $table->unsignedInteger('version_no');
                $table->string('question_type', 24);
                $table->text('prompt');
                $table->json('choices')->nullable();
                $table->json('answer')->nullable();
                $table->text('explanation')->nullable();
                $table->string('knowledge_tag', 120);
                $table->unsignedTinyInteger('difficulty');
                $table->string('source_type', 24)->default('internal');
                $table->string('source_ref', 255)->nullable();
                $table->string('status', 24)->default('draft');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamps();

                $table->unique(['question_key', 'version_no'], 'question_items_key_version_unique');
                $table->index(['question_bank_id', 'status'], 'question_items_bank_status_idx');
                $table->index(['question_bank_id', 'knowledge_tag'], 'question_items_bank_tag_idx');
            });
        }

        if (!Schema::hasTable('question_bank_audit_logs')) {
            Schema::create('question_bank_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('question_bank_id');
                $table->unsignedBigInteger('question_bank_item_id')->nullable();
                $table->unsignedInteger('campus_id');
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('action', 32);
                $table->text('reason')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamps();

                $table->index(['question_bank_id', 'created_at'], 'question_bank_audit_bank_created_idx');
                $table->index(['question_bank_item_id', 'created_at'], 'question_bank_audit_item_created_idx');
                $table->index(['campus_id', 'created_at'], 'question_bank_audit_campus_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank_audit_logs');
        Schema::dropIfExists('question_bank_items');
        Schema::dropIfExists('question_banks');
    }
};
