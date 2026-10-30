<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** #3824 part 2 (Founder GO 2026-10-29): create-only; rollback drops the table. Existing tables untouched. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_record_author_snapshots')) {
            return;
        }

        Schema::create('learning_record_author_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('learning_record_id');
            $table->unsignedBigInteger('author_teacher_id')->nullable();
            $table->unsignedBigInteger('new_teacher_id');
            $table->string('status_at_snapshot', 32);
            $table->json('payload'); // body fields + AttachmentUrl (URL only, file not moved) + teacher_comment row copy
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['learning_record_id', 'created_at'], 'lr_author_snap_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_record_author_snapshots');
    }
};
