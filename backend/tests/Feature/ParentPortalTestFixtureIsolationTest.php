<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentPortalTestFixtureIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $type): string
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . Str::lower(Str::random(8)) . '@test.invalid',
            'Name' => 'TEST ' . $type,
            'PSW' => bcrypt('test-only'),
            'type' => $type,
            'status' => 'active',
            'MustChangePassword' => false,
        ]);
        $raw = Str::random(48);
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $raw,
            'expires_at' => now()->addDay(),
        ]);

        return $raw;
    }

    private function fixtureToken(): string
    {
        return $this->token('S');
    }

    public function test_fixture_path_is_unauthenticated_and_super_admin_only(): void
    {
        $this->postJson('/api/v1/admin/qa/parent-fixture/session')->assertUnauthorized();
        $this->withToken($this->token('T'))
            ->postJson('/api/v1/admin/qa/parent-fixture/session')
            ->assertForbidden();
        $this->withToken($this->token('A'))
            ->postJson('/api/v1/admin/qa/parent-fixture/session')
            ->assertForbidden();
    }

    public function test_fixture_is_idempotent_and_parent_session_can_read_portal(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->fixtureToken()];

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/admin/qa/parent-fixture/session')
            ->assertOk()
            ->json();
        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/admin/qa/parent-fixture/session')
            ->assertOk()
            ->json();

        $this->assertSame($first['fixture']['campus_id'], $second['fixture']['campus_id']);
        $this->assertSame($first['fixture']['student_id'], $second['fixture']['student_id']);
        $this->assertSame($first['fixture']['guardian_id'], $second['fixture']['guardian_id']);
        $this->assertNotSame($first['session']['parent_session_id'], $second['session']['parent_session_id']);
        $this->assertSame(1, DB::table('Campus')->where('is_test', true)->count());
        $this->assertSame(1, DB::table('Student')->where('CampusID', $first['fixture']['campus_id'])->count());
        $this->assertSame(1, DB::table('guardians')->count());
        $this->assertSame(1, DB::table('student_guardians')->count());
        $this->assertSame(1, DB::table('ParentSession')->where('StudentID', $first['fixture']['student_id'])->count());
        $this->assertDatabaseHas('guardians', [
            'id' => $second['fixture']['guardian_id'],
            'phone' => null,
            'phone_normalized' => null,
            'line_user_id' => null,
        ]);
        $this->assertDatabaseHas('student_guardians', [
            'id' => $second['fixture']['student_guardian_id'],
            'notify_learning_feedback' => 0,
            'notify_tuition' => 0,
        ]);

        $parentHeaders = ['Authorization' => 'Bearer ' . $second['session']['token']];
        $this->withHeaders($parentHeaders)
            ->getJson('/api/v1/parent/dashboard')
            ->assertOk()
            ->assertJsonPath('student.id', $second['fixture']['student_id']);
    }

    public function test_test_rows_are_hidden_from_public_and_operational_surfaces(): void
    {
        $superHeaders = ['Authorization' => 'Bearer ' . $this->fixtureToken()];
        $fixture = $this->withHeaders($superHeaders)
            ->postJson('/api/v1/admin/qa/parent-fixture')
            ->assertOk()
            ->json('fixture');

        $this->assertSame(0, Campus::query()->whereKey($fixture['campus_id'])->count());
        $this->assertSame(0, Student::query()->whereKey($fixture['student_id'])->count());
        $this->assertSame(1, Campus::withoutGlobalScopes()->whereKey($fixture['campus_id'])->count());
        $this->assertSame(1, Student::withoutGlobalScopes()->whereKey($fixture['student_id'])->count());

        $branches = $this->getJson('/api/v1/branches')->assertOk()->json();
        $this->assertNotContains($fixture['campus_id'], collect($branches)->pluck('id')->all());

        $students = $this->withHeaders($superHeaders)
            ->getJson('/api/v1/students?per_page=all')
            ->assertOk()
            ->json();
        $studentRows = $students['data'] ?? $students;
        $this->assertNotContains($fixture['student_id'], collect($studentRows)->pluck('id')->all());

        $login = $this->postJson('/api/v1/parent/login', [
            'StudentID' => $fixture['student_id'],
            'Phone' => '0900000000',
        ]);
        $login->assertStatus(404);
    }

    public function test_fixture_has_no_operational_children_or_external_identity(): void
    {
        $fixture = $this->withToken($this->fixtureToken())
            ->postJson('/api/v1/admin/qa/parent-fixture')
            ->assertOk()
            ->json('fixture');

        $this->assertDatabaseCount('StudentClass', 0);
        $this->assertDatabaseCount('ClassSession', 0);
        $this->assertDatabaseCount('Invoice', 0);
        $this->assertDatabaseCount('student_line_bindings', 0);
        $this->assertDatabaseHas('Campus', ['id' => $fixture['campus_id'], 'is_test' => 1]);
        $this->assertDatabaseHas('Student', ['id' => $fixture['student_id'], 'CampusID' => $fixture['campus_id']]);
        $this->assertDatabaseHas('student_guardians', [
            'id' => $fixture['student_guardian_id'],
            'student_id' => $fixture['student_id'],
            'guardian_id' => $fixture['guardian_id'],
        ]);
    }
}
