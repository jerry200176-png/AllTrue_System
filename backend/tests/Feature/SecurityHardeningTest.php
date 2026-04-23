<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 4 [TEST] — Security Hardening v1.0
 *
 * Coverage:
 *   SEC-002  FR-001 / FR-002  register throttle (auth + directors)
 *   SEC-003  FR-003           forgot-password throttle
 *   SEC-004  FR-007~009       password min:8 (register / change / profile)
 *   SEC-006  FR-004 / FR-005  swipe-rfid throttle
 *   Regression FR-011         old short-password accounts can still login
 *
 * NOTE: HTTP security headers (SEC-005) are set by Apache .htaccess and are
 * not forwarded by the Laravel test HTTP client (which bypasses Apache).
 * Header presence is verified in [OPS] via `curl -I`.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();

        // Flush rate-limiter state between tests so throttle counters start fresh.
        Cache::flush();

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

    // ─── SEC-002 / FR-001: auth/register throttle ────────────────────────────

    /** @test */
    public function register_throttle_blocks_after_10_requests(): void
    {
        // 10 requests should pass (422 or other, not 429)
        for ($i = 1; $i <= 10; $i++) {
            $res = $this->postJson('/api/v1/auth/register', [
                'account'  => "throttle-test-{$i}@example.com",
                'password' => 'Password1!',
                'name'     => "User{$i}",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} should not be throttled yet");
        }

        // 11th request should be throttled
        $res = $this->postJson('/api/v1/auth/register', [
            'account'  => 'throttle-test-11@example.com',
            'password' => 'Password1!',
            'name'     => 'User11',
        ]);
        $res->assertStatus(429);
    }

    /** @test */
    public function register_throttle_response_contains_retry_after_header(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $res = $this->postJson('/api/v1/auth/register', [
                'account'  => "hdr-test-{$i}@example.com",
                'password' => 'Password1!',
                'name'     => "Hdr{$i}",
            ]);
        }
        $this->assertNotNull(
            $res->headers->get('Retry-After'),
            'HTTP 429 response must include Retry-After header'
        );
    }

    // ─── SEC-002 / FR-002: directors/register throttle ───────────────────────

    /** @test */
    public function directors_register_throttle_blocks_after_10_requests(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $res = $this->postJson('/api/v1/directors/register', [
                'account'   => "dir-throttle-{$i}@example.com",
                'password'  => 'Password1!',
                'name'      => "Dir{$i}",
                'campus_id' => $this->campus->id,
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} should not be throttled yet");
        }

        $res = $this->postJson('/api/v1/directors/register', [
            'account'   => 'dir-throttle-11@example.com',
            'password'  => 'Password1!',
            'name'      => 'Dir11',
            'campus_id' => $this->campus->id,
        ]);
        $res->assertStatus(429);
    }

    // ─── SEC-003 / FR-003: forgot-password throttle ──────────────────────────

    /** @test */
    public function forgot_password_throttle_blocks_after_5_requests(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->postJson('/api/v1/auth/forgot-password', [
                'email' => "fp-{$i}@example.com",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} should not be throttled yet");
        }

        $res = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'fp-6@example.com',
        ]);
        $res->assertStatus(429);
    }

    // ─── SEC-006 / FR-004: swipe-rfid throttle ───────────────────────────────

    /** @test */
    public function swipe_rfid_throttle_blocks_after_30_requests(): void
    {
        $headers = [
            'Authorization' => 'Bearer sec-test-token-abc',
            'Accept'        => 'application/json',
        ];

        for ($i = 1; $i <= 30; $i++) {
            $res = $this->withHeaders($headers)->postJson('/api/v1/swipe-rfid', [
                'branch_code' => (string) $this->campus->id,
                'rfid'        => "RFID-SCAN-{$i}",
            ]);
            $this->assertNotEquals(429, $res->status(), "Request {$i} should not be throttled yet");
        }

        $res = $this->withHeaders($headers)->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id,
            'rfid'        => 'RFID-SCAN-31',
        ]);
        $res->assertStatus(429);
    }

    // ─── SEC-004 / FR-007: auth/register rejects password < 8 chars ──────────

    /** @test */
    public function register_rejects_password_shorter_than_8(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'account'  => 'shortpwd@example.com',
            'password' => 'Abc123!',  // 7 chars
            'name'     => 'ShortPwd',
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    /** @test */
    public function register_accepts_password_exactly_8_chars(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'account'  => 'exactpwd@example.com',
            'password' => 'Abc1234!',  // exactly 8 chars
            'name'     => 'ExactPwd',
        ]);
        // 201 created or other non-422 (could be 422 for other validation, but NOT password)
        $this->assertNotEquals(422, $res->status(),
            'Should not reject an 8-char password');
        if ($res->status() === 422) {
            $this->assertArrayNotHasKey('password', $res->json('errors') ?? []);
        }
    }

    // ─── SEC-004 / FR-008: directors/register rejects password < 8 chars ─────

    /** @test */
    public function directors_register_rejects_password_shorter_than_8(): void
    {
        $res = $this->postJson('/api/v1/directors/register', [
            'account'   => 'dir-short@example.com',
            'password'  => 'Ab12345',  // 7 chars
            'name'      => 'DirShort',
            'campus_id' => $this->campus->id,
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004 / FR-009: PUT /api/v1/me rejects new password < 8 chars ─────

    /** @test */
    public function update_me_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson('/api/v1/me', [
            'current_password' => 'Password1!',
            'password'         => 'Short1!',        // 7 chars
            'password_confirmation' => 'Short1!',
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004: ProfileController::store rejects password < 8 ─────────────

    /** @test */
    public function profile_store_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/profiles', [
            'account'  => 'new-teacher@example.com',
            'password' => 'Short1!',   // 7 chars
            'name'     => 'NewTeacher',
            'role'     => 'teacher',
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    // ─── SEC-004: ProfileController::update rejects password < 8 ────────────

    /** @test */
    public function profile_update_rejects_password_shorter_than_8(): void
    {
        [$token] = $this->makeDirectorToken();

        // Create a teacher to update
        $teacher = User::create([
            'LoginName' => 'teacher-to-update@x.com',
            'Name'      => 'TeacherUpdate',
            'PSW'       => password_hash('Password1!', PASSWORD_DEFAULT),
            'type'      => 'T',
        ]);
        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $teacher->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->putJson("/api/v1/profiles/{$teacher->id}", [
            'password' => 'Short1!',   // 7 chars
        ]);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['password']);
    }

    // ─── FR-011 (Regression): existing short-password accounts can still login

    /** @test */
    public function existing_short_password_account_can_still_login(): void
    {
        // Simulate a pre-existing account with a 4-char password (bcrypt of '1234')
        User::create([
            'LoginName' => 'old-user@example.com',
            'Name'      => 'OldUser',
            'PSW'       => password_hash('1234', PASSWORD_DEFAULT),
            'type'      => 'A',
        ]);

        // Login should succeed — password min:8 does NOT affect the login path
        $res = $this->postJson('/api/v1/auth/login', [
            'account'  => 'old-user@example.com',
            'password' => '1234',
        ]);
        $res->assertStatus(200);
        $res->assertJsonPath('data.session.token_type', 'Bearer');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a director user + AuthToken for authenticated endpoint tests.
     * Returns [$token, $user].
     */
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
}
