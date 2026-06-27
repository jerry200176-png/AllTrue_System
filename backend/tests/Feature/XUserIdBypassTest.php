<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-AUDIT-001 (#970): X-User-Id / X-User-Role / X-Teacher-Id headers must be
 * honoured only in local/testing. In production, identity comes solely from a
 * valid Bearer token — otherwise any client can impersonate a user and escalate
 * role via headers.
 */
class XUserIdBypassTest extends TestCase
{
    use RefreshDatabase;

    private function withProductionEnv(callable $fn): void
    {
        $original = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $fn();
        } finally {
            $this->app['env'] = $original;
        }
    }

    public function test_spoofed_identity_headers_are_ignored_in_production(): void
    {
        $this->withProductionEnv(function () {
            // No Bearer token — only spoofed identity headers. With header auth
            // disabled in production, no auth_role is set → role-gated route 401.
            $res = $this->withHeaders([
                'X-User-Id'   => '1',
                'X-User-Role' => 'director',
            ])->getJson('/api/v1/alerts/tuition');

            $this->assertContains($res->status(), [401, 403], 'X-User-Id must not authenticate in production');
        });
    }

    public function test_valid_bearer_token_still_authenticates_in_production(): void
    {
        $director = User::create([
            'LoginName' => 'prod-bearer-' . uniqid() . '@test.com',
            'Name'      => 'Prod Director',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 912000888,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $this->withProductionEnv(function () use ($raw) {
            // Bearer path must remain fully functional in production.
            $res = $this->withToken($raw)->getJson('/api/v1/alerts/tuition');
            $this->assertNotContains($res->status(), [401, 403], 'Valid Bearer must authenticate in production');
        });
    }

    public function test_header_auth_still_works_in_testing_env(): void
    {
        // Default env is "testing" (phpunit.xml) — header auth remains available so
        // the existing header-based test (ResetDataAndDirectorFlowTest) keeps working.
        $director = User::create([
            'LoginName' => 'test-header-' . uniqid() . '@test.com',
            'Name'      => 'Test Director',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 912000889,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);

        $res = $this->withHeaders([
            'X-User-Id'   => (string) $director->id,
            'X-User-Role' => 'director',
        ])->getJson('/api/v1/alerts/tuition');

        $this->assertNotContains($res->status(), [401, 403], 'Header auth must still work in testing env');
    }
}
