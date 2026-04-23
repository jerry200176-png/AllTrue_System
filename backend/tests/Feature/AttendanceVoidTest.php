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
 * FR-007 ~ FR-013 (刪除 / Void 出缺勤記錄)
 * DELETE /api/v1/attendance/{id}
 *
 * 防再犯紀錄 §TEST-001 ─ StudentSingIn NOT NULL 欄位：StudentID, SignInDT。
 */
class AttendanceVoidTest extends TestCase
{
    use RefreshDatabase;

    // ── AC-1: 主任成功 void，VoidedAt 被寫入 ──────────────────────────────────

    public function test_director_can_void_attendance_record(): void
    {
        [$token, $student] = $this->scaffold();

        $signin = StudentSignIn::create([
            'StudentID'       => $student->id,
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", [
            'void_reason' => '測試資料，請刪除',
        ]);

        $res->assertOk()->assertJson(['ok' => true, 'voided_id' => $signin->id]);

        $this->assertNotNull(
            StudentSignIn::withTrashed()->find($signin->id)?->VoidedAt
                ?? StudentSignIn::find($signin->id)?->VoidedAt
                ?? StudentSignIn::where('id', $signin->id)->value('VoidedAt'),
            'VoidedAt 應被寫入'
        );

        // 再次查詢時應消失（active scope）
        $this->assertNull(StudentSignIn::whereNull('VoidedAt')->find($signin->id));
    }

    // ── AC-2: 缺少 void_reason → 422 ─────────────────────────────────────────

    public function test_void_requires_reason(): void
    {
        [$token, $student] = $this->scaffold();

        $signin = StudentSignIn::create([
            'StudentID'  => $student->id,
            'SignInDT'   => now()->toDateTimeString(),
            'Status'     => 'present',
            'CampusID'   => 1,
            'PersonType' => 'student',
            'MDT'        => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '']);

        $res->assertUnprocessable();
    }

    // ── AC-3: 刪除不存在 or 已 void 的記錄 → 404 ─────────────────────────────

    public function test_void_nonexistent_record_returns_404(): void
    {
        [$token] = $this->scaffold();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson('/api/v1/attendance/999999', ['void_reason' => '測試刪除不存在記錄']);

        $res->assertNotFound();
    }

    // ── AC-4: 一般老師不能刪除 ────────────────────────────────────────────────

    public function test_teacher_cannot_void(): void
    {
        [$dirToken, $student] = $this->scaffold();
        $teacherToken         = $this->createTeacherToken();

        $signin = StudentSignIn::create([
            'StudentID'  => $student->id,
            'SignInDT'   => now()->toDateTimeString(),
            'Status'     => 'present',
            'CampusID'   => 1,
            'PersonType' => 'student',
            'MDT'        => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '老師嘗試刪除']);

        $res->assertStatus(403);
        $this->assertNull(StudentSignIn::whereNull('VoidedAt')->find($signin->id)?->VoidedAt);
    }

    // ── AC-5: void 已 voided 記錄 → 404（active scope 找不到） ──────────────

    public function test_void_already_voided_record_returns_404(): void
    {
        [$token, $student] = $this->scaffold();

        $signin = StudentSignIn::create([
            'StudentID'       => $student->id,
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'MDT'             => now(),
            'VoidedAt'        => now(),
            'VoidedByUserID'  => 1,
            'VoidReason'      => '已刪除',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->deleteJson("/api/v1/attendance/{$signin->id}", ['void_reason' => '重複刪除測試']);

        $res->assertNotFound();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function scaffold(): array
    {
        Campus::firstOrCreate(
            ['id' => 1],
            ['name' => '測試分校', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
             'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '',
             'TeachLIFFID' => '', 'TeachLIFF_URL' => '']
        );

        $user = User::create([
            'LoginName'          => 'dir-void-' . uniqid() . '@example.com',
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0900111000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name'         => '刪除測試生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);

        return [$raw, $student];
    }

    private function createTeacherToken(): string
    {
        $teacher = User::create([
            'LoginName'          => 'tch-void-' . uniqid() . '@example.com',
            'Name'               => '老師',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => '0911000002',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }
}
