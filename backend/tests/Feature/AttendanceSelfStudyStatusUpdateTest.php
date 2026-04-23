<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /api/v1/attendance/{id}
 * 自修記錄狀態直接更新（不觸發堂數邏輯）
 *
 * FR-001：主任可更新自修記錄 Status（無 ClassSessionID）
 * FR-002：非主任角色 → 403
 * FR-003：分校隔離——其他分校主任 → 403
 * FR-004：已軟刪除記錄 → 404
 * FR-005：無效 status 值 → 422
 * FR-006：一般有 ClassSessionID 的記錄也可更新
 */
class AttendanceSelfStudyStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    // ── FR-001: 主任更新自修記錄 Status ──────────────────────────────────────

    public function test_director_can_update_self_study_record_status(): void
    {
        [$token, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'director');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'absent']);

        $res->assertOk()
            ->assertJsonFragment(['ok' => true, 'status' => 'absent', 'status_label' => '缺席']);

        $this->assertDatabaseHas('StudentSingIn', [
            'id'     => $signin->id,
            'Status' => 'absent',
        ]);
    }

    public function test_director_can_update_self_study_to_leave(): void
    {
        [$token, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'director');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'leave']);

        $res->assertOk()
            ->assertJsonFragment(['status' => 'leave', 'status_label' => '請假']);
    }

    public function test_super_admin_can_update_self_study_record_status(): void
    {
        [$token, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'super_admin');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'late']);

        $res->assertOk()
            ->assertJsonFragment(['status' => 'late', 'status_label' => '遲到']);
    }

    // ── FR-002: 非主任角色 → 403 ──────────────────────────────────────────────

    public function test_teacher_cannot_update_self_study_status(): void
    {
        [$token, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'teacher');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'absent']);

        $res->assertForbidden();
    }

    // ── FR-003: 分校隔離 ──────────────────────────────────────────────────────

    public function test_director_of_another_campus_cannot_update(): void
    {
        // 自修記錄屬於 campus 1
        [, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'director');

        // 另一個 campus 2 的主任
        Campus::firstOrCreate(['id' => 2], ['name' => '分校2', 'code' => 'C2', 'Current' => 1]);
        $otherToken = $this->makeDirectorToken(campusId: 2);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$otherToken}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'absent']);

        $res->assertForbidden();
    }

    // ── FR-004: 已軟刪除 → 404 ───────────────────────────────────────────────

    public function test_voided_record_returns_404(): void
    {
        [$token, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'director');

        $signin->VoidedAt = now();
        $signin->save();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'absent']);

        $res->assertNotFound();
    }

    // ── FR-005: 無效 status → 422 ────────────────────────────────────────────

    public function test_invalid_status_returns_422(): void
    {
        [$token, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'director');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'invalid_value']);

        $res->assertUnprocessable();
    }

    // ── FR-006: 有 ClassSessionID 的一般記錄也可更新 ─────────────────────────

    public function test_director_can_update_regular_record_without_class_session_logic(): void
    {
        [$token, $signin] = $this->scaffoldSelfStudy(campusId: 1, role: 'director');

        // 給記錄加上 ClassSessionID（模擬一般出缺勤記錄）
        $signin->ClassSessionID = 999;
        $signin->Memo = null;
        $signin->save();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/attendance/{$signin->id}", ['status' => 'late']);

        $res->assertOk()
            ->assertJsonFragment(['status' => 'late']);
    }

    // ── Scaffolding ───────────────────────────────────────────────────────────

    private function scaffoldSelfStudy(int $campusId, string $role): array
    {
        Campus::firstOrCreate(
            ['id' => $campusId],
            ['name' => "分校{$campusId}", 'code' => "C{$campusId}", 'Current' => 1]
        );

        $student = Student::create([
            'name'         => '自修學生',
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);

        $signin = StudentSignIn::create([
            'StudentID'       => $student->id,
            'StudentClassID'  => null,
            'ClassSessionID'  => null,
            'Memo'            => 'self_study',
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => $campusId,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        $token = $this->makeDirectorToken(campusId: $campusId, role: $role);

        return [$token, $signin];
    }

    private function makeDirectorToken(int $campusId, string $role = 'director'): string
    {
        $userType = $role === 'teacher' ? 'T' : 'A';
        $loginName = "{$role}-patch-test-{$campusId}-" . uniqid() . '@example.com';

        $user = User::create([
            'LoginName'          => $loginName,
            'Name'               => "測試{$role}",
            'PSW'                => 'secret',
            'type'               => $userType,
            'phone'              => '0900000000',
            'MustChangePassword' => false,
        ]);

        $isAdmin = in_array($role, ['director', 'super_admin'], true) ? 1 : 0;
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID'   => $user->id,
            'Admin'    => $isAdmin,
            'Approved' => 1,
        ]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $raw,
            'expires_at' => now()->addDay(),
        ]);

        return $raw;
    }
}
