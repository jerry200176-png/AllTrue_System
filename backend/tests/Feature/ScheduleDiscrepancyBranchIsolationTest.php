<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRD 2026-04-18: 課程回報管理分校隔離 — FR-001 / FR-002.
 *
 * Before this PRD a multi-campus director could pull reports from every
 * branch they were attached to by omitting branch_id. Directors must now
 * be forced to pick one branch; super_admins keep the aggregate view.
 */
class ScheduleDiscrepancyBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_campus_director_without_branch_id_sees_only_their_campus(): void
    {
        [$dirToken, $dir] = $this->makeUserToken([1], 'sdbi-single-dir@test.com', 'A');
        [$tok1] = $this->makeUserToken(1, 'sdbi-t1@test.com', 'T');
        [$tok2] = $this->makeUserToken(2, 'sdbi-t2@test.com', 'T');
        $session1 = $this->makeClassSession(1);
        $session2 = $this->makeClassSession(2);

        $this->postReport($tok1, 1, $session1, '校1 報告');
        $this->postReport($tok2, 2, $session2, '校2 報告');

        $res = $this->authJson('GET', '/api/v1/schedule-discrepancies', [], $dirToken);
        $res->assertOk();

        $rows = $res->json('data') ?? [];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(1, (int) $row['branch_id']);
        }
    }

    public function test_multi_campus_director_without_branch_id_is_rejected_422(): void
    {
        [$dirToken] = $this->makeUserToken([1, 2], 'sdbi-multi-dir@test.com', 'A');

        $res = $this->authJson('GET', '/api/v1/schedule-discrepancies', [], $dirToken);
        $res->assertStatus(422);
        $res->assertJsonStructure(['message']);
        $this->assertStringContainsString('分校', (string) $res->json('message'));
    }

    public function test_multi_campus_director_with_own_branch_id_sees_that_branch_only(): void
    {
        [$dirToken] = $this->makeUserToken([1, 2], 'sdbi-multi-ok@test.com', 'A');
        [$tok1] = $this->makeUserToken(1, 'sdbi-multi-t1@test.com', 'T');
        [$tok2] = $this->makeUserToken(2, 'sdbi-multi-t2@test.com', 'T');
        $session1 = $this->makeClassSession(1);
        $session2 = $this->makeClassSession(2);
        $this->postReport($tok1, 1, $session1, '校1 資料');
        $this->postReport($tok2, 2, $session2, '校2 資料');

        $res = $this->authJson('GET', '/api/v1/schedule-discrepancies?branch_id=2', [], $dirToken);
        $res->assertOk();
        $rows = $res->json('data') ?? [];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(2, (int) $row['branch_id']);
        }
    }

    public function test_director_cannot_specify_branch_outside_their_campuses(): void
    {
        [$dirToken] = $this->makeUserToken([1], 'sdbi-tamper-dir@test.com', 'A');

        $this->authJson('GET', '/api/v1/schedule-discrepancies?branch_id=2', [], $dirToken)
            ->assertStatus(403);
    }

    public function test_super_admin_without_branch_id_sees_all_campuses(): void
    {
        [$superToken] = $this->makeUserToken([], 'sdbi-super@test.com', 'S');
        [$tok1] = $this->makeUserToken(1, 'sdbi-super-t1@test.com', 'T');
        [$tok2] = $this->makeUserToken(2, 'sdbi-super-t2@test.com', 'T');
        $s1 = $this->makeClassSession(1);
        $s2 = $this->makeClassSession(2);
        $this->postReport($tok1, 1, $s1, 'super 看得見校1');
        $this->postReport($tok2, 2, $s2, 'super 看得見校2');

        $res = $this->authJson('GET', '/api/v1/schedule-discrepancies', [], $superToken);
        $res->assertOk();
        $rows = $res->json('data') ?? [];
        $branchIds = array_unique(array_map(fn ($r) => (int) $r['branch_id'], $rows));
        sort($branchIds);
        $this->assertSame([1, 2], $branchIds);
    }

    public function test_super_admin_with_branch_id_scopes_to_that_branch(): void
    {
        [$superToken] = $this->makeUserToken([], 'sdbi-super-scope@test.com', 'S');
        [$tok1] = $this->makeUserToken(1, 'sdbi-super-scope-t1@test.com', 'T');
        [$tok2] = $this->makeUserToken(2, 'sdbi-super-scope-t2@test.com', 'T');
        $this->postReport($tok1, 1, $this->makeClassSession(1), 'x1');
        $this->postReport($tok2, 2, $this->makeClassSession(2), 'x2');

        $res = $this->authJson('GET', '/api/v1/schedule-discrepancies?branch_id=2', [], $superToken);
        $res->assertOk();
        $rows = $res->json('data') ?? [];
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(2, (int) $row['branch_id']);
        }
    }

    // ────────── helpers ──────────

    private function authJson(string $method, string $url, array $data, string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->json($method, $url, $data);
    }

    private function postReport(string $token, int $branchId, int $sessionId, string $note): int
    {
        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => $branchId,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'notes' => $note,
        ], $token);
        $res->assertStatus(201);
        return (int) $res->json('discrepancy.id');
    }

    private function makeUserToken($campusIds, string $loginName, string $type): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'SDBI',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) rand(900000000, 999999999),
            'MustChangePassword' => false,
        ]);

        foreach ((array) $campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID' => $user->id,
                'Admin' => $type === 'A' ? 1 : 0,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return [$token, $user];
    }

    private function makeClassSession(int $campusId): int
    {
        $studentId = DB::table('Student')->insertGetId([
            'name' => '測試生' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $studentClassId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'GradeID' => 0,
            'SubjectID' => 1,
            'TeacherID' => 0,
            'by1' => 0,
            'StartDate' => now(),
            'TotalHours' => 0,
            'RoomID' => '',
            'SessionCount' => 1,
            'Charge' => 0,
        ]);

        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $studentClassId,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '14:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'scheduled',
        ]);
    }
}
