<?php

namespace Tests\Feature;

use App\Http\Middleware\RequirePin;
use App\Models\AuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * #769 Phase A — 敏感頁 PIN 二次驗證後端。涵蓋 AC1–AC8。
 */
class PinVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'LoginName'          => 'pin-director@example.com',
            'Name'               => 'PinDir',
            'PSW'                => password_hash('account-pass', PASSWORD_DEFAULT),
            'type'               => 'A',
            'MustChangePassword' => false,
        ]);

        $this->token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $this->user->id,
            'token'      => $this->token,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function authed(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /** @test AC: status 反映尚未設定 */
    public function status_reports_pin_not_set_initially(): void
    {
        $res = $this->withHeaders($this->authed())->getJson('/api/v1/me/pin/status');
        $res->assertOk()
            ->assertJson(['pin_set' => false, 'locked' => false, 'verified' => false]);
    }

    /** @test AC1/AC2/AC3 設定成功並解鎖本 session */
    public function set_pin_succeeds_and_unlocks_session(): void
    {
        $res = $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/set', ['pin' => '2468']);
        $res->assertOk()->assertJson(['pin_set' => true]);

        $this->assertNotEmpty($this->user->fresh()->pin_hash);
        $this->assertNotEquals('2468', $this->user->fresh()->pin_hash, 'PIN 必須雜湊儲存');

        // 設定後本 session 應為已驗證
        $this->withHeaders($this->authed())->getJson('/api/v1/me/pin/status')
            ->assertJson(['pin_set' => true, 'verified' => true]);
    }

    /** @test AC1 弱碼被擋 */
    public function set_rejects_weak_pins(): void
    {
        foreach (['1234', '0000', '123456', '654321', '1111'] as $weak) {
            $this->user->update(['pin_hash' => null]);
            $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/set', ['pin' => $weak])
                ->assertStatus(422);
        }
    }

    /** @test AC1 格式錯誤被擋 */
    public function set_rejects_bad_format(): void
    {
        foreach (['12', '1234567', 'abcd', '12a4'] as $bad) {
            $this->user->update(['pin_hash' => null]);
            $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/set', ['pin' => $bad])
                ->assertStatus(422);
        }
    }

    /** @test 已設定再 set → 409 */
    public function set_conflicts_when_already_set(): void
    {
        $this->user->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);
        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/set', ['pin' => '1357'])
            ->assertStatus(409);
    }

    /** @test AC: verify 正確解鎖 */
    public function verify_correct_pin_unlocks(): void
    {
        $this->user->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);
        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/verify', ['pin' => '2468'])
            ->assertOk()->assertJson(['verified' => true]);

        $this->assertTrue(
            Carbon::parse(AuthToken::where('token', $this->token)->first()->pin_verified_until)->isFuture()
        );
    }

    /** @test AC4 連續錯誤 5 次後鎖定 15 分鐘 */
    public function verify_locks_after_five_failures(): void
    {
        $this->user->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);

        for ($i = 1; $i <= 4; $i++) {
            $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/verify', ['pin' => '9999'])
                ->assertStatus(401);
        }
        // 第 5 次 → 鎖定
        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/verify', ['pin' => '9999'])
            ->assertStatus(429);
        // 鎖定中即使正確也擋
        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/verify', ['pin' => '2468'])
            ->assertStatus(429);

        // 15 分鐘後解鎖可再試
        Carbon::setTestNow(now()->addMinutes(16));
        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/verify', ['pin' => '2468'])
            ->assertOk();
        Carbon::setTestNow();
    }

    /** @test AC6 lock 清除解鎖 */
    public function lock_clears_verification(): void
    {
        $this->user->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);
        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/verify', ['pin' => '2468'])->assertOk();

        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/lock')->assertOk();
        $this->assertNull(AuthToken::where('token', $this->token)->first()->pin_verified_until);
        $this->withHeaders($this->authed())->getJson('/api/v1/me/pin/status')->assertJson(['verified' => false]);
    }

    /** @test AC7 重設需正確帳號密碼 */
    public function reset_requires_correct_account_password(): void
    {
        $this->user->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);

        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/reset', ['pin' => '1357', 'password' => 'wrong'])
            ->assertStatus(401);

        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/reset', ['pin' => '1357', 'password' => 'account-pass'])
            ->assertOk()->assertJson(['pin_set' => true]);

        // 新 PIN 生效、舊 PIN 失效
        $this->user->update(['pin_locked_until' => null]);
        $this->withHeaders($this->authed())->postJson('/api/v1/me/pin/verify', ['pin' => '1357'])->assertOk();
    }

    /** @test AC8/FR6 require_pin middleware：未設 PIN soft 放行 */
    public function require_pin_soft_passes_when_no_pin(): void
    {
        $request = Request::create('/x', 'GET');
        $request->headers->set('Authorization', "Bearer {$this->token}");
        $request->attributes->set('auth_user', $this->user->fresh());

        $resp = (new RequirePin())->handle($request, fn ($r) => response('ok', 200));
        $this->assertSame(200, $resp->getStatusCode());
    }

    /** @test AC8/FR6 require_pin middleware：有 PIN 未驗證 → 423；驗證後放行 */
    public function require_pin_blocks_until_verified(): void
    {
        $this->user->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);

        $make = function () {
            $request = Request::create('/x', 'GET');
            $request->headers->set('Authorization', "Bearer {$this->token}");
            $request->attributes->set('auth_user', $this->user->fresh());
            return $request;
        };

        $blocked = (new RequirePin())->handle($make(), fn ($r) => response('ok', 200));
        $this->assertSame(423, $blocked->getStatusCode());

        // 解鎖後放行
        AuthToken::where('token', $this->token)->update(['pin_verified_until' => now()->addMinutes(10)]);
        $passed = (new RequirePin())->handle($make(), fn ($r) => response('ok', 200));
        $this->assertSame(200, $passed->getStatusCode());
    }

    /** @test 未認證 → 401 */
    public function unauthenticated_status_returns_401(): void
    {
        $this->getJson('/api/v1/me/pin/status')->assertStatus(401);
    }
}
