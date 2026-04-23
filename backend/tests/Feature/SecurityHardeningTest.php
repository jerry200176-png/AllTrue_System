<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 [TEST] — Security Hardening v1.0
 *
 * Coverage:
 *   SEC-002  FR-001/002  register throttle (auth + directors)
 *   SEC-003  FR-003      forgot-password throttle
 *   SEC-004  FR-007~010  password min:8
 *   SEC-006  FR-004/005  swipe-rfid throttle
 *   Regression FR-011    existing short-password accounts can still login
 *
 * Throttle tests each use a unique spoofed IP so cross-test cache state
 * never pollutes sibling tests. Cache::flush() is intentionally NOT used
 * because it is unreliable when the file cache driver is active.
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
     * Return a unique RFC-5737 test IP so each throttle test gets its own
     * rate-limiter bucket and never inherits counts from another test.
     */
    private function uniqueIp(): string
    {
        static $n = 0;
        $n++;
        // 192.0.2.0/24 is reserved for documentation/testing (RFC 5737)
        return '192.0.2.' . ($n % 254 + 1);
    }

    private function jsonWithIp(string $ip): self
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function makeDirectorToken(): array
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'LoginName'          => "sec-director-{$n}@example.com",
            'Name'               => "SecDirector{$n}",
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
        $ip = $this->uniqueIp();

        for ($i = 1; $i <= 10; $i++) {
            $res = $this->jsonWithIp($ip)->postJson('/api/v1/auth/register', [
                'account'  => "throttle-reg-{$i}@x.com",
                'password' => 'Password1!',
                'name'     => "U{$i}",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $res = $this->jsonWithIp($ip)->postJson('/api/v1/auth/register', [
            'account'  => 'throttle-reg-11@x.com',
            'password' => 'Password1!',
            'name'     => 'U11',
        ]);
        $res->assertStatus(429);
        $this->assertNotNull($res->headers->get('Retry-After'));
    }

    // ─── SEC-002 / FR-002: directors/register throttle ────────────────────────

    /** @test */
    public function directors_register_throttle_blocks_after_10_requests(): void
    {
        $ip = $this->uniqueIp();

        for ($i = 1; $i <= 10; $i++) {
            $res = $this->jsonWithIp($ip)->postJson('/api/v1/directors/register', [
                'account'   => "dir-throttle-{$i}@x.com",
                'password'  => 'Password1!',
                'name'      => "D{$i}",
                'campus_id' => $this->campus->id,
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $res = $this->jsonWithIp($ip)->postJson('/api/v1/directors/register', [
            'account'   => 'dir-throttle-11@x.com',
            'password'  => 'Password1!',
            'name'      => 'D11',
            'campus_id' => $this->campus->id,
        ]);
        $res->assertStatus(429);
    }

    // ─── SEC-003 / FR-003: forgot-password throttle ───────────────────────────

    /** @test */
    public function forgot_password_throttle_blocks_after_5_requests(): void
    {
        $ip = $this->uniqueIp();

        for ($i = 1; $i <= 5; $i++) {
            $res = $this->jsonWithIp($ip)->postJson('/api/v1/auth/forgot-password', [
                'account' => "fp-{$i}@x.com",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $res = $this->jsonWithIp($ip)->postJson('/api/v1/auth/forgot-password', [
            'account' => 'fp-6@x.com',
        ]);
        $res->assertStatus(429);
    }

    // ─── SEC-006 / FR-004: swipe-rfid throttle ────────────────────────────────

    /** @test */
    public function swipe_rfid_throttle_blocks_after_30_requests(): void
    {
        $ip = $this->uniqueIp();

        for ($i = 1; $i <= 30; $i++) {
            $res = $this->jsonWithIp($ip)->withHeaders([
                'Authorization' => 'Bearer sec-test-token-abc',
            ])->postJson('/api/v1/swipe-rfid', [
                'branch_code' => (string) $this->campus->id,
                'rfid'        => "RFID-{$i}",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} throttled early");
        }

        $res = $this->jsonWithIp($ip)->withHeaders([
            'Authorization' => 'Bearer sec-test-token-abc',
        ])->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id,
            'rfid'        => 'RFID-31',
        ]);
        $res->assertStatus(429);
    }

    // ─── SEC-004 / FR-007: auth/register rejects password < 8 ────────────────

    /** @test */
    public function register_rejects_password_shorter_than_8(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'account'  => 'shortpwd@x.com',
            'password' => 'Abc123!',   // 7 chars
            'name'     => 'Short',
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    /** @test */
    public function register_accepts_password_of_8_chars(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'account'  => 'ok8pwd@x.com',
            'password' => 'Abc1234!',   // 8 chars
            'name'     => 'OkPwd',
        ]);
        if ($res->status() === 422) {
            $this->assertArrayNotHasKey('password', $res->json('errors') ?? []);
        }
    }

    // ─── SEC-004 / FR-008: directors/register rejects password < 8 ───────────

    /** @test */
    public function directors_register_rejects_password_shorter_than_8(): void
    {
        $res = $this->postJson('/api/v1/directors/register', [
            'account'   => 'dir-short@x.com',
            'password'  => 'Ab12345',   // 7 chars
            'name'      => 'DirShort',
            'campus_id' => $this->campus->id,
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004 / FR-009: PUT /me rejects new password < 8 ──────────────────

    /** @test */
    public function update_me_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson('/api/v1/me', [
                'current_password'      => 'Password1!',
                'password'              => 'Short1!',
                'password_confirmation' => 'Short1!',
            ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004: ProfileController::store rejects password < 8 ──────────────

    /** @test */
    public function profile_store_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/profiles', [
                'account'  => 'new-teacher@x.com',
                'password' => 'Short1!',
                'name'     => 'NewTeacher',
                'role'     => 'teacher',
            ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
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

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/profiles/{$teacher->id}", [
                'password' => 'Short1!',
            ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
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

        $res = $this->postJson('/api/v1/auth/login', [
            'account'  => 'old-user@x.com',
            'password' => '1234',
        ]);
        $res->assertStatus(200);
        $res->assertJsonPath('data.session.token_type', 'Bearer');
    }
}
