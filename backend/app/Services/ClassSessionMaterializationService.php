<?php

namespace App\Services;

use App\Exceptions\SlotOccupiedException;
use App\Models\ClassSession;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single write authority for ClassSession row creation (Phase 1 refactor).
 *
 * Idempotent on (StudentClassID, SessionDate, StartTime HH:MM). Does not encode
 * overlap policy, leave cascade, or LearningRecord side-effects — callers retain those.
 */
class ClassSessionMaterializationService
{
    /**
     * Only 'cancelled' frees a slot: the active-only unique index's generated
     * column is `CASE WHEN Status = 'cancelled' THEN NULL ELSE 1 END` (#957 D1),
     * so the guard must mirror it exactly — anything else still occupies the slot.
     */
    private const SLOT_FREEING_STATUS = 'cancelled';

    /**
     * Return the non-cancelled ClassSession that already holds the target slot
     * for this course, or null if the slot is free. This is the single source of
     * truth for slot-occupancy checks (`uq_class_session_slot` is on
     * StudentClassID + SessionDate + StartTime for non-cancelled rows).
     *
     * Matches on StartTime HH:MM, consistent with upsertSlot's idempotency key.
     */
    public function findActiveSlotConflict(
        int $courseId,
        mixed $sessionDate,
        mixed $startTime,
        ?int $excludeSessionId = null
    ): ?ClassSession {
        $date = $this->normalizeDate($sessionDate);
        $startHm = substr($this->normalizeTimeForStorage($startTime), 0, 5);

        if ($courseId <= 0 || $date === '' || $startHm === '') {
            return null;
        }

        return ClassSession::query()
            ->where('StudentClassID', $courseId)
            ->whereDate('SessionDate', $date)
            ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
            ->when($excludeSessionId !== null, fn ($q) => $q->where('id', '!=', $excludeSessionId))
            ->whereRaw('LOWER(Status) <> ?', [self::SLOT_FREEING_STATUS])
            ->orderBy('id')
            ->first();
    }

    /**
     * Guard a single-row move/create: throw SlotOccupiedException (rendered as
     * HTTP 422) when a genuine non-cancelled session already holds the target
     * slot. Callers use this immediately before their `save()`/create so the
     * director gets an actionable message instead of a raw 1062 → 500.
     */
    public function assertSlotAvailable(
        int $courseId,
        mixed $sessionDate,
        mixed $startTime,
        ?int $excludeSessionId = null
    ): void {
        $conflict = $this->findActiveSlotConflict($courseId, $sessionDate, $startTime, $excludeSessionId);
        if ($conflict !== null) {
            throw SlotOccupiedException::fromConflict(
                $courseId,
                $this->normalizeDate($sessionDate),
                $this->normalizeTimeForStorage($startTime),
                $conflict
            );
        }
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array{session: ClassSession, created: bool}
     */
    public function upsertSlot(array $slot): array
    {
        $studentClassId = (int) ($slot['StudentClassID'] ?? 0);
        $sessionDate = $this->normalizeDate($slot['SessionDate'] ?? null);
        $startTime = $this->normalizeTimeForStorage($slot['StartTime'] ?? null);
        $startHm = substr($startTime, 0, 5);

        if ($studentClassId <= 0 || $sessionDate === '' || $startHm === '') {
            throw new InvalidArgumentException('ClassSession slot requires StudentClassID, SessionDate, and StartTime.');
        }

        return DB::transaction(function () use ($slot, $studentClassId, $sessionDate, $startTime, $startHm) {
            $existing = ClassSession::query()
                ->where('StudentClassID', $studentClassId)
                ->whereDate('SessionDate', $sessionDate)
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            if ($existing) {
                return ['session' => $existing, 'created' => false];
            }

            $payload = $this->buildCreatePayload($slot, $studentClassId, $sessionDate, $startTime);

            return [
                'session' => ClassSession::create($payload),
                'created' => true,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $slot, int $studentClassId, string $sessionDate, string $startTime): array
    {
        $payload = [
            'StudentClassID' => $studentClassId,
            'SessionDate' => $sessionDate,
            'StartTime' => $startTime,
            'EndTime' => $this->normalizeTimeForStorage($slot['EndTime'] ?? '18:00:00'),
            'Status' => (string) ($slot['Status'] ?? 'scheduled'),
            'Note' => array_key_exists('Note', $slot) ? (string) $slot['Note'] : '',
        ];

        if (array_key_exists('SubjectID', $slot)) {
            $payload['SubjectID'] = $slot['SubjectID'] !== null ? (int) $slot['SubjectID'] : null;
        }

        if (array_key_exists('IsContractException', $slot)) {
            $payload['IsContractException'] = (int) (bool) $slot['IsContractException'];
        }

        if (array_key_exists('session_charge', $slot)) {
            $payload['session_charge'] = $slot['session_charge'] !== null ? (int) $slot['session_charge'] : null;
        }

        return $payload;
    }

    private function normalizeDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return substr((string) $value, 0, 10);
    }

    private function normalizeTimeForStorage(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '00:00:00';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $raw)) {
            return $raw . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }

        return substr($raw, 0, 8);
    }
}
