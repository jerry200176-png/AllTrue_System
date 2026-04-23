<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 3 Tech Debt — TD-010
 *
 * 驗證：StudentController::bindCard 在同一分校綁定相同 RFID 時應回傳 422，
 * 並驗證 DB unique index (RFID, CampusID) 防止重複寫入。
 *
 * 現況（紅燈）：bindCard 無唯一性驗證，重複 RFID 可靜默寫入 → 刷卡只取第一筆
 * 修復後（綠燈）：422 + 明確錯誤訊息；DB unique index 兜底防止 bypass
 *
 * ⚠️ CI only — 不可在 production 跑（RefreshDatabase）
 */
class RfidUniqueConstraintTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeDirectorToken(int $campusId, string $email): string
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'LoginName'          => $email,
            'Name'               => "Dir{$n}",
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => "091{$n}111{$n}11",
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    private function makeStudent(int $campusId, ?string $rfid = null): Student
    {
        static $n = 0;
        $n++;
        return Student::create([
            'name'         => "RfidStudent{$n}",
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'RFID'         => $rfid,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function bindCard(string $token, int $studentId, string $rfid): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson("/api/v1/students/{$studentId}/bind-card", ['rfid' => $rfid]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * TD-010 (1/3): 同分校相同 RFID 綁第二個學生應回傳 422。
     *
     * 現況（紅燈）：bindCard 無驗證 → 回傳 200，RFID 重複靜默成功
     * 修復後（綠燈）：回傳 422 + 明確錯誤訊息
     */
    public function bind_card_rejects_duplicate_rfid_in_same_campus(): void
    {
        $token    = $this->makeDirectorToken(1, 'rfid-test-1@example.com');
        $studentA = $this->makeStudent(1);
        $studentB = $this->makeStudent(1);

        // 第一次綁定應成功
        $this->bindCard($token, $studentA->id, 'CARD-001')->assertOk();

        // 同一分校第二個學生綁相同 RFID → 應拒絕
        $res = $this->bindCard($token, $studentB->id, 'CARD-001');

        $res->assertStatus(422);
        $this->assertStringContainsString(
            'CARD-001',
            $res->json('message') ?? $res->content(),
            'TD-010: 422 錯誤訊息應包含衝突的 RFID 值'
        );

        // 確認 studentB 的 RFID 未被更新
        $this->assertNull($studentB->fresh()->RFID,
            'TD-010: 被拒絕的學生 RFID 不應被更新');
    }

    /**
     * @test
     * TD-010 (2/3): 不同分校允許使用相同 RFID（composite unique: RFID + CampusID）。
     *
     * 此測試在修復前後均應通過（迴歸保護）。
     */
    public function bind_card_allows_same_rfid_in_different_campuses(): void
    {
        $tokenA   = $this->makeDirectorToken(1, 'rfid-test-2a@example.com');
        $tokenB   = $this->makeDirectorToken(2, 'rfid-test-2b@example.com');
        $studentA = $this->makeStudent(1);
        $studentB = $this->makeStudent(2);

        // 分校 1 綁 CARD-002
        $this->bindCard($tokenA, $studentA->id, 'CARD-002')->assertOk();

        // 分校 2 相同 RFID → 應允許（不同 campus）
        $this->bindCard($tokenB, $studentB->id, 'CARD-002')->assertOk();

        $this->assertSame('CARD-002', $studentA->fresh()->RFID);
        $this->assertSame('CARD-002', $studentB->fresh()->RFID);
    }

    /**
     * @test
     * TD-010 (3/3): RFID=null 的學生不受 unique constraint 影響（MySQL NULL 視為不同值）。
     *
     * 此測試在修復前後均應通過（迴歸保護）。
     */
    public function multiple_students_with_null_rfid_do_not_conflict(): void
    {
        // 建立多個 RFID=null 學生，驗證 unique index 不阻擋 null 值
        $this->makeStudent(1, null);
        $this->makeStudent(1, null);
        $this->makeStudent(1, null);

        $count = Student::where('CampusID', 1)->whereNull('RFID')->count();
        $this->assertGreaterThanOrEqual(3, $count,
            'TD-010: RFID=null 的學生可以共存，不受 unique constraint 限制');
    }
}
