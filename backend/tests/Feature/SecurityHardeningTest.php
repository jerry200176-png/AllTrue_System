<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleRequestsByIp;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 4 [TEST] — Security Hardening v1.0
 *
 * Throttle strategy:
 *  - Before each throttle test: clear the specific RateLimiter key.
 *  - Non-throttle tests that hit the same endpoints: bypass throttle via
 *    withoutMiddleware(ThrottleRequestsByIp::class) so they never pollute
 *    the counter for the throttle tests.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'name'           => 'SecTestCampus',
            'Token'          => 'sec-test-token-abc',
            'code'           => 'sec',
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

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Flush ALL cache to reset every rate-limiter counter.
     * Using Cache::flush() instead of computing the key because TrustProxies(*')
     * may cause $request->ip() to differ from '127.0.0.1' in CI, making
     * key-based clearing unreliable.
     */
    private function clearThrottle(): void
    {
        Cache::flush();
    }

    private function makeDirectorToken(): array
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'LoginName'          => "sec-dir-{$n}@example.com",
            'Name'               => "SecDir{$n}",
            'PSW'                => password_hash('Password1!', PASSWORD_DEFAULT),
            'type'               => 'A',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $user->id,
            'Admin'    => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(32));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);
        return [$token, $user];
    }

    // ─── SEC-002 / FR-001: auth/register throttle ─────────────────────────────

    /** @test */
    public function register_throttle_blocks_after_10_requests(): void
    {
        $this->clearThrottle();

        for ($i = 1; $i <= 10; $i++) {
            $res = $this->postJson('/api/v1/auth/register', [
                'account'  => "throttle-{$i}@x.com",
                'password' => 'Password1!',
                'name'     => "U{$i}",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $this->postJson('/api/v1/auth/register', [
            'account'  => 'throttle-11@x.com',
            'password' => 'Password1!',
            'name'     => 'U11',
        ])->assertStatus(429);
    }

    // ─── SEC-002 / FR-002: directors/register throttle ────────────────────────

    /** @test */
    public function directors_register_throttle_blocks_after_10_requests(): void
    {
        $this->clearThrottle();

        for ($i = 1; $i <= 10; $i++) {
            $res = $this->postJson('/api/v1/directors/register', [
                'account'   => "dir-{$i}@x.com",
                'password'  => 'Password1!',
                'name'      => "D{$i}",
                'campus_id' => $this->campus->id,
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $this->postJson('/api/v1/directors/register', [
            'account'   => 'dir-11@x.com',
            'password'  => 'Password1!',
            'name'      => 'D11',
            'campus_id' => $this->campus->id,
        ])->assertStatus(429);
    }

    // ─── SEC-003 / FR-003: forgot-password throttle ───────────────────────────

    /** @test */
    public function forgot_password_throttle_blocks_after_5_requests(): void
    {
        $this->clearThrottle();

        for ($i = 1; $i <= 5; $i++) {
            $res = $this->postJson('/api/v1/auth/forgot-password', [
                'account' => "fp-{$i}@x.com",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $this->postJson('/api/v1/auth/forgot-password', [
            'account' => 'fp-6@x.com',
        ])->assertStatus(429);
    }

    // ─── SEC-006 / FR-004: swipe-rfid throttle ────────────────────────────────

    /** @test */
    public function swipe_rfid_throttle_blocks_after_30_requests(): void
    {
        $this->clearThrottle();

        for ($i = 1; $i <= 30; $i++) {
            $res = $this->withHeaders(['Authorization' => 'Bearer sec-test-token-abc'])
                ->postJson('/api/v1/swipe-rfid', [
                    'branch_code' => (string) $this->campus->id,
                    'rfid'        => "RFID-{$i}",
                ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $this->withHeaders(['Authorization' => 'Bearer sec-test-token-abc'])
            ->postJson('/api/v1/swipe-rfid', [
                'branch_code' => (string) $this->campus->id,
                'rfid'        => 'RFID-31',
            ])->assertStatus(429);
    }

    // ─── SEC-004 / FR-007: auth/register rejects password < 8 ────────────────
    // Uses withoutMiddleware to avoid accumulating throttle counter.

    /** @test */
    public function register_rejects_password_shorter_than_8(): void
    {
        $this->withoutMiddleware(ThrottleRequestsByIp::class)
            ->postJson('/api/v1/auth/register', [
                'account'  => 'shortpwd@x.com',
                'password' => 'Abc123!',
                'name'     => 'Short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /** @test */
    public function register_accepts_password_of_8_chars(): void
    {
        $res = $this->withoutMiddleware(ThrottleRequestsByIp::class)
            ->postJson('/api/v1/auth/register', [
                'account'  => 'ok8pwd@x.com',
                'password' => 'Abc1234!',
                'name'     => 'OkPwd',
            ]);
        // Password validation must NOT produce a password error (other errors are fine)
        $this->assertArrayNotHasKey('password', $res->json('errors') ?? []);
    }

    // ─── SEC-004 / FR-008: directors/register rejects password < 8 ───────────

    /** @test */
    public function directors_register_rejects_password_shorter_than_8(): void
    {
        $this->withoutMiddleware(ThrottleRequestsByIp::class)
            ->postJson('/api/v1/directors/register', [
                'account'   => 'dir-short@x.com',
                'password'  => 'Ab12345',
                'name'      => 'DirShort',
                'campus_id' => $this->campus->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004 / FR-009: PUT /me rejects new password < 8 ──────────────────

    /** @test */
    public function update_me_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/v1/me', [
                'current_password'      => 'Password1!',
                'password'              => 'Short1!',
                'password_confirmation' => 'Short1!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004: ProfileController::store rejects password < 8 ──────────────

    /** @test */
    public function profile_store_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/profiles', [
                'account'  => 'new-teacher@x.com',
                'password' => 'Short1!',
                'name'     => 'NewTeacher',
                'role'     => 'teacher',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004: ProfileController::update rejects password < 8 ─────────────

    /** @test */
    public function profile_update_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        $teacher = User::create([
            'LoginName' => 'teacher-upd@x.com',
            'Name'      => 'TeacherUpd',
            'PSW'       => password_hash('Password1!', PASSWORD_DEFAULT),
            'type'      => 'T',
        ]);
        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $teacher->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/profiles/{$teacher->id}", [
                'password' => 'Short1!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // ─── FR-011 Regression: existing short-password account can still login ───

    /** @test */
    public function existing_short_password_account_can_still_login(): void
    {
        User::create([
            'LoginName' => 'old-user@x.com',
            'Name'      => 'OldUser',
            'PSW'       => password_hash('1234', PASSWORD_DEFAULT),
            'type'      => 'A',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'account'  => 'old-user@x.com',
            'password' => '1234',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.session.token_type', 'Bearer');
    }
}
