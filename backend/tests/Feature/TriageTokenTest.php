<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleRequestsByIp;
use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\BugReportComment;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Read-only AI triage token (config/triage_tokens.php). CI only. */
final class TriageTokenTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    public function test_super_admin_creates_token_shown_once_and_stored_only_as_hash(): void
    {
        $admin = $this->user('S');
        $created = $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'cloud triage'], $this->as($admin));

        $created->assertCreated();
        $this->assertStringContainsString('no-store', (string) $created->headers->get('Cache-Control'));
        $token = (string) $created->json('token');
        $prefix = (string) config('triage_tokens.secret_prefix');
        $this->assertMatchesRegularExpression('/^\d+\|' . preg_quote($prefix, '/') . '[A-Za-z0-9]{40}$/', $token);
        [$id, $secret] = explode('|', $token, 2);

        $row = DB::table('personal_access_tokens')->where('id', $id)->first();
        $this->assertSame(hash('sha256', $secret), $row->token);
        $this->assertSame([config('triage_tokens.ability')], json_decode($row->abilities, true));

        $list = $this->getJson('/api/v1/admin/triage-tokens', $this->as($admin))->assertOk();
        $list->assertJsonPath('data.0.name', 'cloud triage')
            ->assertJsonPath('data.0.last_used_at', null)
            ->assertJsonStructure(['data' => [['id', 'name', 'created_at', 'last_used_at', 'expires_at', 'expired']]]);
        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertStringNotContainsString(hash('sha256', $secret), $list->getContent());
        $this->assertSame(1, $this->auditCount('triage_token.created'));
    }

    public function test_non_super_admin_cannot_create_list_or_revoke(): void
    {
        $token = $this->createToken($this->user('S'));
        $id = (int) explode('|', $token)[0];

        foreach (['A', 'T'] as $type) {
            $user = $this->user($type);
            $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'x'], $this->as($user))->assertForbidden();
            $this->getJson('/api/v1/admin/triage-tokens', $this->as($user))->assertForbidden();
            $this->deleteJson("/api/v1/admin/triage-tokens/{$id}", [], $this->as($user))->assertForbidden();
        }
        ($suspended = $this->user('S'))->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/v1/admin/triage-tokens', $this->as($suspended))->assertForbidden();
        $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'x'], $this->as($suspended))->assertForbidden();
        // The token cannot mint or revoke tokens either.
        $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'x'], $this->bearer($token))->assertForbidden();
        $this->deleteJson("/api/v1/admin/triage-tokens/{$id}", [], $this->bearer($token))->assertForbidden();
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_token_reads_list_detail_and_attachment_and_every_request_is_audited(): void
    {
        Storage::fake('public');
        $token = $this->createToken($this->user('S'));
        $reporter = $this->user('T');
        $old = $this->bug($reporter, 'new');
        $bug = $this->bug($reporter, 'new');
        $this->bug($reporter, 'closed');
        BugReportComment::create(['bug_report_id' => $bug->id, 'author_user_id' => $reporter->id, 'body' => 'internal', 'is_internal_note' => true]);
        Storage::disk('public')->put("bug-reports/{$bug->id}/shot.png", 'PNGDATA');
        $att = BugReportAttachment::create(['bug_report_id' => $bug->id, 'stored_path' => "bug-reports/{$bug->id}/shot.png", 'original_name' => 'shot.png', 'mime_type' => 'image/png', 'size' => 7]);

        $list = $this->getJson("/api/v1/triage/bug-reports?status=new,triaged&since_id={$old->id}", $this->bearer($token));
        $list->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bug->id)
            ->assertJsonPath('next_since_id', $bug->id);
        $this->getJson('/api/v1/triage/bug-reports?status=bogus', $this->bearer($token))->assertStatus(422);

        $this->getJson("/api/v1/triage/bug-reports/{$bug->id}", $this->bearer($token))->assertOk()
            ->assertJsonPath('comments.0.body', 'internal')
            ->assertJsonPath('attachments.0.url', null)
            ->assertJsonPath('attachments.0.download_path', "/api/v1/triage/bug-reports/{$bug->id}/attachments/{$att->id}");

        $download = $this->get("/api/v1/triage/bug-reports/{$bug->id}/attachments/{$att->id}", $this->bearer($token));
        $download->assertOk();
        $this->assertSame('PNGDATA', $download->streamedContent());
        $this->getJson("/api/v1/triage/bug-reports/{$old->id}/attachments/{$att->id}", $this->bearer($token))->assertNotFound();

        $tokenId = (int) explode('|', $token)[0];
        $this->assertNotNull(DB::table('personal_access_tokens')->where('id', $tokenId)->value('last_used_at'));
        $rows = DB::table('security_audit_events')->where('event_type', 'triage_token.read')->orderBy('id')->get();
        $this->assertCount(5, $rows);
        $detail = json_decode($rows[2]->metadata, true);
        $this->assertSame(SecurityAuditEvent::ref('triage_token', $tokenId), $rows[2]->actor_ref);
        $this->assertSame(SecurityAuditEvent::ref('bug_report', $bug->id), $rows[2]->subject_ref);
        $this->assertSame(['success', 'failure'], [$rows[2]->outcome, $rows[4]->outcome]);
        $this->assertSame('triage.bug-reports.show', $detail['route']);
        $this->assertSame(SecurityAuditEvent::ref('ip', '127.0.0.1'), $detail['client_ip_ref']);
        $this->assertSame(200, $detail['http_status']);
        $this->assertStringNotContainsString('127.0.0.1', $rows[2]->metadata);
        $this->assertNotNull($rows[2]->occurred_at);
    }

    public function test_token_gets_403_on_every_api_route_outside_the_allowlist(): void
    {
        $this->withoutMiddleware(ThrottleRequestsByIp::class);
        $token = $this->createToken($this->user('S'));
        $allowed = (array) config('triage_tokens.allowed_routes');
        $checked = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/') || in_array($route->getName(), $allowed, true)) {
                continue;
            }
            $method = $route->methods()[0];
            $uri = '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri());
            if (!$route->matches(Request::create($uri, $method))) {
                continue; // dummy value does not satisfy a where() constraint
            }
            $status = $this->json($method, $uri, [], $this->bearer($token))->getStatusCode();
            $this->assertSame(403, $status, "{$method} {$uri} must reject a triage token");
            $checked[] = "{$method} {$route->uri()}";
        }

        $this->assertGreaterThan(200, count($checked));
        foreach ([
            'POST api/v1/bugs/{id}/status', 'POST api/v1/bugs/{id}/comments', 'GET api/v1/bugs',
            'GET api/v1/admin/campuses', 'POST api/v1/admin/reset-data', 'GET api/v1/admin/bug-reports',
            'GET api/v1/invoices', 'POST api/v1/invoices/{invoice}/payments', 'GET api/v1/finance/summary',
            'GET api/v1/me', 'GET api/v1/admin/triage-tokens', 'POST api/v1/admin/triage-tokens',
        ] as $mustBeChecked) {
            $this->assertContains($mustBeChecked, $checked);
        }
        $this->assertGreaterThan(0, $this->auditCount('triage_token.scope_denied'));
    }

    public function test_refused_token_requests_are_answered_but_their_audit_rows_are_capped(): void
    {
        $this->withoutMiddleware(ThrottleRequestsByIp::class);
        $forged = ['Authorization' => 'Bearer 1|' . config('triage_tokens.secret_prefix') . 'forged'];
        $expired = $this->bearer($this->createToken($this->user('S')));
        $this->travel(100)->days(); // past ttl_days: resolve() still finds it, so it shares the cap with forged bearers
        foreach (array_merge(...array_fill(0, 15, ['/api/v1/me', '/api/v1/invoices', '/api/v1/triage/bug-reports'])) as $uri) { $this->getJson($uri, $forged)->assertStatus(str_contains($uri, 'triage') ? 401 : 403); $this->getJson('/api/v1/bugs', $expired)->assertForbidden(); $this->getJson('/api/v1/triage/bug-reports', $expired)->assertUnauthorized(); }
        $valid = $this->bearer($this->createToken($this->user('S')));
        foreach (range(1, 15) as $i) { $this->getJson('/api/v1/me', $valid)->assertForbidden(); } // a valid token's scope denials share the cap too
        $this->getJson('/api/v1/triage/bug-reports', $valid)->assertOk(); // successful reads are always audited
        $this->assertSame(11, $this->auditCount('triage_token.scope_denied') + $this->auditCount('triage_token.read'));
    }

    public function test_expiry_is_fixed_at_issue_and_config_can_only_shorten_it(): void
    {
        config(['triage_tokens.ttl_days' => 42]);
        $admin = $this->user('S');
        $token = $this->createToken($admin);
        $id = (int) explode('|', $token)[0];
        $listed = $this->getJson('/api/v1/admin/triage-tokens', $this->as($admin))->json('data.0');
        $this->assertSame(42, now()->parse($listed['created_at'])->diffInDays(now()->parse($listed['expires_at'])));
        $this->assertNotNull(DB::table('personal_access_tokens')->where('id', $id)->value('expires_at'));

        $this->travel(10)->days();
        config(['triage_tokens.ttl_days' => 7]); // shortening applies at once (fail-safe)
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))
            ->assertStatus(401)->assertJsonPath('code', 'token_expired');
        config(['triage_tokens.ttl_days' => 30]); // within the issued 42 days: back to the issued expiry
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))->assertOk();

        // #4021: raising ttl_days does not extend a live token ...
        config(['triage_tokens.ttl_days' => 180]);
        $this->assertSame($listed['expires_at'], $this->getJson('/api/v1/admin/triage-tokens', $this->as($admin))->json('data.0.expires_at'));
        // ... nor revive an expired one.
        $this->travel(40)->days();
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))
            ->assertStatus(401)->assertJsonPath('code', 'token_expired');

        // A token issued after the change gets the new TTL.
        $fresh = $this->createToken($admin);
        $row = collect($this->getJson('/api/v1/admin/triage-tokens', $this->as($admin))->json('data'))
            ->firstWhere('id', (int) explode('|', $fresh)[0]);
        $this->assertSame(180, now()->parse($row['created_at'])->diffInDays(now()->parse($row['expires_at'])));
        $this->assertFalse($row['expired']);
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($fresh))->assertOk();
    }

    public function test_token_issued_before_expires_at_existed_is_capped_at_the_launch_ttl(): void
    {
        config(['triage_tokens.ttl_days' => 365]);
        $token = $this->createToken($this->user('S'));
        DB::table('personal_access_tokens')->where('id', (int) explode('|', $token)[0])->update(['expires_at' => null]);

        $this->travel(89)->days();
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))->assertOk();
        $this->travel(2)->days();
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))
            ->assertStatus(401)->assertJsonPath('code', 'token_expired');
    }

    public function test_attachment_read_that_throws_is_still_audited(): void
    {
        $token = $this->createToken($this->user('S'));
        $bug = $this->bug($this->user('T'), 'new');
        $att = BugReportAttachment::create(['bug_report_id' => $bug->id, 'stored_path' => "bug-reports/{$bug->id}/shot.png", 'original_name' => 'shot.png', 'mime_type' => 'image/png', 'size' => 7]);
        $disk = \Mockery::mock();
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('get')->andThrow(new \RuntimeException('disk read failed'));
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->getJson("/api/v1/triage/bug-reports/{$bug->id}/attachments/{$att->id}", $this->bearer($token))->assertStatus(500);

        $rows = DB::table('security_audit_events')->where('event_type', 'triage_token.read')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('failure', $rows[0]->outcome);
        $meta = json_decode($rows[0]->metadata, true);
        $this->assertSame([500, 'read_failed', 'triage.bug-reports.attachment'], [$meta['http_status'], $meta['reason_code'], $meta['route']]);
    }

    public function test_revoked_token_and_session_tokens_are_rejected(): void
    {
        $admin = $this->user('S');
        $token = $this->createToken($admin);
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))->assertOk();

        $session = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $admin->id, 'token' => $session, 'expires_at' => now()->addDay()]);
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($session))->assertUnauthorized();

        $id = (int) explode('|', $token)[0];
        $this->deleteJson("/api/v1/admin/triage-tokens/{$id}", [], $this->as($admin))->assertOk();
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))->assertUnauthorized();
        $this->assertSame(1, $this->auditCount('triage_token.revoked'));
        $this->getJson('/api/v1/admin/triage-tokens', $this->as($admin))->assertJsonCount(0, 'data');
    }

    public function test_token_stops_working_when_owner_is_suspended_or_demoted(): void
    {
        $admin = $this->user('S');
        $token = $this->createToken($admin);
        $admin->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))->assertForbidden();
        $admin->forceFill(['status' => null, 'type' => 'A'])->save();
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer($token))->assertForbidden();
        $this->postJson('/api/v1/triage/bug-reports', [], $this->bearer($token))->assertStatus(405);
    }

    private function createToken(User $admin): string
    {
        return (string) $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'ci'], $this->as($admin))
            ->assertCreated()->json('token');
    }

    private function user(string $type): User
    {
        $n = ++$this->seq;
        return User::create(['LoginName' => "triage{$n}@test.com", 'Name' => "Triage {$n}", 'PSW' => 'secret', 'type' => $type, 'phone' => (string) (900000000 + $n)]);
    }

    private function bug(User $reporter, string $status): BugReport
    {
        return BugReport::create(['CampusID' => 1, 'reporter_user_id' => $reporter->id, 'title' => "bug {$status}", 'description' => 'desc', 'severity' => 'medium', 'status' => $status]);
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        return ['X-User-Id' => (string) $user->id, 'Accept' => 'application/json'];
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function auditCount(string $event): int
    {
        return DB::table('security_audit_events')->where('event_type', $event)->count();
    }
}
