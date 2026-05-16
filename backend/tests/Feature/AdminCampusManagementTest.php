<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Superadmin 分校管理 CRUD API 測試。
 *
 * 背景：CampusController 原有硬編碼 BRANCH_NAMES 白名單，
 * 新分校不在白名單就無法在主任申請頁出現。
 * 本測試驗證修法後新分校可正常出現，且 CRUD API 正常運作。
 */
class AdminCampusManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $superToken;
    private string $directorToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Super admin user
        $super = User::create([
            'LoginName' => 'super@example.com',
            'Name'      => 'Super Admin',
            'PSW'       => bcrypt('pw'),
            'type'      => 'S',
            'status'    => 'active',
        ]);
        $t = \bin2hex(\random_bytes(16));
        AuthToken::create(['user_id' => $super->id, 'token' => $t, 'expires_at' => now()->addDay()]);
        $this->superToken = $t;

        // Regular director
        $dir = User::create([
            'LoginName' => 'dir@example.com',
            'Name'      => '普通主任',
            'PSW'       => bcrypt('pw'),
            'type'      => 'A',
            'status'    => 'active',
        ]);
        $t2 = \bin2hex(\random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $t2, 'expires_at' => now()->addDay()]);
        $this->directorToken = $t2;
    }

    // ── 公開端點（主任申請用） ──────────────────────────────────────────────────

    /**
     * @test
     * 新增分校後，公開的 GET /branches 必須包含該分校。
     * 驗證移除硬編碼白名單後，所有啟用分校都能出現。
     */
    public function new_active_campus_appears_in_public_branch_list(): void
    {
        // 建立一個不在原白名單內的新分校
        Campus::create([
            'name'    => '板橋分校',
            'code'    => 'banqiao',
            'active'  => true,
            'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
            'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '',
            'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);

        $res = $this->getJson('/api/v1/branches');

        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();
        $this->assertContains('板橋分校', $names, '新分校必須出現在公開分校清單（主任申請用）');
    }

    /**
     * @test
     * 停用的分校不應出現在公開清單。
     */
    public function inactive_campus_excluded_from_public_list(): void
    {
        Campus::create([
            'name'    => '廢棄測試分校',
            'code'    => 'test-inactive',
            'active'  => false,
            'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
            'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '',
            'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);

        $res = $this->getJson('/api/v1/branches');
        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();
        $this->assertNotContains('廢棄測試分校', $names);
    }

    // ── Superadmin CRUD ─────────────────────────────────────────────────────────

    /** @test */
    public function superadmin_can_list_all_campuses(): void
    {
        Campus::create([
            'name' => '列表測試分校', 'code' => 'listtest', 'active' => true, 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
            'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '',
            'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);

        $res = $this->auth($this->superToken)->getJson('/api/v1/admin/campuses');
        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();
        $this->assertContains('列表測試分校', $names);
    }

    /** @test */
    public function superadmin_can_create_campus(): void
    {
        $res = $this->auth($this->superToken)->postJson('/api/v1/admin/campuses', [
            'name'   => '新竹分校',
            'code'   => 'hsinchu',
            'active' => true,
        ]);

        $res->assertStatus(201);
        $this->assertSame('新竹分校', $res->json('name'));
        $this->assertDatabaseHas('Campus', ['name' => '新竹分校', 'code' => 'hsinchu']);
    }

    /** @test */
    public function superadmin_can_update_campus(): void
    {
        $campus = Campus::create([
            'name' => '舊名分校', 'code' => 'oldcode', 'active' => true, 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
            'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '',
            'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);

        $res = $this->auth($this->superToken)
            ->putJson("/api/v1/admin/campuses/{$campus->id}", [
                'name' => '新名分校',
                'active' => false,
            ]);

        $res->assertOk();
        $this->assertDatabaseHas('Campus', ['id' => $campus->id, 'name' => '新名分校']);
    }

    /** @test */
    public function superadmin_can_delete_campus_without_users(): void
    {
        $campus = Campus::create([
            'name' => '空分校', 'code' => 'empty', 'active' => true, 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
            'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '',
            'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);

        $res = $this->auth($this->superToken)
            ->deleteJson("/api/v1/admin/campuses/{$campus->id}");

        $res->assertOk();
        $this->assertDatabaseMissing('Campus', ['id' => $campus->id]);
    }

    /** @test */
    public function cannot_delete_campus_that_has_users(): void
    {
        $campus = Campus::create([
            'name' => '有人分校', 'code' => 'withusers', 'active' => true, 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
            'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '',
            'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '',
        ]);

        $user = User::create([
            'LoginName' => 'user-in-campus@example.com', 'Name' => '使用者',
            'PSW' => bcrypt('pw'), 'type' => 'A', 'status' => 'active',
        ]);
        UserCampus::create([
            'UserID' => $user->id, 'CampusID' => $campus->id, 'Admin' => 0, 'Approved' => 1,
        ]);

        $res = $this->auth($this->superToken)
            ->deleteJson("/api/v1/admin/campuses/{$campus->id}");

        $res->assertStatus(422);
        $this->assertDatabaseHas('Campus', ['id' => $campus->id]);
    }

    /** @test */
    public function director_cannot_access_admin_campus_api(): void
    {
        $this->auth($this->directorToken)
            ->getJson('/api/v1/admin/campuses')
            ->assertStatus(403);
    }

    private function auth(string $token): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }
}
