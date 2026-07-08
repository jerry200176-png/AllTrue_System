<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                    'teacher_due_total',
                    'director_due_total',
                ],
                'mission_center' => [
                    'teacher' => ['remaining_total', 'breached_total', 'completion_hint'],
                    'director' => ['remaining_total', 'breached_total', 'completion_hint'],
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
                'activation_funnel' => [
                    'teacher' => ['opened_users', 'activated_users', 'activation_within_24h_pct'],
                    'director' => ['opened_users', 'activated_users', 'activation_within_24h_pct'],
                ],
            ],
            'meta' => ['branch_id', 'generated_at'],
        ]);
    }

    public function test_weekly_metrics_completion_rate_never_exceeds_100_percent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 12:00:00'));

        try {
            $campus = CampusFactory::new()->create();
            $token = $this->createDirectorToken([$campus->id], 'adoption-completion-rate@example.com');

            $teacher = User::create([
                'LoginName' => 'completion-teacher@example.com',
                'Name' => '完成率老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => 912345678,
            ]);
            UserCampus::create([
                'CampusID' => $campus->id,
                'UserID' => $teacher->id,
                'Admin' => 0,
                'Approved' => 1,
            ]);

            $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
            $scId = DB::table('StudentClass')->insertGetId([
                'StudentID' => $student->id,
                'TeacherID' => $teacher->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'ClassType' => 'one_on_one',
                'ScheduleMode' => 'count',
                'RemainingSessions' => 8,
                'UsedSessions' => 0,
                'SessionCount' => 8,
                'SessionDuration' => 60,
                'TotalHours' => 8,
                'Rate' => 400,
                'Charge' => 3200,
                'Pay' => 3200,
                'Paid' => 0,
                'Stop' => 0,
                'StartDate' => '2026-06-01',
                'Period' => 4,
                'by1' => $teacher->id,
                'MDate' => now(),
            ]);

            $sessionDate = '2026-06-05';
            $attendedCsId = DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $scId,
                'SessionDate' => $sessionDate,
                'StartTime' => '10:00',
                'EndTime' => '12:00',
                'Status' => 'attended',
            ]);
            $scheduledCsId = DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $scId,
                'SessionDate' => $sessionDate,
                'StartTime' => '14:00',
                'EndTime' => '16:00',
                'Status' => 'scheduled',
            ]);

            foreach ([$attendedCsId, $scheduledCsId] as $csId) {
                DB::table('LearningRecord')->insert([
                    'StudentID' => $student->id,
                    'StudentClassID' => $scId,
                    'ClassSessionID' => $csId,
                    'TeacherID' => $teacher->id,
                    'Subject' => 'Math',
                    'SessionDate' => $sessionDate,
                    'StartTime' => '10:00',
                    'EndTime' => '12:00',
                    'Content' => '課堂內容',
                    'Progress' => '授課進度',
                    'Status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/adoption/weekly-metrics?branch_id={$campus->id}")
                ->assertOk()
                ->assertJsonPath('data.attended_sessions', 1)
                ->assertJsonPath('data.learning_records_filled', 1)
                ->assertJsonPath('data.system_completion_rate_pct', 100);
        } finally {
            Carbon::setTestNow();
        }
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

