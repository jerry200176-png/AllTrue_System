<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentCampusPresence;
use App\Support\SessionStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Campus presence lifecycle for RFID-1 (#2809).
 *
 * INVARIANT: this service must never call SessionDeductionService or
 * AttendanceEffectsService. Presence is not course attendance.
 */
class StudentCampusPresenceService
{
    public const MATCH_WINDOW_MINUTES = 30;
    public const DEBOUNCE_SECONDS = 60;

    /**
     * Record or reuse an arrival. Idempotent via IdempotencyKey and open-row guard.
     */
    public function recordArrival(
        Student $student,
        int $campusId,
        Carbon $arrivedAt,
        string $source = StudentCampusPresence::SOURCE_RFID,
        ?string $deviceId = null,
        ?string $rfidUid = null,
        ?string $idempotencyKey = null,
    ): StudentCampusPresence {
        $key = $idempotencyKey ?: $this->buildIdempotencyKey(
            $campusId,
            (string) ($rfidUid ?: $student->id),
            $deviceId,
            $arrivedAt,
            'in'
        );

        return DB::transaction(function () use ($student, $campusId, $arrivedAt, $source, $deviceId, $rfidUid, $key) {
            $existingByKey = StudentCampusPresence::query()
                ->where('IdempotencyKey', $key)
                ->lockForUpdate()
                ->first();
            if ($existingByKey) {
                return $existingByKey;
            }

            $open = StudentCampusPresence::query()
                ->where('StudentID', $student->id)
                ->where('CampusID', $campusId)
                ->where('Status', StudentCampusPresence::STATUS_OPEN)
                ->whereNull('DepartedAt')
                ->whereNull('VoidedAt')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($open) {
                $age = Carbon::parse($open->ArrivedAt)->diffInSeconds($arrivedAt);
                if ($age <= self::DEBOUNCE_SECONDS) {
                    return $open;
                }
                // Still on campus beyond debounce: keep the open interval (no second open row).
                return $open;
            }

            return StudentCampusPresence::create([
                'CampusID' => $campusId,
                'StudentID' => $student->id,
                'Source' => $source,
                'DeviceID' => $deviceId,
                'RfidUidHash' => $rfidUid ? hash('sha256', $rfidUid) : null,
                'ArrivedAt' => $arrivedAt,
                'DepartedAt' => null,
                'Status' => StudentCampusPresence::STATUS_OPEN,
                'CloseReason' => null,
                'IdempotencyKey' => $key,
            ]);
        });
    }

    /**
     * Close an open presence interval. Debounced duplicates return the open row unchanged.
     */
    public function recordDeparture(
        Student $student,
        int $campusId,
        Carbon $departedAt,
        string $closeReason = StudentCampusPresence::CLOSE_SWIPE_OUT,
        ?string $deviceId = null,
        ?string $rfidUid = null,
        ?string $idempotencyKey = null,
    ): ?StudentCampusPresence {
        $key = $idempotencyKey ?: $this->buildIdempotencyKey(
            $campusId,
            (string) ($rfidUid ?: $student->id),
            $deviceId,
            $departedAt,
            'out'
        );

        return DB::transaction(function () use ($student, $campusId, $departedAt, $closeReason, $key) {
            $existingByKey = StudentCampusPresence::query()
                ->where('IdempotencyKey', $key)
                ->lockForUpdate()
                ->first();
            if ($existingByKey) {
                return $existingByKey;
            }

            $open = StudentCampusPresence::query()
                ->where('StudentID', $student->id)
                ->where('CampusID', $campusId)
                ->where('Status', StudentCampusPresence::STATUS_OPEN)
                ->whereNull('DepartedAt')
                ->whereNull('VoidedAt')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$open) {
                return null;
            }

            $age = Carbon::parse($open->ArrivedAt)->diffInSeconds($departedAt);
            if ($age <= self::DEBOUNCE_SECONDS) {
                return $open;
            }

            $open->DepartedAt = $departedAt;
            $open->Status = StudentCampusPresence::STATUS_CLOSED;
            $open->CloseReason = $closeReason;
            // Preserve original arrival idempotency; departure retries use $key via early return above
            // only when client supplies departure key. Stamp a departure marker in CloseReason only.
            $open->save();

            return $open;
        });
    }

    /**
     * Toggle arrive/depart for door readers (RFID-2 will call this).
     *
     * @return array{action: string, presence: ?StudentCampusPresence}
     */
    public function toggleSwipe(
        Student $student,
        int $campusId,
        Carbon $at,
        ?string $deviceId = null,
        ?string $rfidUid = null,
    ): array {
        $open = StudentCampusPresence::query()
            ->where('StudentID', $student->id)
            ->where('CampusID', $campusId)
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->orderByDesc('id')
            ->first();

        if ($open) {
            $age = Carbon::parse($open->ArrivedAt)->diffInSeconds($at);
            if ($age <= self::DEBOUNCE_SECONDS) {
                return ['action' => 'duplicate_ignored', 'presence' => $open];
            }
            $closed = $this->recordDeparture($student, $campusId, $at, StudentCampusPresence::CLOSE_SWIPE_OUT, $deviceId, $rfidUid);
            return ['action' => 'sign_out', 'presence' => $closed];
        }

        $presence = $this->recordArrival($student, $campusId, $at, StudentCampusPresence::SOURCE_RFID, $deviceId, $rfidUid);
        return ['action' => 'sign_in', 'presence' => $presence];
    }

    /**
     * Close open presence rows whose ArrivedAt date is before $beforeDate (Y-m-d).
     */
    public function orphanCloseBefore(string $beforeDate): int
    {
        $rows = StudentCampusPresence::query()
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->whereDate('ArrivedAt', '<', $beforeDate)
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $row->DepartedAt = Carbon::parse($row->ArrivedAt)->endOfDay();
            $row->Status = StudentCampusPresence::STATUS_ORPHAN_CLOSED;
            $row->CloseReason = StudentCampusPresence::CLOSE_ORPHAN_JOB;
            $row->save();
            $count++;
        }

        return $count;
    }

    /**
     * Ranked ClassSession candidates overlapping a presence interval / point-in-time.
     * Never writes attendance or billing.
     *
     * @return array{candidates: list<array<string, mixed>>, ambiguous: bool, exception: ?string}
     */
    public function candidatesForStudent(
        Student $student,
        Carbon $at,
        ?Carbon $departedAt = null,
    ): array {
        $today = $at->toDateString();
        $window = self::MATCH_WINDOW_MINUTES;

        $sessions = ClassSession::query()
            ->with(['studentClass.subjectRecord'])
            ->whereHas('studentClass', fn ($q) => $q
                ->where('StudentID', $student->id)
                ->where('Stop', 0)
            )
            ->whereDate('SessionDate', $today)
            ->whereNotIn(DB::raw('LOWER(Status)'), array_merge(
                [SessionStatus::CANCELLED],
                SessionStatus::leaveFamily()
            ))
            ->orderBy('StartTime')
            ->get();

        $ranked = [];
        $leaveArrived = ClassSession::query()
            ->whereHas('studentClass', fn ($q) => $q->where('StudentID', $student->id)->where('Stop', 0))
            ->whereDate('SessionDate', $today)
            ->whereIn(DB::raw('LOWER(Status)'), SessionStatus::leaveFamily())
            ->exists();

        foreach ($sessions as $session) {
            $start = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);
            $end = $session->EndTime
                ? Carbon::parse($session->SessionDate . ' ' . $session->EndTime)
                : $start->copy()->addHour();

            $windowStart = $start->copy()->subMinutes($window);
            $inWindow = $at->betweenIncluded($windowStart, $end)
                || ($departedAt && $start->betweenIncluded($at, $departedAt));

            if (!$inWindow && !$departedAt) {
                // Also include upcoming today after early arrival (still candidates for UI).
                if ($at->lt($windowStart) && $start->isSameDay($at)) {
                    $priority = 30; // early / upcoming
                } else {
                    continue;
                }
            } elseif ($at->betweenIncluded($start, $end)) {
                $priority = 10; // ongoing
            } elseif ($at->lt($start) && $at->gte($windowStart)) {
                $priority = 20; // soon
            } elseif ($departedAt && $start->betweenIncluded($at, $departedAt)) {
                $priority = 15; // inside presence window
            } else {
                $priority = 30;
            }

            $sc = $session->studentClass;
            $ranked[] = [
                'priority' => $priority,
                'class_session_id' => $session->id,
                'student_class_id' => $sc?->ID,
                'status' => $session->Status,
                'start_time' => $session->StartTime,
                'end_time' => $session->EndTime,
                'subject' => $sc?->subjectRecord?->Subject_Name ?? null,
                'teacher_id' => $sc?->TeacherID,
            ];
        }

        usort($ranked, fn ($a, $b) => [$a['priority'], $a['start_time']] <=> [$b['priority'], $b['start_time']]);

        $topPriority = $ranked[0]['priority'] ?? null;
        $topCount = $topPriority === null ? 0 : count(array_filter($ranked, fn ($r) => $r['priority'] === $topPriority));
        $ambiguous = $topCount > 1;

        return [
            'candidates' => $ranked,
            'ambiguous' => $ambiguous,
            'exception' => $leaveArrived ? 'leave_but_arrived' : null,
        ];
    }

    public function openForCampus(int $campusId, ?Carbon $day = null): Collection
    {
        $day = $day ?: now();
        return StudentCampusPresence::query()
            ->with('student')
            ->where('CampusID', $campusId)
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->whereDate('ArrivedAt', $day->toDateString())
            ->orderBy('ArrivedAt')
            ->get();
    }

    public function buildIdempotencyKey(
        int $campusId,
        string $identity,
        ?string $deviceId,
        Carbon $at,
        string $direction,
    ): string {
        $window = (int) floor($at->timestamp / self::DEBOUNCE_SECONDS);
        $raw = implode('|', [
            $campusId,
            $identity,
            $deviceId ?: '-',
            $direction,
            $window,
        ]);

        return hash('sha256', $raw);
    }
}
