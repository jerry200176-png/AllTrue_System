<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ScheduleDiscrepancy;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleDiscrepancyReadContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_list_has_paginated_rows_with_stable_types_and_campus_scope(): void
    {
        [$token, $director] = $this->makeUserToken(1, 'sd-read-list@test.com', 'A');
        $newer = $this->makeReport($director->id, [
            'student_name' => '小明',
            'session_date' => '2026-08-30',
            'status' => 'pending',
            'created_at' => Carbon::parse('2026-08-30 10:00:00'),
        ]);
        $this->makeReport($director->id, [
            'student_name' => '小華',
            'status' => 'resolved',
            'created_at' => Carbon::parse('2026-08-30 09:00:00'),
        ]);
        $this->makeReport($director->id, [
            'branch_id' => 2,
            'student_name' => '不應出現',
            'created_at' => Carbon::parse('2026-08-30 11:00:00'),
        ]);

        $response = $this->authJson(
            'GET',
            '/api/v1/schedule-discrepancies?branch_id=1&per_page=1',
            $token
        );

        $response->assertOk()
            ->assertJsonStructure([
                'current_page', 'data', 'per_page', 'total', 'last_page',
            ])
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.branch_id', 1)
            ->assertJsonPath('data.0.reporter_id', $director->id)
            ->assertJsonPath('data.0.student_name', '小明')
            ->assertJsonPath('data.0.status', 'pending');

        $row = $response->json('data.0');
        $this->assertIsInt($row['id']);
        $this->assertIsInt($row['branch_id']);
        $this->assertIsInt($row['reporter_id']);
        $this->assertIsString($row['discrepancy_type']);
        $this->assertIsString($row['discrepancy_type_label']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['session_date']);
        $this->assertIsString($row['created_at']);
        $this->assertSame('SD ' . $director->LoginName, $row['reporter_name']);
    }

    public function test_mine_and_active_reads_keep_aliases_audit_fields_and_null_contract(): void
    {
        [$teacherToken, $teacher] = $this->makeUserToken(1, 'sd-read-mine@test.com', 'T');
        [, $otherTeacher] = $this->makeUserToken(1, 'sd-read-other@test.com', 'T');
        $report = $this->makeReport($teacher->id, [
            'class_session_id' => 7001,
            'status' => 'acknowledged',
            'acknowledged_at' => Carbon::parse('2026-08-30 12:00:00'),
        ]);
        $this->makeReport($otherTeacher->id, ['class_session_id' => 7002]);

        $mine = $this->authJson('GET', '/api/v1/schedule-discrepancies/my?per_page=10', $teacherToken);
        $mine->assertOk()
            ->assertJsonStructure([
                'current_page', 'data', 'per_page', 'total', 'last_page',
                'data' => [[
                    'id', 'class_session_id', 'branch_id', 'reporter_id',
                    'discrepancy_type', 'discrepancy_type_label', 'status',
                    'created_at', 'acknowledged_at', 'resolved_at', 'withdrawn_at',
                ]],
            ])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonPath('data.0.reporter_id', $teacher->id)
            ->assertJsonPath('data.0.status', 'acknowledged');

        $empty = $this->authJson(
            'GET',
            '/api/v1/schedule-discrepancies/active-for-session?class_session_id=9999',
            $teacherToken
        );
        $empty->assertOk()->assertJsonPath('discrepancy', null);

        $active = $this->authJson(
            'GET',
            '/api/v1/schedule-discrepancies/active-for-session?class_session_id=7001',
            $teacherToken
        );
        $active->assertOk()
            ->assertJsonPath('discrepancy.id', $report->id)
            ->assertJsonPath('discrepancy.class_session_id', 7001)
            ->assertJsonPath('discrepancy.status', 'acknowledged');
        $this->assertIsInt($active->json('discrepancy.class_session_id'));
        $this->assertIsString($active->json('discrepancy.created_at'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $mine->json('data.0.acknowledged_at')
        );
    }

    public function test_summary_counts_only_unarchived_reports_in_requested_campus(): void
    {
        [$token, $director] = $this->makeUserToken(1, 'sd-read-summary@test.com', 'A');
        $this->makeReport($director->id, ['status' => 'pending']);
        $this->makeReport($director->id, ['status' => 'acknowledged']);
        $this->makeReport($director->id, ['status' => 'resolved']);
        $this->makeReport($director->id, ['status' => 'withdrawn']);
        $this->makeReport($director->id, ['status' => 'pending', 'archived_at' => now()]);
        $this->makeReport($director->id, ['branch_id' => 2, 'status' => 'pending']);

        $summary = $this->authJson(
            'GET',
            '/api/v1/schedule-discrepancies/summary?branch_id=1',
            $token
        );

        $summary->assertOk()
            ->assertExactJson([
                'pending' => 1,
                'acknowledged' => 1,
                'resolved' => 1,
                'withdrawn' => 1,
            ]);
        foreach (['pending', 'acknowledged', 'resolved', 'withdrawn'] as $key) {
            $this->assertIsInt($summary->json($key));
        }
    }

    private function authJson(string $method, string $url, string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->json($method, $url);
    }

    private function makeUserToken(int $campusId, string $loginName, string $type): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'SD ' . $loginName,
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $user->id,
            'Admin' => $type === 'A' ? 1 : 0,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return [$token, $user];
    }

    private function makeReport(int $reporterId, array $overrides = []): ScheduleDiscrepancy
    {
        return ScheduleDiscrepancy::create(array_merge([
            'class_session_id' => null,
            'reporter_id' => $reporterId,
            'branch_id' => 1,
            'discrepancy_type' => 'wrong_time',
            'session_date' => '2026-08-30',
            'subject' => '數學',
            'student_name' => '測試生',
            'time_range' => '14:00-15:00',
            'notes' => '測試回報',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
