<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remap legacy Subject IDs that no longer exist in the Subject table.
 *
 * The old system had a different Subject table with IDs like 1, 14, 15, 21.
 * After the table was recreated the canonical IDs start at 64. This migration
 * updates StudentClass and StudentSingIn so their SubjectID values point to
 * the correct rows, restoring subject-name resolution everywhere in the app.
 *
 * Mapping (verified via LearningRecord.Subject text field):
 *   1  -> 68 (Chemistry)
 *  14  -> 65 (English)
 *  15  -> 66 (Math)
 *  21  -> 69 (Science / 理化)
 */
return new class extends Migration
{
    private array $mapping = [
        1  => 68, // Chemistry
        14 => 65, // English
        15 => 66, // Math
        21 => 69, // Science
    ];

    public function up(): void
    {
        foreach ($this->mapping as $oldId => $newId) {
            if (Schema::hasTable('StudentClass')) {
                DB::table('StudentClass')
                    ->where('SubjectID', $oldId)
                    ->update(['SubjectID' => $newId]);
            }
            if (Schema::hasTable('StudentSingIn')) {
                DB::table('StudentSingIn')
                    ->where('SubjectID', $oldId)
                    ->update(['SubjectID' => $newId]);
            }
        }
    }

    public function down(): void
    {
        $reverse = array_flip($this->mapping);
        foreach ($reverse as $newId => $oldId) {
            if (Schema::hasTable('StudentClass')) {
                DB::table('StudentClass')
                    ->where('SubjectID', $newId)
                    ->update(['SubjectID' => $oldId]);
            }
            if (Schema::hasTable('StudentSingIn')) {
                DB::table('StudentSingIn')
                    ->where('SubjectID', $newId)
                    ->update(['SubjectID' => $oldId]);
            }
        }
    }
};
