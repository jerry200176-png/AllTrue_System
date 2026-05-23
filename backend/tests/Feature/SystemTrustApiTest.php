<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemTrustApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_summary_enforces_campus_isolation(): void
    {
        $token = $this->createDirectorToken([1], 'trust-director@example.com');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/system/trust-summary?branch_id=2')
            ->assertForbidden();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/system/trust-summary?branch_id=1')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'branch_id',
                    'generated_at',
                    'recent_improvements',
                    'known_issues',
                    'reliability_snapshot' => [
                        'open_bugs',
                        'high_priority_open_bugs',
                        'pending_discrepancies',
                        'pending_learning_reviews',
                        'health_status',
                    ],
                ],
            ]);
    }

    public function test_trust_summary_payload_is_sanitized_for_known_issues(): void
    {
        $token = $this->createDirectorToken([1], 'trust-sanitized@example.com');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/system/trust-summary?branch_id=1')
            ->assertOk()
            ->json('data.known_issues');

        $issues = is_array($response) ? $response : [];
        foreach ($issues as $issue) {
            $this->assertIsArray($issue);
            $this->assertArrayNotHasKey('student_name', $issue);
            $this->assertArrayNotHasKey('token', $issue);
            $this->assertArrayNotHasKey('stack_trace', $issue);
        }
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'Trust 測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 923456788,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}

