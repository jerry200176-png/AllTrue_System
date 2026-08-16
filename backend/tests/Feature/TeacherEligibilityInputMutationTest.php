<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherEligibilityInputMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_update_and_withdraw_pending_holiday_event(): void
    {
        $token = $this->createDirectorToken([1], 'elig-director@test.com');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $created = $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/events', [
            'branch_id' => 1,
            'event_date' => '2026-08-31',
            'event_type' => 'holiday',
            'evidence' => '國曆假日',
        ]);
        $created->assertCreated();
        $id = (int) $created->json('id');

        $this->withHeaders($headers)->putJson("/api/v1/finance/teacher-eligibility/events/{$id}", [
            'branch_id' => 1,
            'event_date' => '2026-08-31',
            'event_type' => 'official_closure',
            'evidence' => '改成統一公休',
        ])->assertOk()->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('teacher_payroll_events', [
            'id' => $id,
            'event_type' => 'official_closure',
            'status' => 'pending',
        ]);

        $this->withHeaders($headers)->postJson("/api/v1/finance/teacher-eligibility/events/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('status', 'withdrawn');

        $this->withHeaders($headers)->postJson("/api/v1/finance/teacher-eligibility/events/{$id}/approve")
            ->assertStatus(422);
    }

    public function test_approved_event_cannot_be_edited(): void
    {
        $token = $this->createDirectorToken([1], 'elig-director-2@test.com');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $created = $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/events', [
            'branch_id' => 1,
            'event_date' => '2026-08-31',
            'event_type' => 'holiday',
        ])->assertCreated();
        $id = (int) $created->json('id');
        $this->withHeaders($headers)->postJson("/api/v1/finance/teacher-eligibility/events/{$id}/approve")->assertOk();

        $this->withHeaders($headers)->putJson("/api/v1/finance/teacher-eligibility/events/{$id}", [
            'branch_id' => 1,
            'event_date' => '2026-08-30',
            'event_type' => 'holiday',
        ])->assertStatus(422);

        $this->withHeaders($headers)->postJson("/api/v1/finance/teacher-eligibility/events/{$id}/withdraw")
            ->assertStatus(422);
    }

    /** @param  array<int>  $campusIds */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName, 'Name' => '主任測試', 'PSW' => 'secret', 'type' => 'A',
            'phone' => '0912345678', 'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
