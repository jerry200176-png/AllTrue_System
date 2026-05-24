<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug #129 regression guard: rate_unit calculation sanity.
 *
 * Verifies:
 * 1. StudentClass with rate_unit='session' → Charge = Rate × SessionCount.
 * 2. StudentClass with rate_unit='hour' → Charge = Rate × totalHours (SessionCount × avg_hours).
 */
class RateUnitChargeCalculationTest extends TestCase
{
    use RefreshDatabase;

    /** rate_unit=session: Charge = Rate × SessionCount */
    public function test_session_rate_unit_charge_is_rate_times_sessions(): void
    {
        [$token, $campus, $teacher] = $this->makeFixture();

        $student = Student::create([
            'name' => 'Rate-Test-A-' . uniqid(),
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'SchoolName' => 'TestSchool',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

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

    /** rate_unit=hour: Charge = Rate × (sessions × avg_hours_per_session) */
    public function test_hour_rate_unit_charge_is_rate_times_hours(): void
    {
        [$token, $campus, $teacher] = $this->makeFixture();

        $student = Student::create([
            'name' => 'Rate-Test-B-' . uniqid(),
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'SchoolName' => 'TestSchool',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

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
    private function makeFixture(): array
    {
        $campus = CampusFactory::new()->create();
        $teacher = User::create([
            'LoginName' => 'teacher-rate-' . uniqid() . '@test.com',
            'Name' => '老師', 'PSW' => 'x', 'type' => 'T',
            'phone' => '091' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $director = User::create([
            'LoginName' => 'dir-rate-' . uniqid() . '@test.com',
            'Name' => '主任', 'PSW' => 'x', 'type' => 'A',
            'phone' => '092' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $raw, 'expires_at' => now()->addHours(2)]);
        return [$raw, $campus, $teacher];
    }
}
