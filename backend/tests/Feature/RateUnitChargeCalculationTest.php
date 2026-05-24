<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug #129 regression guard: rate_unit calculation sanity.
 *
 * Verifies:
 * 1. StudentClass with rate_unit='session' → Charge = Rate × SessionCount.
 * 2. StudentClass with rate_unit='hour' → Charge = Rate × totalHours (SessionCount × avg_hours).
 * 3. CourseEditForm behaviour: when rate_unit is submitted as 'session', backend stores 'session'.
 */
class RateUnitChargeCalculationTest extends TestCase
{
    use RefreshDatabase;

    /** rate_unit=session: Charge = Rate × SessionCount */
    public function test_session_rate_unit_charge_is_rate_times_sessions(): void
    {
        $campus   = \App\Models\Campus::factory()->create();
        $student  = \App\Models\Student::factory()->create(['CampusID' => $campus->id, 'SchoolName' => 'TestSchool']);
        $teacher  = \App\Models\User::factory()->create(['type' => 'T']);

        $token = $this->createDirectorToken($campus->id);

        // Create a course via API with rate_unit=session
        $response = $this->withToken($token)->postJson('/api/v1/student-classes', [
            'student_id'         => $student->id,
            'teacher_id'         => $teacher->id,
            'subject'            => 'Math',
            'grade_id'           => 7,
            'class_type'         => 'one_on_one',
            'rate'               => 1100,
            'rate_unit'          => 'session',
            'sessions'           => 8,
            'duration_minutes'   => 120,
            'branch_id'          => $campus->id,
            'day_of_week'        => 3,
            'start_time'         => '23:00',
        ]);

        $response->assertStatus(201);
        $sc = DB::table('StudentClass')->orderByDesc('ID')->first();
        $this->assertEquals('session', $sc->rate_unit, 'rate_unit should be stored as session');
        $this->assertEquals(8800, (int) $sc->Charge, 'Charge should be 1100 × 8 = 8800');
    }

    /** rate_unit=hour: Charge = Rate × (sessions × avg_hours/session) */
    public function test_hour_rate_unit_charge_is_rate_times_hours(): void
    {
        $campus  = \App\Models\Campus::factory()->create();
        $student = \App\Models\Student::factory()->create(['CampusID' => $campus->id, 'SchoolName' => 'TestSchool']);
        $teacher = \App\Models\User::factory()->create(['type' => 'T']);

        $token = $this->createDirectorToken($campus->id);

        $response = $this->withToken($token)->postJson('/api/v1/student-classes', [
            'student_id'       => $student->id,
            'teacher_id'       => $teacher->id,
            'subject'          => 'Math',
            'grade_id'         => 7,
            'class_type'       => 'one_on_one',
            'rate'             => 1100,
            'rate_unit'        => 'hour',
            'sessions'         => 8,
            'duration_minutes' => 120,
            'branch_id'        => $campus->id,
            'day_of_week'      => 3,
            'start_time'       => '23:00',
        ]);

        $response->assertStatus(201);
        $sc = DB::table('StudentClass')->orderByDesc('ID')->first();
        $this->assertEquals('hour', $sc->rate_unit, 'rate_unit should be stored as hour');
        // 8 sessions × 2 hours = 16 hours × 1100 = 17,600
        $this->assertEquals(17600, (int) $sc->Charge, 'Charge should be 1100 × 16 hours = 17600');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private function createDirectorToken(int $campusId): string
    {
        $director = \App\Models\User::factory()->create(['type' => 'A', 'status' => 'active']);
        \App\Models\UserCampus::create(['UserID' => $director->id, 'CampusID' => $campusId, 'Approved' => 1]);
        $raw   = \Illuminate\Support\Str::random(40);
        $token = \App\Models\AuthToken::create([
            'user_id'    => $director->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addHours(2),
        ]);
        return $raw;
    }
}
