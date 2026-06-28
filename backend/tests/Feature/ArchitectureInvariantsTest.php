<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Automated enforcement of domain invariants (docs/RULE_ARCHITECTURE_GOVERNANCE.md §3).
 * Each test name carries its DI-n tag; scripts/arch-fitness-check.mjs FIT-12 verifies the
 * tag is present so an invariant can never silently lose its enforcement.
 */
class ArchitectureInvariantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DI-5: at most one SessionRecord (LearningRecord) per delivered Occurrence.
     * Enforced at the database layer by unique(ClassSessionID). This test fails if that
     * structural guarantee is ever removed.
     */
    public function test_DI_5_one_learning_record_per_occurrence_is_db_enforced(): void
    {
        $cs = ClassSession::create([
            'StudentClassID' => 1,
            'SessionDate' => '2026-03-02',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        LearningRecord::create([
            'StudentClassID' => 1,
            'ClassSessionID' => $cs->id,
            'TeacherID' => 1,
            'Content' => '',
            'Status' => 'pending',
        ]);

        $this->expectException(QueryException::class); // unique(ClassSessionID) must reject the duplicate
        LearningRecord::create([
            'StudentClassID' => 1,
            'ClassSessionID' => $cs->id,
            'TeacherID' => 1,
            'Content' => '',
            'Status' => 'pending',
        ]);
    }
}
