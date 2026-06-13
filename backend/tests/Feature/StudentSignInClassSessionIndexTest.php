<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #804 regression guard: StudentSingIn must keep an index on ClassSessionID.
 *
 * Without it, ClassSessionController::index()'s `si` (latest sign-in) derived
 * table is full-scanned per outer row (production ANALYZE: ~33.5s, ~20.6M row
 * reads). LearningRecord already has the equivalent index. This test is
 * revert-proof: drop the migration and it fails.
 */
class StudentSignInClassSessionIndexTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function student_sign_in_has_index_on_class_session_id(): void
    {
        $rows = DB::select("SHOW INDEX FROM StudentSingIn WHERE Column_name = 'ClassSessionID'");

        $this->assertNotEmpty(
            $rows,
            'StudentSingIn 必須有 ClassSessionID 索引（#804 效能）：缺索引時 class-sessions 端點 si derived table 會全表掃描。'
        );
    }
}
