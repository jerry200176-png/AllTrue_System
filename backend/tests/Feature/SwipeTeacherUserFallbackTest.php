<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\TeacherSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: 刷卡系統必須支援「只存在 User 表、不存在 Teacher 表」的老師。
 *
 * 背景：新增老師現在統一寫入 User + UserCampus。但 SwipeRfidController
 * 原來只查 Teacher 表，導致新老師刷卡失敗。
 *
 * 修法：找不到 Teacher 記錄時，改以 User 表作為 fallback。
 */
class SwipeTeacherUserFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        $this->campus = Campus::create([
            'name'           => 'FallbackCampus',
            'Token'          => 'fallback-token-xyz',
            'code'           => 'fb',
            'Current'        => 0,
            'LineNotifyID'   => '',
            'Client_ID'      => '',
            'Client_Secret'  => '',
            'LIFFID'         => '',
            'LIFF_URL'       => '',
            'URL'            => '',
            'TelegramToken'  => '',
            'TelegramChatID' => '',
            'TelegramURL'    => '',
            'TeachLIFFID'    => '',
            'TeachLIFF_URL'  => '',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function swipe(string $rfid): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/swipe-rfid', [
            'rfid'  => $rfid,
            'token' => 'fallback-token-xyz',
        ]);
    }

    /**
     * @test
     * 老師只在 User + UserCampus，Teacher 表無對應記錄時刷卡必須成功。
     *
     * 這是 2026-05 工程師回報的問題：新老師帳號只寫 User，未同步建 Teacher，
     * 導致刷卡系統查 Teacher 表找不到而拒絕打卡。
     */
    public function teacher_with_user_only_record_can_swipe_in(): void
    {
        $rfid = 'USER-ONLY-RFID-001';

        // 只建 User 記錄，刻意不建 Teacher 記錄
        $user = User::create([
            'LoginName' => 'user-only-teacher@example.com',
            'Name'      => '林小明',
            'PSW'       => bcrypt('pw'),
            'type'      => 'T',
            'status'    => 'active',
        ]);

        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $user->id,
            'Admin'    => 0,
            'Approved' => 1,
            'RFID'     => $rfid,
        ]);

        // 確認 Teacher 表確實沒有這個 ID
        $this->assertDatabaseMissing('Teacher', ['id' => $user->id]);

        $res = $this->swipe($rfid);

        $res->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('type', 'teacher')
            ->assertJsonPath('action', 'sign_in');

        $this->assertSame(
            1,
            TeacherSignIn::where('TeacherID', $user->id)->count(),
            '刷卡應建立 TeacherSignIn 記錄'
        );
    }

    /**
     * @test
     * 既有老師（同時有 User + Teacher 記錄）不受影響，刷卡仍正常。
     */
    public function teacher_with_both_user_and_teacher_records_can_swipe_in(): void
    {
        $rfid = 'BOTH-RECORDS-RFID-001';

        $userId = DB::table('User')->insertGetId([
            'LoginName' => 'both-records@example.com',
            'Name'      => '王大明',
            'PSW'       => bcrypt('pw'),
            'type'      => 'T',
            'status'    => 'active',
            'phone'     => '',
        ]);

        DB::table('Teacher')->insert([
            'id'       => $userId,
            'CampusID' => $this->campus->id,
            'T_Name'   => '王大明',
            'RFID'     => null,
            'Enable'   => 1,
            'MDT'      => now(),
            'TelegramID' => '',
        ]);

        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $userId,
            'Admin'    => 0,
            'Approved' => 1,
            'RFID'     => $rfid,
        ]);

        $res = $this->swipe($rfid);

        $res->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('type', 'teacher')
            ->assertJsonPath('action', 'sign_in');

        $this->assertSame(1, TeacherSignIn::where('TeacherID', $userId)->count());
    }

    /**
     * @test
     * 停用（status=suspended）的 User-only 老師刷卡必須失敗（不被接受）。
     */
    public function suspended_user_only_teacher_cannot_swipe(): void
    {
        $rfid = 'SUSPENDED-RFID-001';

        $user = User::create([
            'LoginName' => 'suspended@example.com',
            'Name'      => '停用老師',
            'PSW'       => bcrypt('pw'),
            'type'      => 'T',
            'status'    => 'suspended',
        ]);

        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $user->id,
            'Admin'    => 0,
            'Approved' => 1,
            'RFID'     => $rfid,
        ]);

        $res = $this->swipe($rfid);

        // 停用老師不應觸發 teacher sign_in，可能登記為未知 RFID
        $this->assertSame(0, TeacherSignIn::where('TeacherID', $user->id)->count(),
            '停用老師不應建立 TeacherSignIn');
    }
}
