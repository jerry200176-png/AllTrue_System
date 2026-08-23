<?php

namespace App\Services;

use App\Exceptions\SlotOccupiedException;
use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Support\SessionStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single write authority for ClassSession row creation (Phase 1 refactor).
 *
 * Idempotent on (StudentClassID, SessionDate, StartTime HH:MM). Interactive
 * future writes are guarded at the model boundary; historical imports and
 * read-side projections retain their existing audit/repair semantics.
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
            $reviveCancelled = !empty($slot['_revive_cancelled']);
            $existing = ClassSession::query()
                ->where('StudentClassID', $studentClassId)
                ->whereDate('SessionDate', $sessionDate)
                ->whereRaw('SUBSTRING(StartTime, 1, 5) = ?', [$startHm])
                ->when($reviveCancelled, fn ($q) => $q->whereRaw('LOWER(Status) <> ?', ['cancelled']))
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            if ($existing) {
                return ['session' => $existing, 'created' => false];
            }

            $studentClass = ($slot['_student_class'] ?? null) instanceof StudentClass
                ? $slot['_student_class']
                : StudentClass::query()->whereKey($studentClassId)->lockForUpdate()->first();
            if (!$studentClass) {
                throw new InvalidArgumentException('ClassSession slot references an unknown StudentClass.');
            }

            $payload = $this->buildCreatePayload($slot, $studentClassId, $sessionDate, $startTime);
            $session = new ClassSession($payload);
            $session->setPreloadedStudentClass($studentClass);
            if (($slot['_student_class'] ?? null) instanceof StudentClass) {
                $session->setPreloadedCourseSettlementLock(
                    $slot['_student_class']->isUsageSettlementLocked()
                );
            }
            $session->save();
            $session->setPreloadedCourseSettlementLock(null);

            return [
                'session' => $session,
                'created' => true,
            ];
        });
    }

    public function assertStudentSlotAvailableForSession(ClassSession $session): void
    {
        $studentClassId = (int) $session->getAttribute('StudentClassID');
        $status = strtolower(trim((string) $session->getAttribute('Status')));
        $note = strtolower((string) $session->getAttribute('Note'));
        $sessionDate = $this->normalizeDate($session->getAttribute('SessionDate'));
        if ($studentClassId <= 0
            || !in_array($status, ['scheduled', 'rescheduled'], true)
            || $sessionDate < now()->toDateString()
            // Read-side monthly projection and schedule repair are derived
            // views, not a director booking action; retain their batched query
            // budget and let the duplicate audit report any legacy collision.
            || str_contains($note, 'projected-monthly-materialized')
            || str_contains($note, 'auto-materialized-from-schedule-read-repair')) {
            return;
        }

        $studentClass = $session->preloadedStudentClass()
            ?? StudentClass::query()->whereKey($studentClassId)->first();
        if (!$studentClass) {
            // A few legacy audit/constraint tests intentionally create a bare
            // ClassSession row without its parent contract. There is no student
            // identity to compare in that case; keep the existing FK behaviour
            // and leave the overlap guard inapplicable.
            return;
        }

        $this->assertStudentSlotAvailable(
            $studentClass,
            $studentClassId,
            $sessionDate,
            $this->normalizeTimeForStorage($session->getAttribute('StartTime')),
            $this->normalizeTimeForStorage($session->getAttribute('EndTime')),
            $session->exists() ? (int) $session->getKey() : null,
        );
    }

    private function assertStudentSlotAvailable(
        StudentClass $studentClass,
        int $studentClassId,
        string $sessionDate,
        string $startTime,
        string $endTime,
        ?int $excludeSessionId = null,
    ): void {
        $studentId = (int) $studentClass->getAttribute('StudentID');
        if ($studentId <= 0
            || ((int) $studentClass->getAttribute('Stop') === 1
                && (int) $studentClass->getAttribute('RemainingSessions') <= 0)
            || strtolower(trim((string) $studentClass->getAttribute('ClassType'))) === 'trial') {
            return;
        }

        $activeStatuses = SessionStatus::futureReservationExclusionStatuses();
        $sessionConflict = ClassSession::query()
            ->from('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', $studentId)
            ->where('cs.StudentClassID', '!=', $studentClassId)
            ->when($excludeSessionId !== null, fn ($query) => $query->where('cs.id', '!=', $excludeSessionId))
            ->whereDate('cs.SessionDate', $sessionDate)
            ->where(function ($query) {
                $query->where('sc.Stop', 0)->orWhereNull('sc.Stop');
            })
            ->whereNotIn('cs.Status', $activeStatuses)
            ->whereRaw("LOWER(COALESCE(sc.ClassType, '')) <> ?", ['trial'])
            ->where('cs.StartTime', '<', $endTime)
            ->where('cs.EndTime', '>', $startTime)
            ->orderBy('cs.id')
            ->first();

        if ($sessionConflict) {
            throw SlotOccupiedException::fromStudentConflict(
                $studentClassId,
                $sessionDate,
                $startTime,
                (int) $sessionConflict->getKey(),
                $sessionConflict->getAttribute('Status') !== null
                    ? (string) $sessionConflict->getAttribute('Status')
                    : null,
            );
        }

        // A schedule may exist before its ClassSession is materialized. Check it
        // here too, otherwise a later backfill could recreate the overlap.
        $scheduleConflict = DB::table('schedules as s')
            ->leftJoin('StudentClass as sc', 'sc.ID', '=', 's.student_course_id')
            ->where('s.student_id', $studentId)
            ->whereDate('s.schedule_date', $sessionDate)
            ->where('s.status', 'scheduled')
            ->where(function ($query) use ($studentClassId) {
                $query->whereNull('s.student_course_id')
                    ->orWhere('s.student_course_id', '!=', $studentClassId);
            })
            // An original_schedule_id row is a substitute/reschedule history
            // anchor. Its live ClassSession is checked above; the anchor itself
            // must not block a valid renewal at the same student time.
            ->whereNull('s.original_schedule_id')
            ->where(function ($query) {
                $query->whereNull('sc.ClassType')
                    ->orWhereRaw("LOWER(sc.ClassType) <> ?", ['trial']);
            })
            ->where(function ($query) {
                $query->whereNull('sc.ID')
                    ->orWhere('sc.Stop', 0)
                    ->orWhereNull('sc.Stop');
            })
            ->where('s.start_time', '<', substr($endTime, 0, 5))
            ->where('s.end_time', '>', substr($startTime, 0, 5))
            ->orderBy('s.id')
            ->first(['s.id', 's.status']);

        if ($scheduleConflict) {
            throw SlotOccupiedException::fromStudentConflict(
                $studentClassId,
                $sessionDate,
                $startTime,
                null,
                $scheduleConflict->status !== null ? (string) $scheduleConflict->status : null,
            );
        }
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
