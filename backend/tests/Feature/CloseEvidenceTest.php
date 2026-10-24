<?php

namespace Tests\Feature;

use App\Services\Billing\OrdinaryCloseEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CloseEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_trip_preserves_nested_and_long_snapshot_values(): void
    {
        $payload = $this->payload();
        $payload['guard_fact_ids']['invoices'] = range(1, 80);
        $payload['cancelled_sessions'][] = $this->sessionEvidence(19, ' [結案取消]');
        $service = new OrdinaryCloseEvidence();
        $id = $this->appendEvidence($service, $payload);

        $this->assertEquals($payload, $service->read($id, 34, 12));
        $row = DB::table('security_audit_events')->where('id', $id)->first();
        $this->assertSame('student_class.ordinary_close.v1', $row->event_type);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(12, (int) $row->campus_id);
        $this->assertNotSame('77', $row->actor_ref);
        $this->assertStringNotContainsString('private note', (string) $row->metadata);
    }

    public function test_reader_refuses_tampering_wrong_owner_or_wrong_campus(): void
    {
        $service = new OrdinaryCloseEvidence();
        $id = $this->appendEvidence($service, $this->payload());
        $this->assertNull($service->read($id, 35, 12));
        $this->assertNull($service->read($id, 34, 13));

        $row = DB::table('security_audit_events')->where('id', $id)->first();
        $metadata = json_decode($row->metadata, true);
        $metadata['ordinary_close_evidence']['payload']['lifecycle_before']['end_date'] = '2038-12-31';
        DB::table('security_audit_events')->where('id', $id)->update(['metadata' => json_encode($metadata)]);
        $this->assertNull($service->read($id, 34, 12));

        $metadata['ordinary_close_evidence']['version'] = 2;
        DB::table('security_audit_events')->where('id', $id)->update(['metadata' => json_encode($metadata)]);
        $this->assertNull($service->read($id, 34, 12));
    }

    public function test_writer_refuses_unsupported_version_schema_and_raw_pii(): void
    {
        $service = new OrdinaryCloseEvidence();
        $payload = $this->payload();
        $payload['schema_version'] = 2;
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));

        $payload = $this->payload();
        $payload['cancelled_sessions'][] = $this->sessionEvidence(19, 'tag') + ['note_text' => 'private note'];
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));

        $payload = $this->payload();
        $payload['cancelled_sessions'][] = $this->sessionEvidence(19, 'tag');
        $payload['close_uuid'] = (string) Str::uuid();
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
    }

    public function test_required_insert_failure_throws_and_caller_transaction_rolls_back(): void
    {
        $service = new OrdinaryCloseEvidence();
        $armed = true;
        DB::connection()->beforeExecuting(function ($query) use (&$armed) {
            if ($armed && stripos((string) $query, 'insert') !== false
                && stripos((string) $query, 'security_audit_events') !== false) {
                throw new RuntimeException('forced evidence insert failure');
            }
        });

        try {
            try {
                DB::transaction(function () use ($service) {
                    DB::table('security_audit_events')->insert([
                        'event_type' => 'ordinary_close_probe', 'correlation_id' => (string) Str::uuid(),
                        'occurred_at' => now(), 'outcome' => 'success',
                    ]);
                    $service->append($this->payload(), 77, 12, 34);
                });
                $this->fail('Required evidence insert failure must escape to abort the transaction.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('forced evidence insert failure', $e->getMessage());
            }
        } finally {
            $armed = false;
        }
        $this->assertSame(0, DB::table('security_audit_events')->count());
    }

    public function test_duplicate_close_uuid_is_rejected(): void
    {
        $service = new OrdinaryCloseEvidence();
        $payload = $this->payload();
        $this->appendEvidence($service, $payload);
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
    }

    private function payload(): array
    {
        return [
            'close_uuid' => (string) Str::uuid(), 'student_class_id' => 34,
            'student_id' => 56, 'campus_id' => 12, 'schedule_mode' => 'count',
            'close_reason' => 'settled',
            'lifecycle_before' => ['stop' => 0, 'closed_reason' => null, 'end_date' => null, 'mdate' => '2026-10-24 08:00:00'],
            'lifecycle_after' => ['stop' => 1, 'closed_reason' => 'settled', 'end_date' => '2030-01-01', 'mdate' => '2026-10-24 08:01:00'],
            'cancel_mode' => 'cancel_remaining', 'cancelled_sessions' => [],
            'guard_digest' => hash('sha256', 'synthetic guard'),
            'guard_fact_ids' => [
                'invoices' => [], 'invoice_items' => [], 'payments' => [], 'payment_reports' => [],
                'deduction_events' => [], 'sign_ins' => [], 'learning_records' => [],
                'entitlement_changes' => [], 'pricing_amendments' => [], 'group_memberships' => [],
                'sessions' => [], 'schedules' => [], 'successor_courses' => [],
            ],
        ];
    }

    private function sessionEvidence(int $id, string $suffix): array
    {
        return [
            'id' => $id, 'owner_id' => 34, 'date' => '2030-01-01', 'start' => '09:00:00', 'end' => '10:00:00',
            'before_status' => 'scheduled', 'after_status' => 'cancelled',
            'note' => [
                'was_null' => false, 'byte_length' => 7, 'sha256' => hash('sha256', 'before'),
                'suffix' => $suffix, 'after_sha256' => hash('sha256', 'after'),
            ],
        ];
    }

    private function assertEvidenceThrows(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected evidence validation to reject the input.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    private function appendEvidence(OrdinaryCloseEvidence $service, array $payload): int
    {
        return DB::transaction(fn () => $service->append($payload, 77, 12, 34));
    }
}
