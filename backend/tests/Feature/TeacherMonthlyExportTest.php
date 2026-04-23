<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FR-001 (月報匯出): GET /api/v1/teacher-attendance/export-monthly
 *
 * 防再犯紀錄 §TEST-001 ─ 所有 DB insert 均核對過 NOT NULL 欄位。
 */
class TeacherMonthlyExportTest extends TestCase
{
    use RefreshDatabase;

    // ── AC-1: 主任可成功下載 XLSX，HTTP 200 且 Content-Type 正確 ──────────────

    public function test_director_can_export_monthly_xlsx(): void
    {
        [$token, $campusId] = $this->scaffold('director');

        DB::table('TeacherSingIn')->insert([
            'TeacherID' => 1,
            'CampusID'  => $campusId,
            'SignInDT'  => now()->format('Y-m-d 08:00:00'),
            'SignOutDT' => now()->format('Y-m-d 17:00:00'),
            'MDT'       => now(),
        ]);

        $yearMonth = now()->format('Y-m');
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => '*/*'])
            ->get("/api/v1/teacher-attendance/export-monthly?year_month={$yearMonth}");

        $res->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('Content-Type', ''),
            '回應應為 XLSX Content-Type'
        );
    }

    // ── AC-2: year_month 格式錯誤 → 422 ──────────────────────────────────────

    public function test_invalid_year_month_returns_422(): void
    {
        [$token] = $this->scaffold('director');

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->get('/api/v1/teacher-attendance/export-monthly?year_month=2026-99');

        $res->assertUnprocessable();
    }

    // ── AC-3: 一般老師無法呼叫（middleware role:director,super_admin）─────────

    public function test_teacher_role_is_forbidden(): void
    {
        [$token] = $this->scaffold('teacher');

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->get('/api/v1/teacher-attendance/export-monthly?year_month=' . now()->format('Y-m'));

        $res->assertStatus(403);
    }

    // ── AC-4: 未攜帶 token → 401 ─────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $res = $this->withHeaders(['Accept' => 'application/json'])
            ->get('/api/v1/teacher-attendance/export-monthly?year_month=' . now()->format('Y-m'));

        $res->assertUnauthorized();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function scaffold(string $role): array
    {
        Campus::firstOrCreate(
            ['id' => 1],
            ['name' => '測試分校', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
             'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '',
             'TeachLIFFID' => '', 'TeachLIFF_URL' => '']
        );

        $type = $role === 'teacher' ? 'T' : 'A';
        $email = "monthly-export-{$role}-" . uniqid() . '@example.com';
        $user = User::create([
            'LoginName'          => $email,
            'Name'               => '測試用戶',
            'PSW'                => 'secret',
            'type'               => $type,
            'phone'              => '0900000001',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID'   => $user->id,
            'Admin'    => $role === 'director' ? 1 : 0,
            'Approved' => 1,
        ]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return [$raw, 1];
    }
}
