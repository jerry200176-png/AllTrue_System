<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable per-session SubjectID override to ClassSession.
 * When NULL, the session inherits the subject from its parent StudentClass.
 * ClassSessionController::index() uses COALESCE(cs.SubjectID, sc.SubjectID)
 * to always show the effective subject.
 */
class AddSubjectIdToClassSession extends Migration
{
    public function up(): void
    {
        Schema::table('ClassSession', function (Blueprint $table) {
            $table->bigInteger('SubjectID')->nullable()->after('StudentClassID');
            $table->index('SubjectID', 'class_session_subject_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ClassSession', function (Blueprint $table) {
            $table->dropIndex('class_session_subject_id_idx');
            $table->dropColumn('SubjectID');
        });
    }
}
