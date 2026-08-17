<?php

namespace Tests\Feature;

use App\Models\SecurityAuditEvent;
use App\Models\User;
use Database\Factories\CampusFactory;
use Database\Factories\UserCampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1810: super_admin approve/reject of director applications must leave
 * a PII-minimized security_audit_events row (who / when / old→new type).
 */
class DirectorAccountAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_writes_security_audit_event(): void
    {
        $campus = CampusFactory::new()->create();
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $pending = UserFactory::new()->pendingDirector()->create([
            'LoginName' => 'approve.audit@example.com',
        ]);
        UserCampusFactory::new()->create([
            'UserID' => $pending->id,
            'CampusID' => $campus->id,
            'Admin' => 0,
            'Approved' => false,
        ]);

        $res = $this->postJson("/api/v1/directors/{$pending->id}/approve", [], [
            'X-User-Id' => (string) $superAdmin->id,
        ]);
        $res->assertOk();

        $row = DB::table('security_audit_events')
            ->where('event_type', 'director.account.approved')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(SecurityAuditEvent::ref('user', $superAdmin->id), $row->actor_ref);
        $this->assertSame(SecurityAuditEvent::ref('user', $pending->id), $row->subject_ref);
        $this->assertSame((int) $campus->id, (int) $row->campus_id);

        $meta = json_decode($row->metadata, true);
        $this->assertSame('approve', $meta['reason_code'] ?? null);
        $this->assertSame('U', $meta['old_type'] ?? null);
        $this->assertSame('D', $meta['new_type'] ?? null);
        $this->assertArrayNotHasKey('Name', $meta);
        $this->assertStringNotContainsString('approve.audit', (string) $row->metadata);
    }

    public function test_reject_writes_security_audit_event_before_user_is_removed(): void
    {
        $campus = CampusFactory::new()->create();
        $superAdmin = UserFactory::new()->superAdmin()->create();
        $pending = UserFactory::new()->pendingDirector()->create([
            'LoginName' => 'reject.audit@example.com',
            'Name' => '待駁回主任',
        ]);
        UserCampusFactory::new()->create([
            'UserID' => $pending->id,
            'CampusID' => $campus->id,
            'Admin' => 0,
            'Approved' => false,
        ]);
        $pendingId = (int) $pending->id;

        $res = $this->postJson("/api/v1/directors/{$pendingId}/reject", [], [
            'X-User-Id' => (string) $superAdmin->id,
        ]);
        $res->assertOk();
        $this->assertDatabaseMissing('User', ['id' => $pendingId]);

        $row = DB::table('security_audit_events')
            ->where('event_type', 'director.account.rejected')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(SecurityAuditEvent::ref('user', $superAdmin->id), $row->actor_ref);
        $this->assertSame(SecurityAuditEvent::ref('user', $pendingId), $row->subject_ref);

        $meta = json_decode($row->metadata, true);
        $this->assertSame('reject', $meta['reason_code'] ?? null);
        $this->assertSame('U', $meta['old_type'] ?? null);
        $this->assertSame('deleted', $meta['new_type'] ?? null);
        $this->assertStringNotContainsString('待駁回', (string) $row->metadata);
        $this->assertStringNotContainsString('reject.audit', (string) $row->metadata);
    }
}
