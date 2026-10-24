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
        $payload['previous_pointer'] = [
            'kind' => 'ordinary_close_corrected_v1', 'close_uuid' => (string) Str::uuid(), 'correction_event_id' => 91,
        ];
        $payload['cancel_remaining'] = true;
        $payload['guard_fact_ids']['invoices'] = range(1, 80);
        $payload['guard_fact_ids']['sessions'] = range(1, 65);
        $payload['cancelled_sessions'] = array_map(
            fn (int $id) => $this->sessionEvidence($id, ' [結案取消]'), range(1, 65)
        );
        $service = new OrdinaryCloseEvidence();
        $id = $this->appendEvidence($service, $payload);

        $this->assertEquals($payload, $service->read($id, 34, 12));
        $row = DB::table('security_audit_events')->where('id', $id)->first();
        $this->assertSame('student_class.ordinary_close.v1', $row->event_type);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(12, (int) $row->campus_id);
        $this->assertNotSame('77', $row->actor_ref);
        $this->assertGreaterThan(120, strlen((string) $row->metadata));
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

    public function test_writer_refuses_unsupported_version_schema_and_free_text_fields(): void
    {
        $service = new OrdinaryCloseEvidence();
        $baseline = $this->validCancellationPayload();
        $validId = $this->appendEvidence($service, $baseline);
        $this->assertEquals($baseline, $service->read($validId, 34, 12));

        $payload = $baseline;
        $payload['close_uuid'] = (string) Str::uuid();
        $payload['schema_version'] = 2;
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));

        $payload = $baseline;
        $payload['close_uuid'] = (string) Str::uuid();
        $payload['cancelled_sessions'][0]['note_text'] = 'free text';
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));

        $payload = $baseline;
        $payload['close_uuid'] = (string) Str::uuid();
        $payload['cancelled_sessions'][0]['note']['suffix'] = 'unrecognized note marker';
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
        $this->assertSame(1, DB::table('security_audit_events')->count());
    }

    public function test_required_insert_failure_throws_and_caller_transaction_rolls_back(): void
    {
        $service = new OrdinaryCloseEvidence();
        $armed = true;
        $appendAttempted = false;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$armed, &$appendAttempted) {
            if ($armed && stripos((string) $query, 'insert') !== false
                && stripos((string) $query, 'security_audit_events') !== false
                && in_array(OrdinaryCloseEvidence::EVENT_TYPE, $bindings, true)) {
                $appendAttempted = true;
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
        $this->assertTrue($appendAttempted, 'Fault injection must reach the typed evidence insert.');
        $this->assertSame(0, DB::table('security_audit_events')->count());
    }

    public function test_duplicate_close_uuid_is_rejected(): void
    {
        $service = new OrdinaryCloseEvidence();
        $payload = $this->payload();
        $this->appendEvidence($service, $payload);
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
    }

    public function test_reader_refuses_duplicate_close_uuid_rows(): void
    {
        $service = new OrdinaryCloseEvidence();
        $id = $this->appendEvidence($service, $this->payload());
        $row = (array) DB::table('security_audit_events')->where('id', $id)->first();
        unset($row['id']);
        DB::table('security_audit_events')->insert($row);

        $this->assertNull($service->read($id, 34, 12));
    }

    public function test_writer_rejects_mismatched_binding_before_commit(): void
    {
        $service = new OrdinaryCloseEvidence();
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $this->payload(), 35, 12));
        $this->assertSame(0, DB::table('security_audit_events')->count());
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $this->payload(), 34, 13));
        $this->assertSame(0, DB::table('security_audit_events')->count());
    }

    public function test_writer_rejects_invalid_session_dates_times_states_and_note_suffix(): void
    {
        $service = new OrdinaryCloseEvidence();
        $baseline = $this->payload();
        $baseline['cancel_remaining'] = true;
        $baseline['guard_fact_ids']['sessions'] = [19];
        $baseline['cancelled_sessions'] = [$this->sessionEvidence(19, ' [結案取消]')];
        $validId = $this->appendEvidence($service, $baseline);
        $this->assertEquals($baseline, $service->read($validId, 34, 12));

        $cases = [
            ['date' => '2030-02-30'], ['start' => '25:00:00'],
            ['owner_id' => 35], ['before_status' => 'free text'], ['after_status' => 'attended'],
            ['suffix' => 'unrecognized note marker'],
        ];
        foreach ($cases as $changes) {
            $payload = $baseline;
            $payload['close_uuid'] = (string) Str::uuid();
            $session = $payload['cancelled_sessions'][0];
            if (array_key_exists('suffix', $changes)) {
                $session['note']['suffix'] = $changes['suffix'];
                unset($changes['suffix']);
            }
            $payload['cancelled_sessions'][0] = array_merge($session, $changes);
            $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
            $this->assertSame(1, DB::table('security_audit_events')->count());
        }
    }

    public function test_cancelled_sessions_must_be_a_list_even_when_map_is_otherwise_valid(): void
    {
        $service = new OrdinaryCloseEvidence();
        $payload = $this->payload();
        $payload['cancel_remaining'] = true;
        $payload['guard_fact_ids']['sessions'] = [19];
        $payload['cancelled_sessions'] = ['person@example.test' => $this->sessionEvidence(19, ' [結案取消]')];

        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
        $this->assertSame(0, DB::table('security_audit_events')->count());
    }

    public function test_cancel_and_forfeit_flags_preserve_all_four_independent_combinations(): void
    {
        $service = new OrdinaryCloseEvidence();
        $incoherent = $this->payload();
        $incoherent['guard_fact_ids']['sessions'] = [19];
        $incoherent['cancelled_sessions'] = [$this->sessionEvidence(19, ' [結案取消]')];
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $incoherent));

        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$cancel, $forfeit]) {
            $payload = $this->payload();
            $payload['cancel_remaining'] = $cancel;
            $payload['forfeit_remaining'] = $forfeit;
            if ($cancel) {
                $payload['guard_fact_ids']['sessions'] = [19];
                $payload['cancelled_sessions'] = [$this->sessionEvidence(19, ' [結案取消]')];
            }
            $id = $this->appendEvidence($service, $payload);
            $read = $service->read($id, 34, 12);
            $this->assertSame($cancel, $read['cancel_remaining']);
            $this->assertSame($forfeit, $read['forfeit_remaining']);
            $this->assertCount($cancel ? 1 : 0, $read['cancelled_sessions']);
        }
        $this->assertSame(4, DB::table('security_audit_events')->count());
    }

    public function test_lifecycle_after_state_must_be_a_committed_ordinary_close(): void
    {
        $service = new OrdinaryCloseEvidence();
        foreach ([
            ['stop' => 0, 'closed_reason' => 'settled'],
            ['stop' => 1, 'closed_reason' => 'completed'],
        ] as $changes) {
            $payload = $this->payload();
            $payload['lifecycle_after'] = array_merge($payload['lifecycle_after'], $changes);
            $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
        }
        $this->assertSame(0, DB::table('security_audit_events')->count());
    }

    public function test_snapshot_requires_session_row_digests_and_exact_previous_pointer_shape(): void
    {
        $service = new OrdinaryCloseEvidence();
        $baseline = $this->validCancellationPayload();
        $validId = $this->appendEvidence($service, $baseline);
        $this->assertEquals($baseline, $service->read($validId, 34, 12));

        $payload = $baseline;
        $payload['close_uuid'] = (string) Str::uuid();
        unset($payload['cancelled_sessions'][0]['before_row_digest']);
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));

        $payload = $baseline;
        $payload['close_uuid'] = (string) Str::uuid();
        $payload['cancelled_sessions'][0]['after_row_digest'] = 'not-a-digest';
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));

        foreach (['contract_before_digest', 'contract_after_digest', 'guard_before_digest', 'guard_digest'] as $requiredDigest) {
            $payload = $baseline;
            $payload['close_uuid'] = (string) Str::uuid();
            unset($payload[$requiredDigest]);
            $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
        }

        $payload = $baseline;
        $payload['close_uuid'] = (string) Str::uuid();
        $payload['previous_pointer'] = [
            'kind' => 'ordinary_close_v1', 'close_uuid' => (string) Str::uuid(), 'correction_event_id' => 91,
        ];
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload));
        $this->assertSame(1, DB::table('security_audit_events')->count());
    }

    public function test_fact_and_serialized_byte_budgets_fail_closed(): void
    {
        $service = new OrdinaryCloseEvidence();
        $payload = $this->payload();
        $payload['guard_fact_ids']['invoices'] = range(1, 1001);
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload), 'fact-row budget');

        $payload = $this->payload();
        $payload['guard_fact_ids']['sessions'] = range(1, 500);
        $payload['cancel_remaining'] = true;
        $payload['cancelled_sessions'] = array_map(
            fn (int $id) => $this->sessionEvidence($id, ' [結案取消]'), range(1, 500)
        );
        $this->assertEvidenceThrows(fn () => $this->appendEvidence($service, $payload), 'serialized-byte budget');
        $this->assertSame(0, DB::table('security_audit_events')->count());
    }

    public function test_history_categories_preserve_same_numeric_id_from_distinct_tables(): void
    {
        $service = new OrdinaryCloseEvidence();
        $payload = $this->payload();
        $payload['guard_fact_ids']['entitlement_changes'] = [7];
        $payload['guard_fact_ids']['session_reassignments'] = [7];
        $id = $this->appendEvidence($service, $payload);
        $read = $service->read($id, 34, 12);
        $this->assertSame([7], $read['guard_fact_ids']['entitlement_changes']);
        $this->assertSame([7], $read['guard_fact_ids']['session_reassignments']);
    }

    private function payload(): array
    {
        return [
            'close_uuid' => (string) Str::uuid(), 'student_class_id' => 34,
            'student_id' => 56, 'campus_id' => 12, 'schedule_mode' => 'count',
            'close_reason' => 'settled',
            'lifecycle_before' => ['stop' => 0, 'closed_reason' => null, 'end_date' => null, 'mdate' => '2026-10-24 08:00:00'],
            'lifecycle_after' => ['stop' => 1, 'closed_reason' => 'settled', 'end_date' => '2030-01-01', 'mdate' => '2026-10-24 08:01:00'],
            'previous_pointer' => null,
            'cancel_remaining' => false, 'forfeit_remaining' => false, 'cancelled_sessions' => [],
            'contract_before_digest' => hash('sha256', 'contract before'),
            'contract_after_digest' => hash('sha256', 'contract after'),
            'guard_before_digest' => hash('sha256', 'guard before'),
            'guard_digest' => hash('sha256', 'guard after'),
            'guard_fact_ids' => [
                'invoices' => [], 'invoice_items' => [], 'payments' => [], 'payment_reports' => [],
                'deduction_events' => [], 'sign_ins' => [], 'learning_records' => [],
                'entitlement_changes' => [], 'pricing_amendments' => [], 'group_memberships' => [],
                'sessions' => [], 'schedules' => [], 'successor_courses' => [], 'session_reassignments' => [],
            ],
        ];
    }

    private function validCancellationPayload(): array
    {
        $payload = $this->payload();
        $payload['cancel_remaining'] = true;
        $payload['guard_fact_ids']['sessions'] = [19];
        $payload['cancelled_sessions'] = [$this->sessionEvidence(19, ' [結案取消]')];
        return $payload;
    }

    private function sessionEvidence(int $id, string $suffix): array
    {
        return [
            'id' => $id, 'owner_id' => 34, 'date' => '2030-01-01', 'start' => '09:00:00', 'end' => '10:00:00',
            'before_status' => 'scheduled', 'after_status' => 'cancelled',
            'before_row_digest' => hash('sha256', 'complete before row'),
            'after_row_digest' => hash('sha256', 'complete after row'),
            'note' => [
                'was_null' => false, 'byte_length' => 7, 'sha256' => hash('sha256', 'before'),
                'suffix' => $suffix, 'after_sha256' => hash('sha256', 'after'),
            ],
        ];
    }

    private function assertEvidenceThrows(callable $callback, ?string $message = null): void
    {
        try {
            $callback();
            $this->fail('Expected evidence validation to reject the input.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    private function appendEvidence(OrdinaryCloseEvidence $service, array $payload, int $contractId = 34, int $campusId = 12): int
    {
        return DB::transaction(fn () => $service->append($payload, 77, $campusId, $contractId));
    }
}
