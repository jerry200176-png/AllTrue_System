<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder 2026-10-22: when a director or super_admin changes ANOTHER user's password, every session that
 * user holds ends (old bearer tokens get 401), the admin's own session keeps working, and one PII-minimized
 * security_audit_events row records it without the password, its hash or any token. Synthetic data only.
 */
class AdminPasswordChangeRevokesSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_director_setting_a_teachers_password_signs_the_teacher_out_and_keeps_the_director_in(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->user('A', [$campus->id]);
        $teacher = $this->user('T', [$campus->id]);
        $bystander = $this->user('T', [$campus->id]);
        $directorToken = $this->token($director);
        $teacherTokens = [$this->token($teacher), $this->token($teacher)];
        $bystanderToken = $this->token($bystander);

        $this->api($directorToken)->putJson("/api/v1/profiles/{$teacher->id}", ['password' => 'NewPassword123'])->assertOk();

        foreach ($teacherTokens as $old) {
            $this->api($old)->getJson('/api/v1/me')->assertStatus(401);
        }
        $this->api($directorToken)->getJson('/api/v1/me')->assertOk();
        $this->api($bystanderToken)->getJson('/api/v1/me')->assertOk();
        $this->assertTrue(password_verify('NewPassword123', (string) $teacher->fresh()->PSW));

        $row = $this->onlyAuditRow('staff.password.admin_changed');
        $this->assertSame(SecurityAuditEvent::ref('user', $director->id), $row->actor_ref);
        $this->assertSame(SecurityAuditEvent::ref('user', $teacher->id), $row->subject_ref);
        $this->assertSame((int) $campus->id, (int) $row->campus_id);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame('admin_password_set', $meta['reason_code'] ?? null);
        $this->assertSame('director', $meta['method'] ?? null);
        $this->assertSame('profiles.update', $meta['source'] ?? null);
        $this->assertSame(2, $meta['revoked_session_count'] ?? null);
        $this->assertNoSecrets($row, ['NewPassword123', (string) $teacher->fresh()->PSW, $directorToken, ...$teacherTokens]);
    }

    public function test_a_profile_edit_without_a_password_keeps_the_teacher_signed_in(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->user('A', [$campus->id]);
        $teacher = $this->user('T', [$campus->id]);
        $teacherToken = $this->token($teacher);

        $this->api($this->token($director))->putJson("/api/v1/profiles/{$teacher->id}", ['name' => '新名字'])->assertOk();

        $this->api($teacherToken)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'staff.password.admin_changed')->count());
    }

    public function test_a_director_resetting_a_teachers_password_signs_the_teacher_out(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->user('A', [$campus->id]);
        $teacher = $this->user('T', [$campus->id]);
        $directorToken = $this->token($director);
        $teacherToken = $this->token($teacher);

        $res = $this->api($directorToken)->postJson("/api/v1/profiles/{$teacher->id}/reset-password")->assertOk();
        $temporary = (string) $res->json('temporary_password');
        $this->assertNotSame('', $temporary);

        $this->api($teacherToken)->getJson('/api/v1/me')->assertStatus(401);
        $this->api($directorToken)->getJson('/api/v1/me')->assertOk();

        $row = $this->onlyAuditRow('staff.password.admin_changed');
        $this->assertSame(SecurityAuditEvent::ref('user', $teacher->id), $row->subject_ref);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame('admin_password_reset', $meta['reason_code'] ?? null);
        $this->assertSame('profiles.reset-password', $meta['source'] ?? null);
        $this->assertSame(1, $meta['revoked_session_count'] ?? null);
        $this->assertNoSecrets($row, [$temporary, (string) $teacher->fresh()->PSW, $directorToken, $teacherToken]);
    }

    public function test_a_director_of_another_campus_changes_nothing_and_signs_nobody_out(): void
    {
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $directorToken = $this->token($this->user('A', [$campus->id]));
        $teacher = $this->user('T', [$other->id]);
        $teacherToken = $this->token($teacher);
        $hashBefore = (string) $teacher->fresh()->PSW;

        $this->api($directorToken)->putJson("/api/v1/profiles/{$teacher->id}", ['password' => 'NewPassword123'])->assertForbidden();
        $this->api($directorToken)->postJson("/api/v1/profiles/{$teacher->id}/reset-password")->assertForbidden();

        $this->assertSame($hashBefore, (string) $teacher->fresh()->PSW);
        $this->assertSame(1, AuthToken::query()->where('user_id', $teacher->id)->count());
        $this->api($teacherToken)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'staff.password.admin_changed')->count());
    }

    public function test_super_admin_resetting_a_directors_password_signs_the_director_out(): void
    {
        $campus = CampusFactory::new()->create();
        $superToken = $this->token($this->user('S', []));
        $director = $this->user('D', [$campus->id]);
        $directorTokens = [$this->token($director), $this->token($director)];

        $res = $this->api($superToken)->postJson("/api/v1/directors/{$director->id}/reset-password")->assertOk();
        $temporary = (string) $res->json('temporary_password');

        foreach ($directorTokens as $old) {
            $this->api($old)->getJson('/api/v1/me')->assertStatus(401);
        }
        $this->api($superToken)->getJson('/api/v1/me')->assertOk();

        $row = $this->onlyAuditRow('director.password.reset');
        $this->assertSame(SecurityAuditEvent::ref('user', $director->id), $row->subject_ref);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame('admin_reset', $meta['reason_code'] ?? null);
        $this->assertSame(2, $meta['revoked_session_count'] ?? null);
        $this->assertNoSecrets($row, [$temporary, (string) $director->fresh()->PSW, $superToken, ...$directorTokens]);
    }

    public function test_a_director_cannot_reset_another_directors_password(): void
    {
        $campus = CampusFactory::new()->create();
        $callerToken = $this->token($this->user('A', [$campus->id]));
        $target = $this->user('D', [$campus->id]);
        $targetToken = $this->token($target);
        $hashBefore = (string) $target->fresh()->PSW;

        $this->api($callerToken)->postJson("/api/v1/directors/{$target->id}/reset-password")->assertForbidden();

        $this->assertSame($hashBefore, (string) $target->fresh()->PSW);
        $this->api($targetToken)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'director.password.reset')->count());
    }

    public function test_a_failed_audit_insert_rolls_back_the_password_and_the_sign_out(): void
    {
        $campus = CampusFactory::new()->create();
        $directorToken = $this->token($this->user('A', [$campus->id]));
        $superToken = $this->token($this->user('S', []));
        $teacher = $this->user('T', [$campus->id]);
        $director = $this->user('D', [$campus->id]);
        $fail = false;
        // Same synthetic fault as the billing fail-closed tests: the audit insert runs, then throws inside append().
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into `security_audit_events`')) {
                $fail = false;
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $attempts = [
            'profiles.update' => [$teacher, fn () => $this->api($directorToken)->putJson("/api/v1/profiles/{$teacher->id}", ['password' => 'NewPassword123'])],
            'profiles.reset-password' => [$teacher, fn () => $this->api($directorToken)->postJson("/api/v1/profiles/{$teacher->id}/reset-password")],
            'directors.reset-password' => [$director, fn () => $this->api($superToken)->postJson("/api/v1/directors/{$director->id}/reset-password")],
        ];

        foreach ($attempts as $label => [$target, $call]) {
            $targetToken = $this->token($target);
            $hashBefore = (string) $target->fresh()->PSW;
            $fail = true;
            try {
                $call()->assertStatus(500);
            } finally {
                $fail = false;
            }
            $this->assertSame($hashBefore, (string) $target->fresh()->PSW, "{$label}: password unchanged");
            $this->assertSame(1, AuthToken::query()->where('token', $targetToken)->count(), "{$label}: session kept");
            $this->api($targetToken)->getJson('/api/v1/me')->assertOk();
        }
        $this->assertSame(0, DB::table('security_audit_events')->whereIn('event_type', ['staff.password.admin_changed', 'director.password.reset'])->count());
    }

    public function test_super_admin_setting_their_own_password_here_keeps_their_session(): void
    {
        $super = $this->user('S', []);
        $superToken = $this->token($super);

        $this->api($superToken)->putJson("/api/v1/profiles/{$super->id}", ['password' => 'NewPassword123'])->assertOk();

        $this->api($superToken)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'staff.password.admin_changed')->count());
    }

    /** @param list<int> $campusIds */
    private function user(string $type, array $campusIds): User
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type,
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999),
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => 1]);
        }

        return $user;
    }

    private function token(User $user): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token): self
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function onlyAuditRow(string $eventType): object
    {
        $rows = DB::table('security_audit_events')->where('event_type', $eventType)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('success', $rows[0]->outcome);

        return $rows[0];
    }

    /** @param list<string> $secrets */
    private function assertNoSecrets(object $row, array $secrets): void
    {
        $serialized = json_encode((array) $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($secrets as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, (string) $serialized);
        }
    }
}
