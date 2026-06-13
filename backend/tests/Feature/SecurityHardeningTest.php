<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleRequestsByIp;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // Replace the RateLimiter singleton with a fresh in-memory instance
        // for every test. This guarantees zero throttle counts regardless of
        // which cache driver is active in CI (file cache on disk persists
        // across tests; re-binding the singleton avoids that entirely).
        $this->app->instance(RateLimiter::class, new RateLimiter(new Repository(new ArrayStore())));

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
     * Reset the RateLimiter to a brand-new empty store before a throttle test.
     * This is belt-and-suspenders on top of the setUp() re-binding; it ensures
     * that requests made within the same test method (e.g., password tests that
     * run before throttle tests in the same test method) don't accumulate counts.
     */
    private function clearThrottle(): void
    {
        $this->app->instance(RateLimiter::class, new RateLimiter(new Repository(new ArrayStore())));
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

    // ─── SEC-F1: directors/register registration token gate ──────────────────

    /** @test */
    public function directors_register_returns_403_when_token_required_but_missing(): void
    {
        config(['app.director_registration_token' => 'secret-invite-abc']);

        $this->withoutMiddleware(\App\Http\Middleware\ThrottleRequestsByIp::class)
            ->postJson('/api/v1/directors/register', [
                'account'   => 'newdir@x.com',
                'password'  => 'Password1!',
                'name'      => 'NewDir',
                'campus_id' => $this->campus->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', '無效的邀請碼 (Invalid registration token)');
    }

    /** @test */
    public function directors_register_returns_403_when_wrong_token_provided(): void
    {
        config(['app.director_registration_token' => 'secret-invite-abc']);

        $this->withoutMiddleware(\App\Http\Middleware\ThrottleRequestsByIp::class)
            ->postJson('/api/v1/directors/register', [
                'account'            => 'newdir2@x.com',
                'password'           => 'Password1!',
                'name'               => 'NewDir2',
                'campus_id'          => $this->campus->id,
                'registration_token' => 'wrong-token',
            ])
            ->assertStatus(403);
    }

    /** @test */
    public function directors_register_succeeds_when_correct_token_provided(): void
    {
        config(['app.director_registration_token' => 'secret-invite-abc']);

        $this->withoutMiddleware(\App\Http\Middleware\ThrottleRequestsByIp::class)
            ->postJson('/api/v1/directors/register', [
                'account'            => 'newdir3@x.com',
                'password'           => 'Password1!',
                'name'               => 'NewDir3',
                'campus_id'          => $this->campus->id,
                'registration_token' => 'secret-invite-abc',
            ])
            ->assertStatus(201);
    }

    /** @test */
    public function directors_register_open_when_no_token_configured(): void
    {
        // Without DIRECTOR_REGISTRATION_TOKEN, endpoint is open (backward compatible).
        config(['app.director_registration_token' => null]);

        $this->withoutMiddleware(\App\Http\Middleware\ThrottleRequestsByIp::class)
            ->postJson('/api/v1/directors/register', [
                'account'   => 'opendir@x.com',
                'password'  => 'Password1!',
                'name'      => 'OpenDir',
                'campus_id' => $this->campus->id,
            ])
            ->assertStatus(201);
    }

    // ─── SEC-F3: /health public endpoint strips sensitive info ───────────────

    /** @test */
    public function health_public_endpoint_returns_only_status_and_timestamp(): void
    {
        $response = $this->getJson("/api/v1/health");

        $response->assertStatus(200)
            ->assertJsonStructure(["status", "timestamp"]);

        $json = $response->json();
        $this->assertArrayNotHasKey("perf_flags", $json, "perf_flags must not be exposed publicly");
        $this->assertArrayNotHasKey("log_pipeline", $json, "log_pipeline must not be exposed publicly");
        $this->assertArrayNotHasKey("sentry", $json, "sentry details must not be exposed publicly");
        $this->assertArrayNotHasKey("security", $json, "security.debug_mode must not be exposed publicly");
    }

    /** @test */
    public function health_detailed_requires_authentication(): void
    {
        $this->getJson('/api/v1/health/detailed')
            ->assertStatus(401);
    }

    /** @test */
    public function health_detailed_returns_operational_metrics_when_authenticated(): void
    {
        [$token] = $this->makeDirectorToken();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/health/detailed')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'timestamp', 'perf_flags', 'log_pipeline', 'sentry', 'security']);
    }
}
