<?php

namespace Tests\Feature;

use App\Models\SecurityAuditEvent;
use Database\Factories\CampusFactory;
use Database\Factories\UserCampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1813: super_admin resetting a director password must leave a
 * PII-minimized security_audit_events row. Temporary password must never
 * appear in metadata.
 */
class DirectorPasswordResetAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_writes_security_audit_event_without_secret(): void
    {
        $campus = CampusFactory::new()->create();
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $director = UserFactory::new()->director()->create([
            'LoginName' => 'reset.audit@example.com',
            'Name' => '待重設主任',
        ]);
        UserCampusFactory::new()->create([
            'UserID' => $director->id,
            'CampusID' => $campus->id,
            'Admin' => 1,
            'Approved' => true,
        ]);

        $res = $this->postJson("/api/v1/directors/{$director->id}/reset-password", [], [
            'X-User-Id' => (string) $superAdmin->id,
        ]);
        $res->assertOk();
        $temp = (string) $res->json('temporary_password');
        $this->assertNotSame('', $temp);

        $row = DB::table('security_audit_events')
            ->where('event_type', 'director.password.reset')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(SecurityAuditEvent::ref('user', $superAdmin->id), $row->actor_ref);
        $this->assertSame(SecurityAuditEvent::ref('user', $director->id), $row->subject_ref);
        $this->assertSame((int) $campus->id, (int) $row->campus_id);

        $meta = json_decode($row->metadata, true);
        $this->assertSame('admin_reset', $meta['reason_code'] ?? null);
        $this->assertSame('super_admin', $meta['method'] ?? null);
        $this->assertArrayNotHasKey('temporary_password', $meta);
        $this->assertStringNotContainsString($temp, (string) $row->metadata);
        $this->assertStringNotContainsString('待重設', (string) $row->metadata);
        $this->assertStringNotContainsString('reset.audit', (string) $row->metadata);
    }
}
