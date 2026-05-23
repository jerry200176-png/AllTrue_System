<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdoptionInsightsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_tracker_returns_sla_summary_meta(): void
    {
        $token = $this->createDirectorToken([1], 'adoption-director@example.com');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/adoption/task-tracker?branch_id=1');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => [
                'branch_id',
                'generated_at',
                'count',
                'summary' => [
                    'due_total',
                    'breached_total',
                    'warning_total',
                    'blocked_total',
                    'done_total',
                ],
            ],
        ]);
    }

    public function test_weekly_metrics_returns_daily_summary_and_comparison(): void
    {
        $token = $this->createDirectorToken([1], 'adoption-weekly@example.com');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/adoption/weekly-metrics?branch_id=1');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'window' => ['start', 'end'],
                'workflow_daily' => ['due_total', 'done_total', 'breached_total', 'as_of'],
                'comparison' => [
                    'previous_window' => ['start', 'end'],
                    'teacher_open_rate_pct',
                    'director_open_rate_pct',
                    'delta_teacher_open_rate_pct',
                    'delta_director_open_rate_pct',
                ],
                'parent_feedback_reply_rate_pct',
                'parent_feedback_unread_backlog',
                'bug_reopen_rate_pct',
                'p1p0_median_lead_hours',
                'trust_contract_backlog',
            ],
            'meta' => ['branch_id', 'generated_at'],
        ]);
    }

    public function test_cross_branch_metrics_requires_super_admin_role(): void
    {
        $directorToken = $this->createDirectorToken([1], 'adoption-director-role-check@example.com');

        $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/adoption/cross-branch-metrics')
            ->assertForbidden();

        $superAdminToken = $this->createUserToken([], 'adoption-super-admin@example.com', 'S');
        $this->withHeaders([
            'Authorization' => "Bearer {$superAdminToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/adoption/cross-branch-metrics')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'super_admin_all_branches');
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        return $this->createUserToken($campusIds, $loginName, 'A');
    }

    private function createUserToken(array $campusIds, string $loginName, string $type): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'Adoption 測試主任',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => 923456789,
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

