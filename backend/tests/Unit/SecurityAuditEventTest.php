<?php

namespace Tests\Unit;

use App\Models\SecurityAuditEvent;
use Tests\TestCase;

class SecurityAuditEventTest extends TestCase
{
    public function test_refs_are_stable_hashed_and_not_plaintext(): void
    {
        $ref = SecurityAuditEvent::ref('line_user', 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $ref);
        $this->assertStringNotContainsString('Uaaaaaaaa', (string) $ref);
        $this->assertSame($ref, SecurityAuditEvent::ref('line_user', 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_metadata_allowlist_excludes_pii_and_unknown_values(): void
    {
        $metadata = SecurityAuditEvent::metadata([
            'method' => 'line',
            'student_name' => 'Alice',
            'phone' => '0912345678',
            'line_user_id' => 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'provider_status' => 200,
            'row_count' => 12,
            'export_format' => 'xlsx',
            'campus_scope' => 'restricted',
        ]);

        $metadata2 = SecurityAuditEvent::metadata([
            'old_session_count' => 8,
            'new_session_count' => 10,
            'old_remaining_sessions' => 3,
            'new_remaining_sessions' => 5,
            'phone' => '0912345678',
        ]);
        $this->assertSame([
            'old_session_count' => 8,
            'new_session_count' => 10,
            'old_remaining_sessions' => 3,
            'new_remaining_sessions' => 5,
        ], $metadata2);

        $this->assertSame([
            'method' => 'line',
            'provider_status' => 200,
            'row_count' => 12,
            'export_format' => 'xlsx',
            'campus_scope' => 'restricted',
        ], $metadata);
    }
}
