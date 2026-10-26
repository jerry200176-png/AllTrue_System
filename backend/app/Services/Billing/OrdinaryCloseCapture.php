<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Unwired, bounded census and delta proof for the ordinary-close write set.
 * Returned captures contain safe descriptors and keyed digests, never source text.
 */
final class OrdinaryCloseCapture
{
    private const DOMAIN = 'alltrue-ordinary-close-capture-v1|';
    private const TABLES = [
        'session_entitlement_transfers' => ['id', 'entitlement_changes'],
        'class_session_reassignments' => ['id', 'session_reassignments'],
    ];
    private const REQUIRED_COLUMNS = [
        'session_entitlement_transfers' => ['id', 'class_session_id', 'source_student_class_id', 'target_student_class_id', 'reason', 'decision_reference', 'decided_by_user_id', 'decided_by_actor', 'transferred_at', 'rolled_back_at', 'rolled_back_by_user_id', 'rolled_back_by_actor', 'snapshot_before', 'snapshot_after', 'created_at', 'updated_at'],
        'class_session_reassignments' => ['id', 'class_session_id', 'old_student_class_id', 'new_student_class_id', 'reason', 'performed_by', 'created_at'],
    ];
    private const MAX_FACTS = 1000;

    /** Caller holds the Student prefix lock before this transaction-bound contract-first census. */
    public function captureLocked(int $courseId): array
    {
        $this->requireTransaction();
        if ($courseId < 1) throw new RuntimeException('Invalid ordinary-close capture identity.');
        $course = DB::table('StudentClass')->where('ID', $courseId)->lockForUpdate()->first();
        if (!$course) throw new RuntimeException('Ordinary-close contract is unavailable.');
        $contract = $this->raw($course);
        $this->assertOrdinaryContract($contract);
        $this->requireColumns('StudentClass', ['ID', 'StudentID', 'Stop', 'closed_reason', 'EndDate', 'MDate', 'settlement_snapshot', 'ScheduleMode']);
        $this->requireColumns('ClassSession', ['id', 'StudentClassID', 'SubjectID', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'Note', 'IsContractException', 'session_charge', 'OriginalSessionDate', 'OriginalStartTimeHM', 'created_at', 'updated_at', 'StartTimeHM']);
        // Re-read the caller-held Student prefix as a current locking read, not an older RR snapshot.
        $campusId = (int) DB::table('Student')->where('id', (int) $contract['StudentID'])->lockForUpdate()->value('CampusID');
        if ($campusId < 1) throw new RuntimeException('Ordinary-close campus binding unavailable.');
        $sessions = $this->rows('ClassSession', fn ($q) => $q->where('StudentClassID', $courseId), self::MAX_FACTS);
        $sessionIds = array_map(static fn ($r) => (int) $r['id'], $sessions);
        $facts = [];
        $remaining = self::MAX_FACTS - count($sessions);
        foreach (self::TABLES as $table => [$pk]) {
            $facts[$table] = $this->historyRows($table, $courseId, $sessionIds, $remaining);
            $remaining -= count($facts[$table]);
        }
        $count = count($sessions) + count($facts['session_entitlement_transfers']) + count($facts['class_session_reassignments']);
        if ($count > self::MAX_FACTS) throw new RuntimeException('Ordinary-close capture exceeds its row budget.');

        $sessionFacts = [];
        foreach ($sessions as $row) {
            $id = (int) $row['id'];
            if (!$this->dateValid((string) $row['SessionDate']) || !$this->timeValid((string) $row['StartTime']) || !$this->timeValid((string) $row['EndTime'])) {
                throw new RuntimeException('Ordinary-close session slot is malformed.');
            }
            $sessionFacts[$id] = [
                'id' => $id, 'owner_id' => (int) $row['StudentClassID'],
                'date' => (string) $row['SessionDate'], 'start' => (string) $row['StartTime'], 'end' => (string) $row['EndTime'],
                'status' => $row['Status'], 'note_was_null' => $row['Note'] === null,
                'note_length' => strlen((string) ($row['Note'] ?? '')), 'note_digest' => $this->digestValue('note', $row['Note']),
                'row_digest' => $this->digest('ClassSession', $row),
                'stable_digest' => $this->digest('ClassSession:stable', $this->without($row, ['Status', 'Note', 'updated_at', 'ActiveSlotFlag'])),
            ];
        }
        $history = [];
        foreach ($facts as $table => $rows) {
            $history[$table] = [
                'ids' => array_map(static fn ($r) => (int) $r['id'], $rows),
                'digest' => $this->digestRows($table, $rows),
            ];
        }
        $historyDigest = $this->digest('ordinary-close-partial-guard', ['sessions' => array_column($sessionFacts, 'row_digest'), 'history' => $history]);
        return [
            'course_id' => $courseId, 'student_id' => (int) $contract['StudentID'],
            'campus_id' => $campusId,
            'contract_digest' => $this->digest('StudentClass', $contract),
            'contract_stable_digest' => $this->digest('StudentClass:stable', $this->without($contract, ['Stop', 'closed_reason', 'EndDate', 'MDate', 'settlement_snapshot'])),
            'lifecycle' => $this->lifecycle($contract), 'previous_pointer' => $this->previousPointer($contract['settlement_snapshot'] ?? null),
            'sessions' => $sessionFacts, 'history' => $history, 'history_digest' => $historyDigest,
            'captured_tables' => ['StudentClass', 'ClassSession', 'session_entitlement_transfers', 'class_session_reassignments'],
            'fact_count' => $count,
        ];
    }

    /** Verify actual SQL close writes, then return only this module's partial evidence. */
    public function describeCloseLocked(array $before, string $closeUuid, string $requestedReason, bool $cancelRemaining, bool $forfeitRemaining, string $closeDay): array
    {
        $this->requireTransaction();
        if (!Str::isUuid($closeUuid) || !in_array($requestedReason, ['settled', 'completed'], true) || !$this->dateValid($closeDay)) {
            throw new RuntimeException('Invalid ordinary-close delta request.');
        }
        $courseId = $before['course_id'] ?? 0;
        if (!is_int($courseId) || $courseId < 1) throw new RuntimeException('Malformed ordinary-close capture.');
        $course = DB::table('StudentClass')->where('ID', $courseId)->lockForUpdate()->first();
        if (!$course) throw new RuntimeException('Ordinary-close contract disappeared.');
        $afterContract = $this->raw($course);
        if (!hash_equals($before['contract_stable_digest'], $this->digest('StudentClass:stable', $this->without($afterContract, ['Stop', 'closed_reason', 'EndDate', 'MDate', 'settlement_snapshot'])))) {
            throw new RuntimeException('Ordinary-close changed an unsupported contract field.');
        }
        $newPointer = $this->decodePointer($afterContract['settlement_snapshot'] ?? null);
        if (!is_array($newPointer) || !$this->keysMatch($newPointer, ['kind', 'close_uuid', 'version', 'invalidated'])
            || $newPointer['kind'] !== 'ordinary_close_v1' || $newPointer['close_uuid'] !== $closeUuid
            || $newPointer['version'] !== 1 || $newPointer['invalidated'] !== false) {
            throw new RuntimeException('Ordinary-close pointer is inconsistent.');
        }
        $afterLifecycle = $this->lifecycle($afterContract);
        if ($afterLifecycle['stop'] !== 1 || !in_array($afterLifecycle['closed_reason'], [$requestedReason, 'settled_pending'], true)
            || ($requestedReason === 'settled' && $afterLifecycle['end_date'] !== $closeDay.' 00:00:00'
                && $afterLifecycle['end_date'] !== $closeDay)
            || ($requestedReason === 'completed' && $afterLifecycle['end_date'] !== $before['lifecycle']['end_date'])) {
            throw new RuntimeException('Ordinary-close lifecycle delta is inconsistent.');
        }

        $sessions = $this->rows('ClassSession', fn ($q) => $q->where('StudentClassID', $courseId), self::MAX_FACTS);
        if (count($sessions) !== count($before['sessions'])) throw new RuntimeException('Ordinary-close session census changed.');
        $expected = [];
        foreach ($before['sessions'] as $id => $old) {
            if ($cancelRemaining && $old['status'] === 'scheduled' && $old['date'] >= $closeDay) $expected[(int) $id] = true;
        }
        $actual = [];
        $evidence = [];
        $sessionAfterDigests = [];
        foreach ($sessions as $row) {
            $id = (int) $row['id'];
            if (!isset($before['sessions'][$id])) throw new RuntimeException('Ordinary-close session census changed.');
            $old = $before['sessions'][$id];
            if (array_key_exists('ActiveSlotFlag', $row)
                && $row['ActiveSlotFlag'] !== ($row['Status'] === 'cancelled' ? null : 1)
                && $row['ActiveSlotFlag'] !== ($row['Status'] === 'cancelled' ? null : '1')) {
                throw new RuntimeException('Ordinary-close generated slot value is inconsistent.');
            }
            $sessionAfterDigests[] = $this->digest('ClassSession', $row);
            if (!hash_equals($old['stable_digest'], $this->digest('ClassSession:stable', $this->without($row, ['Status', 'Note', 'updated_at', 'ActiveSlotFlag'])))) {
                throw new RuntimeException('Ordinary-close changed an unsupported session field.');
            }
            $changed = $row['Status'] !== $old['status'] || $this->digestValue('note', $row['Note']) !== $old['note_digest'];
            if ($changed) $actual[$id] = true;
            if (isset($expected[$id])) {
                $suffix = $requestedReason === 'settled' ? ' [結案取消]' : ' [暫停取消]';
                $afterNote = (string) ($row['Note'] ?? '');
                $beforeNote = substr($afterNote, 0, -strlen($suffix));
                if ($row['Status'] !== 'cancelled' || !str_ends_with($afterNote, $suffix)
                    || strlen($beforeNote) !== $old['note_length']
                    || !$old['note_was_null'] && !hash_equals($old['note_digest'], $this->digestValue('note', $beforeNote))
                    || $old['note_was_null'] && $beforeNote !== '') {
                    throw new RuntimeException('Ordinary-close session note inverse is inconsistent.');
                }
                $evidence[] = [
                    'id' => $id, 'owner_id' => $courseId, 'date' => $old['date'], 'start' => $old['start'], 'end' => $old['end'],
                    'before_status' => 'scheduled', 'after_status' => 'cancelled',
                    'before_row_digest' => $old['row_digest'], 'after_row_digest' => $this->digest('ClassSession', $row),
                    'note' => ['was_null' => $old['note_was_null'], 'byte_length' => $old['note_length'],
                        'sha256' => $old['note_digest'], 'suffix' => $suffix,
                        'after_sha256' => $this->digestValue('note', $row['Note'])],
                ];
            } elseif ($changed || !hash_equals($old['row_digest'], $this->digest('ClassSession', $row))) {
                throw new RuntimeException('Ordinary-close changed an ineligible session.');
            }
        }
        if (array_keys($expected) !== array_keys($actual) && array_diff_key($expected, $actual) !== []) {
            throw new RuntimeException('Ordinary-close cancellation set is incomplete.');
        }
        if (count($actual) !== count($expected)) throw new RuntimeException('Ordinary-close cancellation set is unexpected.');
        $historyAfter = [];
        $remaining = self::MAX_FACTS - count($sessions);
        foreach (self::TABLES as $table => [$pk]) {
            $rows = $this->historyRows($table, $courseId, array_keys($before['sessions']), $remaining);
            $remaining -= count($rows);
            if ($before['history'][$table]['ids'] !== array_map(static fn ($r) => (int) $r[$pk], $rows)) {
                throw new RuntimeException('Ordinary-close entitlement history changed.');
            }
            $historyAfter[$table] = ['ids' => $before['history'][$table]['ids'], 'digest' => $this->digestRows($table, $rows)];
            if (!hash_equals($before['history'][$table]['digest'], $historyAfter[$table]['digest'])) {
                throw new RuntimeException('Ordinary-close changed entitlement history.');
            }
        }
        return [
            'close_uuid' => $closeUuid, 'student_class_id' => $courseId, 'student_id' => (int) $before['student_id'], 'campus_id' => (int) $before['campus_id'],
            'schedule_mode' => 'count', 'close_reason' => $afterLifecycle['closed_reason'],
            'lifecycle_before' => $before['lifecycle'], 'lifecycle_after' => $afterLifecycle,
            'previous_pointer' => $before['previous_pointer'], 'cancel_remaining' => $cancelRemaining, 'forfeit_remaining' => $forfeitRemaining,
            'contract_before_digest' => $before['contract_digest'], 'contract_after_digest' => $this->digest('StudentClass', $afterContract),
            'cancelled_sessions' => $evidence,
            'guard_fact_ids' => [
                'sessions' => array_map('intval', array_keys($before['sessions'])),
                'entitlement_changes' => $before['history']['session_entitlement_transfers']['ids'],
                'session_reassignments' => $before['history']['class_session_reassignments']['ids'],
            ],
            'partial_guard_before_digest' => $before['history_digest'],
            'partial_guard_digest' => $this->digest('ordinary-close-partial-guard', ['sessions' => $sessionAfterDigests, 'history' => $historyAfter]),
            'captured_tables' => $before['captured_tables'],
        ];
    }

    private function rows(string $table, callable $where, int $maxRows): array
    {
        $query = DB::table($table)->orderBy('id');
        $where($query);
        $rows = $query->limit($maxRows + 1)->lockForUpdate()->get();
        if ($rows->count() > $maxRows) throw new RuntimeException('Ordinary-close capture exceeds its row budget.');
        return array_map(fn ($row) => $this->raw($row), $rows->all());
    }

    private function historyRows(string $table, int $courseId, array $sessionIds, int $maxRows): array
    {
        $this->requireColumns($table, self::REQUIRED_COLUMNS[$table]);
        return $this->rows($table, function ($q) use ($table, $courseId, $sessionIds) {
            $q->where(function ($inner) use ($table, $courseId, $sessionIds) {
                if ($table === 'session_entitlement_transfers') $inner->where('source_student_class_id', $courseId)->orWhere('target_student_class_id', $courseId);
                else $inner->where('old_student_class_id', $courseId)->orWhere('new_student_class_id', $courseId);
                if ($sessionIds) $inner->orWhereIn('class_session_id', $sessionIds);
            });
        }, $maxRows);
    }

    private function raw(object $row): array { return (array) $row; }
    private function requireColumns(string $table, array $columns): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) throw new RuntimeException('Ordinary-close source schema is unavailable.');
    }
    private function without(array $row, array $keys): array { foreach ($keys as $key) unset($row[$key]); ksort($row, SORT_STRING); return $row; }
    private function digest(string $label, array $value): string { return hash_hmac('sha256', self::DOMAIN.$label.'|'.$this->canonical($value), $this->key()); }
    private function digestValue(string $label, mixed $value): string { return hash_hmac('sha256', self::DOMAIN.$label.'|'.$this->canonical(['value' => $value]), $this->key()); }
    private function digestRows(string $table, array $rows): string { return $this->digest($table, $rows); }
    private function canonical(array $value): string
    {
        try { return json_encode($this->sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new RuntimeException('Ordinary-close source value cannot be encoded safely.'); }
    }
    private function sort(array $value): array { foreach ($value as &$v) if (is_array($v)) $v = $this->sort($v); unset($v); if (!array_is_list($value)) ksort($value, SORT_STRING); return $value; }
    private function key(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) { $decoded = base64_decode(substr($key, 7), true); $key = $decoded === false ? '' : $decoded; }
        if (strlen($key) < 32) throw new RuntimeException('Application key unavailable for ordinary-close capture.');
        return $key;
    }
    private function requireTransaction(): void { if (DB::transactionLevel() < 1) throw new RuntimeException('Ordinary-close capture requires its transaction.'); }
    private function assertOrdinaryContract(array $r): void
    {
        if ((int) ($r['Stop'] ?? -1) !== 0 || ($r['closed_reason'] ?? null) !== null || ($r['ScheduleMode'] ?? null) !== 'count') throw new RuntimeException('Contract is not eligible for ordinary-close capture.');
        $this->previousPointer($r['settlement_snapshot'] ?? null);
    }
    private function previousPointer(mixed $raw): ?array
    {
        if ($raw === null) return null;
        $value = json_decode((string) $raw, true);
        if (!is_array($value) || !$this->keysMatch($value, ['kind', 'close_uuid', 'correction_event_id']) || $value['kind'] !== 'ordinary_close_corrected_v1'
            || !is_string($value['close_uuid']) || !Str::isUuid($value['close_uuid']) || !is_int($value['correction_event_id']) || $value['correction_event_id'] < 1) {
            throw new RuntimeException('Unsupported previous ordinary-close pointer.');
        }
        return $value;
    }
    private function decodePointer(mixed $raw): ?array { if ($raw === null) return null; $v = json_decode((string) $raw, true); return is_array($v) ? $v : null; }
    private function keysMatch(array $value, array $expected): bool { $actual = array_keys($value); sort($actual); sort($expected); return $actual === $expected; }
    private function lifecycle(array $r): array { return ['stop' => (int) $r['Stop'], 'closed_reason' => $r['closed_reason'] ?? null, 'end_date' => $r['EndDate'] ?? null, 'mdate' => $r['MDate'] ?? null]; }
    private function dateValid(string $v): bool { $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v); return $d !== false && $d->format('Y-m-d') === $v; }
    private function timeValid(string $v): bool { return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?$/', $v); }
}
