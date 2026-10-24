<?php

namespace App\Services\Billing;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Dedicated, lossless evidence storage for ordinary close snapshots.
 * The caller holds the owning StudentClass parent lock; this table has no UUID uniqueness constraint.
 */
final class OrdinaryCloseEvidence
{
    public const EVENT_TYPE = 'student_class.ordinary_close.v1';
    public const VERSION = 1;
    private const MAX_BYTES = 131072;
    private const MAX_FACTS = 1000;
    // Full pre/post facts are represented by keyed canonical digests; only exact identity sets and write-set inverses persist.
    private const KEY_DOMAIN = 'alltrue-ordinary-close-evidence-v1|';
    private const GUARD_FACT_CATEGORIES = [
        'invoices', 'invoice_items', 'payments', 'payment_reports', 'deduction_events',
        'sign_ins', 'learning_records', 'entitlement_changes', 'pricing_amendments',
        'group_memberships', 'sessions', 'schedules', 'successor_courses',
    ];

    /** Payload is kept separate from generic metadata to preserve the complete typed snapshot. */
    /** @param array<string, mixed> $payload */
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
        if ($payload['student_class_id'] !== $contractId || $payload['campus_id'] !== $campusId) {
            throw new RuntimeException('Ordinary-close evidence binding mismatch.');
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
        if (DB::table('security_audit_events')->where('event_type', self::EVENT_TYPE)
            ->where('correlation_id', $row->correlation_id)->count() !== 1) {
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
            'close_reason', 'lifecycle_before', 'lifecycle_after', 'previous_pointer', 'cancel_mode',
            'cancelled_sessions', 'contract_before_digest', 'contract_after_digest',
            'guard_before_digest', 'guard_digest', 'guard_fact_ids',
        ]);
        if (!is_string($payload['close_uuid'] ?? null) || !Str::isUuid($payload['close_uuid'])
            || !is_int($payload['student_class_id'] ?? null) || $payload['student_class_id'] < 1
            || !is_int($payload['student_id'] ?? null) || $payload['student_id'] < 1
            || !is_int($payload['campus_id'] ?? null) || $payload['campus_id'] < 1
            || !in_array($payload['schedule_mode'] ?? null, ['count', 'date', 'monthly', 'package', 'trial', 'other'], true)
            || !in_array($payload['close_reason'] ?? null, ['settled', 'completed', 'settled_pending'], true)
            || !in_array($payload['cancel_mode'] ?? null, ['cancel_remaining', 'forfeit_remaining', 'none'], true)
            || !is_array($payload['lifecycle_before'] ?? null) || !is_array($payload['lifecycle_after'] ?? null)
            || !is_array($payload['cancelled_sessions'] ?? null)
            || !$this->isHash($payload['contract_before_digest'] ?? null)
            || !$this->isHash($payload['contract_after_digest'] ?? null)
            || !$this->isHash($payload['guard_before_digest'] ?? null)
            || !$this->isHash($payload['guard_digest'] ?? null)
            || !is_array($payload['guard_fact_ids'] ?? null)) {
            throw new RuntimeException('Malformed ordinary-close evidence payload.');
        }
        // guard_digest is the complete post-close census; guard_before_digest is its complete pre-close counterpart.
        foreach ([$payload['lifecycle_before'], $payload['lifecycle_after']] as $lifecycle) {
            $this->exactKeys($lifecycle, ['stop', 'closed_reason', 'end_date', 'mdate']);
            if (!in_array($lifecycle['stop'], [0, 1], true)
                || !in_array($lifecycle['closed_reason'], [null, 'settled', 'completed', 'settled_pending', 'waived', 'contract_amended'], true)
                || !($lifecycle['end_date'] === null || $this->isDateValue($lifecycle['end_date']))
                || !($lifecycle['mdate'] === null || $this->isDateValue($lifecycle['mdate']))) {
                throw new RuntimeException('Malformed lifecycle evidence.');
            }
        }
        $this->validatePreviousPointer($payload['previous_pointer'] ?? null);
        $sessionIds = [];
        foreach ($payload['cancelled_sessions'] as $session) {
            if (!is_array($session)) {
                throw new RuntimeException('Malformed cancelled-session evidence.');
            }
            $this->exactKeys($session, [
                'id', 'owner_id', 'date', 'start', 'end', 'before_status', 'after_status',
                'before_row_digest', 'after_row_digest', 'note',
            ]);
            if (!is_int($session['id']) || $session['id'] < 1 || $session['owner_id'] !== $payload['student_class_id']
                || !$this->isDateValue($session['date']) || !$this->isTimeValue($session['start']) || !$this->isTimeValue($session['end'])
                || !in_array($session['before_status'], ['scheduled'], true) || $session['after_status'] !== 'cancelled'
                || !$this->isHash($session['before_row_digest']) || !$this->isHash($session['after_row_digest'])
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
        $guardFactCount = 0;
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
            $guardFactCount += count($ids);
        }
        if (array_diff(array_keys($sessionIds), $payload['guard_fact_ids']['sessions']) !== []) {
            throw new RuntimeException('Cancelled-session set is outside the complete session census.');
        }
        $this->validateSafeTree($payload);
        if ($guardFactCount > self::MAX_FACTS) {
            throw new RuntimeException('Ordinary-close evidence exceeds the fact-row budget.');
        }
        if (strlen($this->canonicalJson($payload)) > self::MAX_BYTES) {
            throw new RuntimeException('Ordinary-close evidence exceeds the serialized-byte budget.');
        }
    }

    private function validatePreviousPointer(mixed $pointer): void
    {
        if ($pointer === null) {
            return;
        }
        if (!is_array($pointer)) {
            throw new RuntimeException('Malformed previous ordinary-close pointer.');
        }
        $this->exactKeys($pointer, ['kind', 'close_uuid', 'correction_event_id']);
        if ($pointer['kind'] !== 'ordinary_close_corrected_v1' || !is_string($pointer['close_uuid'])
            || !Str::isUuid($pointer['close_uuid']) || !is_int($pointer['correction_event_id'])
            || $pointer['correction_event_id'] < 1) {
            throw new RuntimeException('Malformed previous ordinary-close pointer.');
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
        if (!is_string($value) || strlen($value) > 35
            || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?)?$/', $value, $match)) {
            return false;
        }
        if (!checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return false;
        }
        return !isset($match[4]) || ((int) $match[4] < 24 && (int) $match[5] < 60 && (int) $match[6] < 60);
    }

    private function isTimeValue(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?$/', $value, $match)) {
            return false;
        }
        return (int) $match[1] < 24 && (int) $match[2] < 60 && (int) $match[3] < 60;
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
