<?php

namespace App\Services\Billing;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/** Dedicated, lossless evidence storage for ordinary close snapshots. */
final class OrdinaryCloseEvidence
{
    public const EVENT_TYPE = 'student_class.ordinary_close.v1';
    public const VERSION = 1;
    private const MAX_BYTES = 131072;
    private const MAX_FACTS = 1000;
    private const KEY_DOMAIN = 'alltrue-ordinary-close-evidence-v1|';
    private const GUARD_FACT_CATEGORIES = [
        'invoices', 'invoice_items', 'payments', 'payment_reports', 'deduction_events',
        'sign_ins', 'learning_records', 'entitlement_changes', 'pricing_amendments',
        'group_memberships', 'sessions', 'schedules', 'successor_courses',
    ];

    /**
     * Payload is deliberately separate from generic audit metadata: nested arrays and long
     * canonical values must survive JSON storage without the generic 120-byte sanitizer.
     *
     * @param array<string, mixed> $payload
     */
    public function append(array $payload, int $actorId, int $campusId, int $contractId): int
    {
        $key = $this->applicationKey();
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Ordinary-close evidence requires the close transaction.');
        }
        $this->validatePayload($payload);
        if ($actorId < 1 || $campusId < 1 || $contractId < 1) {
            throw new RuntimeException('Invalid ordinary-close evidence binding.');
        }

        $uuid = $payload['close_uuid'];
        $subjectRef = SecurityAuditEvent::ref('student_class', $contractId);
        $actorRef = SecurityAuditEvent::ref('user', $actorId);
        if (DB::table('security_audit_events')->where('event_type', self::EVENT_TYPE)
            ->where('correlation_id', $uuid)->lockForUpdate()->exists()) {
            throw new RuntimeException('Duplicate ordinary-close evidence UUID.');
        }

        $signed = [
            'version' => self::VERSION,
            'close_uuid' => $uuid,
            'contract_id' => $contractId,
            'campus_id' => $campusId,
            'actor_ref' => $actorRef,
            'subject_ref' => $subjectRef,
            'payload' => $payload,
        ];
        $canonical = $this->canonicalJson($signed);
        if (strlen($canonical) > self::MAX_BYTES) {
            throw new RuntimeException('Ordinary-close evidence exceeds the complete snapshot limit.');
        }
        $metadata = [
            'ordinary_close_evidence' => $signed,
            'integrity' => hash_hmac('sha256', self::KEY_DOMAIN.$canonical, $key),
        ];

        return (int) DB::table('security_audit_events')->insertGetId([
            'event_type' => self::EVENT_TYPE,
            'correlation_id' => $uuid,
            'occurred_at' => now(),
            'campus_id' => $campusId,
            'actor_ref' => $actorRef,
            'subject_ref' => $subjectRef,
            'binding_ref' => null,
            'outcome' => 'success',
            'metadata' => $this->canonicalJson($metadata),
            'retention_until' => now()->addDays((int) config('audit.retention_days', 180)),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function read(int $eventId, int $contractId, int $campusId): ?array
    {
        $key = $this->applicationKey();
        $row = DB::table('security_audit_events')->where('id', $eventId)->first();
        if (!$row || $row->event_type !== self::EVENT_TYPE || $row->outcome !== 'success') {
            return null;
        }
        try {
            $metadata = json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
        $signed = $metadata['ordinary_close_evidence'] ?? null;
        if (!is_array($signed) || !is_string($metadata['integrity'] ?? null)) {
            return null;
        }
        try {
            $this->validateSigned($signed);
            $canonical = $this->canonicalJson($signed);
        } catch (RuntimeException|JsonException $e) {
            return null;
        }
        if (strlen($canonical) > self::MAX_BYTES
            || !hash_equals(hash_hmac('sha256', self::KEY_DOMAIN.$canonical, $key), $metadata['integrity'])) {
            return null;
        }
        $payload = $signed['payload'];
        if ((int) $row->campus_id !== $campusId
            || (int) $signed['campus_id'] !== $campusId
            || (int) $signed['contract_id'] !== $contractId
            || !hash_equals((string) $row->subject_ref, (string) $signed['subject_ref'])
            || !hash_equals((string) SecurityAuditEvent::ref('student_class', $contractId), (string) $signed['subject_ref'])
            || !hash_equals((string) $row->actor_ref, (string) $signed['actor_ref'])
            || !hash_equals((string) $row->correlation_id, (string) $signed['close_uuid'])) {
            return null;
        }
        if ($payload['student_class_id'] !== $contractId || $payload['campus_id'] !== $campusId) {
            return null;
        }
        return $payload;
    }

    /** @param array<string, mixed> $signed */
    private function validateSigned(array $signed): void
    {
        $this->exactKeys($signed, ['version', 'close_uuid', 'contract_id', 'campus_id', 'actor_ref', 'subject_ref', 'payload']);
        if ($signed['version'] !== self::VERSION || !is_string($signed['close_uuid'])
            || !Str::isUuid($signed['close_uuid']) || !is_int($signed['contract_id']) || $signed['contract_id'] < 1
            || !is_int($signed['campus_id']) || $signed['campus_id'] < 1
            || !is_string($signed['actor_ref']) || !preg_match('/^[a-f0-9]{64}$/', $signed['actor_ref'])
            || !is_string($signed['subject_ref']) || !preg_match('/^[a-f0-9]{64}$/', $signed['subject_ref'])
            || !is_array($signed['payload'])) {
            throw new RuntimeException('Malformed ordinary-close evidence envelope.');
        }
        $this->validatePayload($signed['payload']);
        if ($signed['payload']['close_uuid'] !== $signed['close_uuid']
            || $signed['payload']['student_class_id'] !== $signed['contract_id']
            || $signed['payload']['campus_id'] !== $signed['campus_id']) {
            throw new RuntimeException('Ordinary-close evidence binding mismatch.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload): void
    {
        $this->exactKeys($payload, [
            'close_uuid', 'student_class_id', 'student_id', 'campus_id', 'schedule_mode',
            'close_reason', 'lifecycle_before', 'lifecycle_after', 'cancel_mode',
            'cancelled_sessions', 'guard_digest', 'guard_fact_ids',
        ]);
        if (!is_string($payload['close_uuid'] ?? null) || !Str::isUuid($payload['close_uuid'])
            || !is_int($payload['student_class_id'] ?? null) || $payload['student_class_id'] < 1
            || !is_int($payload['student_id'] ?? null) || $payload['student_id'] < 1
            || !is_int($payload['campus_id'] ?? null) || $payload['campus_id'] < 1
            || !in_array($payload['schedule_mode'] ?? null, ['count', 'date', 'monthly', 'package', 'trial', 'other'], true)
            || !in_array($payload['close_reason'] ?? null, ['settled', 'completed', 'settled_pending'], true)
            || !in_array($payload['cancel_mode'] ?? null, ['cancel_remaining', 'forfeit_remaining', 'none'], true)
            || !is_array($payload['lifecycle_before'] ?? null) || !is_array($payload['lifecycle_after'] ?? null)
            || !is_array($payload['cancelled_sessions'] ?? null) || !is_string($payload['guard_digest'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/', $payload['guard_digest'])
            || !is_array($payload['guard_fact_ids'] ?? null)) {
            throw new RuntimeException('Malformed ordinary-close evidence payload.');
        }
        foreach ([$payload['lifecycle_before'], $payload['lifecycle_after']] as $lifecycle) {
            $this->exactKeys($lifecycle, ['stop', 'closed_reason', 'end_date', 'mdate']);
            if (!in_array($lifecycle['stop'], [0, 1], true)
                || !in_array($lifecycle['closed_reason'], [null, 'settled', 'completed', 'settled_pending', 'waived', 'contract_amended'], true)
                || !($lifecycle['end_date'] === null || $this->isDateValue($lifecycle['end_date']))
                || !($lifecycle['mdate'] === null || $this->isDateValue($lifecycle['mdate']))) {
                throw new RuntimeException('Malformed lifecycle evidence.');
            }
        }
        $sessionIds = [];
        foreach ($payload['cancelled_sessions'] as $session) {
            if (!is_array($session)) {
                throw new RuntimeException('Malformed cancelled-session evidence.');
            }
            $this->exactKeys($session, ['id', 'owner_id', 'date', 'start', 'end', 'before_status', 'after_status', 'note']);
            if (!is_int($session['id']) || $session['id'] < 1 || $session['owner_id'] !== $payload['student_class_id']
                || !is_string($session['date']) || !is_string($session['start']) || !is_string($session['end'])
                || !is_string($session['before_status']) || !is_string($session['after_status'])
                || !is_array($session['note'])) {
                throw new RuntimeException('Malformed cancelled-session binding.');
            }
            if (isset($sessionIds[$session['id']])) {
                throw new RuntimeException('Duplicate cancelled-session identity.');
            }
            $sessionIds[$session['id']] = true;
            $this->exactKeys($session['note'], ['was_null', 'byte_length', 'sha256', 'suffix', 'after_sha256']);
            if (!is_bool($session['note']['was_null']) || !is_int($session['note']['byte_length'])
                || $session['note']['byte_length'] < 0 || !$this->isHash($session['note']['sha256'])
                || !$this->isHash($session['note']['after_sha256']) || !in_array($session['note']['suffix'], [' [結案取消]', ' [暫停取消]'], true)) {
                throw new RuntimeException('Malformed note evidence.');
            }
        }
        $this->exactKeys($payload['guard_fact_ids'], self::GUARD_FACT_CATEGORIES);
        foreach ($payload['guard_fact_ids'] as $ids) {
            if (!is_array($ids) || !array_is_list($ids)) {
                throw new RuntimeException('Malformed guard fact identity set.');
            }
            $seen = [];
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1 || isset($seen[$id])) {
                    throw new RuntimeException('Malformed guard fact identity set.');
                }
                $seen[$id] = true;
            }
        }
        $this->validateSafeTree($payload);
        if ($this->factCount($payload) > self::MAX_FACTS || strlen($this->canonicalJson($payload)) > self::MAX_BYTES) {
            throw new RuntimeException('Ordinary-close evidence is incomplete or exceeds its limit.');
        }
    }

    private function validateSafeTree(mixed $value, string $key = ''): void
    {
        if (is_string($value) && strlen($value) > 16384) {
            throw new RuntimeException('Ordinary-close evidence contains a capped value.');
        }
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                if (is_string($childKey) && preg_match('/name|phone|email|line|token|password|note_text|raw_note|body/i', $childKey)) {
                    throw new RuntimeException('Ordinary-close evidence contains a prohibited field.');
                }
                $this->validateSafeTree($child, (string) $childKey);
            }
        } elseif (!is_null($value) && !is_scalar($value)) {
            throw new RuntimeException('Ordinary-close evidence contains an unsupported value.');
        }
    }

    private function factCount(array $value): int
    {
        $count = 0;
        foreach ($value as $child) {
            $count++;
            if (is_array($child)) {
                $count += $this->factCount($child);
            }
        }
        return $count;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($this->sortRecursively($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $child = $this->sortRecursively($child);
            }
        }
        unset($child);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('Unexpected ordinary-close evidence schema.');
        }
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^[a-f0-9]{64}$/', $value);
    }

    private function isDateValue(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 35
            && (bool) preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value);
    }

    private function applicationKey(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? '' : $decoded;
        }
        if (strlen($key) < 32) {
            throw new RuntimeException('Application key unavailable for ordinary-close evidence.');
        }
        return $key;
    }
}
