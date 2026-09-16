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
 * Campus presence lifecycle (#2809 RFID-1).
 * Must never call SessionDeductionService or AttendanceEffectsService.
 */
class StudentCampusPresenceService
{
    public const MATCH_WINDOW_MINUTES = 30;
    public const DEBOUNCE_SECONDS = 60;

    public function recordArrival(
        Student $student,
        int $campusId,
        Carbon $arrivedAt,
        string $source = StudentCampusPresence::SOURCE_RFID,
        ?string $deviceId = null,
        ?string $rfidUid = null,
        ?string $idempotencyKey = null,
    ): StudentCampusPresence {
        $key = $idempotencyKey ?: $this->buildIdempotencyKey($campusId, (string) ($rfidUid ?: (int) $student->getKey()), $deviceId, $arrivedAt, 'in');

        return DB::transaction(function () use ($student, $campusId, $arrivedAt, $source, $deviceId, $rfidUid, $key) {
            if ($hit = StudentCampusPresence::query()->where('IdempotencyKey', $key)->lockForUpdate()->first()) {
                return $hit;
            }
            $open = $this->lockOpen((int) $student->getKey(), $campusId);
            if ($open) {
                return $open;
            }

            return StudentCampusPresence::create([
                'CampusID' => $campusId,
                'StudentID' => (int) $student->getKey(),
                'Source' => $source,
                'DeviceID' => $deviceId,
                'RfidUidHash' => $rfidUid ? hash('sha256', $rfidUid) : null,
                'ArrivedAt' => $arrivedAt,
                'Status' => StudentCampusPresence::STATUS_OPEN,
                'IdempotencyKey' => $key,
            ]);
        });
    }

    public function recordDeparture(
        Student $student,
        int $campusId,
        Carbon $departedAt,
        string $closeReason = StudentCampusPresence::CLOSE_SWIPE_OUT,
        ?string $deviceId = null,
        ?string $rfidUid = null,
        ?string $idempotencyKey = null,
    ): ?StudentCampusPresence {
        $key = $idempotencyKey ?: $this->buildIdempotencyKey($campusId, (string) ($rfidUid ?: (int) $student->getKey()), $deviceId, $departedAt, 'out');

        return DB::transaction(function () use ($student, $campusId, $departedAt, $closeReason, $key) {
            if ($hit = StudentCampusPresence::query()->where('IdempotencyKey', $key)->lockForUpdate()->first()) {
                return $hit;
            }
            $open = $this->lockOpen((int) $student->getKey(), $campusId);
            if (!$open) {
                return null;
            }
            if (Carbon::parse($open->ArrivedAt)->diffInSeconds($departedAt) <= self::DEBOUNCE_SECONDS) {
                return $open;
            }
            $open->fill([
                'DepartedAt' => $departedAt,
                'Status' => StudentCampusPresence::STATUS_CLOSED,
                'CloseReason' => $closeReason,
            ])->save();

            return $open;
        });
    }

    /** @return array{action: string, presence: ?StudentCampusPresence} */
    public function toggleSwipe(Student $student, int $campusId, Carbon $at, ?string $deviceId = null, ?string $rfidUid = null): array
    {
        $open = StudentCampusPresence::query()
            ->where('StudentID', (int) $student->getKey())
            ->where('CampusID', $campusId)
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->orderByDesc('id')
            ->first();

        if ($open) {
            if (Carbon::parse($open->ArrivedAt)->diffInSeconds($at) <= self::DEBOUNCE_SECONDS) {
                return ['action' => 'duplicate_ignored', 'presence' => $open];
            }

            return ['action' => 'sign_out', 'presence' => $this->recordDeparture($student, $campusId, $at, StudentCampusPresence::CLOSE_SWIPE_OUT, $deviceId, $rfidUid)];
        }

        return ['action' => 'sign_in', 'presence' => $this->recordArrival($student, $campusId, $at, StudentCampusPresence::SOURCE_RFID, $deviceId, $rfidUid)];
    }

    public function orphanCloseBefore(string $beforeDate): int
    {
        $count = 0;
        StudentCampusPresence::query()
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->whereDate('ArrivedAt', '<', $beforeDate)
            ->each(function (StudentCampusPresence $row) use (&$count) {
                $row->fill([
                    'DepartedAt' => Carbon::parse($row->ArrivedAt)->endOfDay(),
                    'Status' => StudentCampusPresence::STATUS_ORPHAN_CLOSED,
                    'CloseReason' => StudentCampusPresence::CLOSE_ORPHAN_JOB,
                ])->save();
                $count++;
            });

        return $count;
    }

    /** @return array{candidates: list<array<string, mixed>>, ambiguous: bool, exception: ?string} */
    public function candidatesForStudent(Student $student, Carbon $at, ?Carbon $departedAt = null): array
    {
        $today = $at->toDateString();
        $window = self::MATCH_WINDOW_MINUTES;
        $sessions = ClassSession::query()
            ->with(['studentClass.subjectRecord'])
            ->whereHas('studentClass', fn ($q) => $q->where('StudentID', (int) $student->getKey())->where('Stop', 0))
            ->whereDate('SessionDate', $today)
            ->whereNotIn(DB::raw('LOWER(Status)'), array_merge([SessionStatus::CANCELLED], SessionStatus::leaveFamily()))
            ->orderBy('StartTime')
            ->get();

        $leaveArrived = ClassSession::query()
            ->whereHas('studentClass', fn ($q) => $q->where('StudentID', (int) $student->getKey())->where('Stop', 0))
            ->whereDate('SessionDate', $today)
            ->whereIn(DB::raw('LOWER(Status)'), SessionStatus::leaveFamily())
            ->exists();

        $ranked = [];
        foreach ($sessions as $session) {
            $start = Carbon::parse($session->SessionDate . ' ' . $session->StartTime);
            $end = $session->EndTime
                ? Carbon::parse($session->SessionDate . ' ' . $session->EndTime)
                : $start->copy()->addHour();
            $windowStart = $start->copy()->subMinutes($window);
            $inWindow = $at->betweenIncluded($windowStart, $end)
                || ($departedAt && $start->betweenIncluded($at, $departedAt));
            if (!$inWindow && !$departedAt) {
                if (!($at->lt($windowStart) && $start->isSameDay($at))) {
                    continue;
                }
                $priority = 30;
            } elseif ($at->betweenIncluded($start, $end)) {
                $priority = 10;
            } elseif ($at->lt($start) && $at->gte($windowStart)) {
                $priority = 20;
            } elseif ($departedAt && $start->betweenIncluded($at, $departedAt)) {
                $priority = 15;
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
                'subject' => $sc?->subjectRecord?->Subject_Name,
                'teacher_id' => $sc?->TeacherID,
            ];
        }

        usort($ranked, fn ($a, $b) => [$a['priority'], $a['start_time']] <=> [$b['priority'], $b['start_time']]);
        $top = $ranked[0]['priority'] ?? null;
        $topCount = $top === null ? 0 : count(array_filter($ranked, fn ($r) => $r['priority'] === $top));

        return [
            'candidates' => $ranked,
            'ambiguous' => $topCount > 1,
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

    public function buildIdempotencyKey(int $campusId, string $identity, ?string $deviceId, Carbon $at, string $direction): string
    {
        $window = (int) floor($at->timestamp / self::DEBOUNCE_SECONDS);

        return hash('sha256', implode('|', [$campusId, $identity, $deviceId ?: '-', $direction, $window]));
    }

    private function lockOpen(int $studentId, int $campusId): ?StudentCampusPresence
    {
        return StudentCampusPresence::query()
            ->where('StudentID', $studentId)
            ->where('CampusID', $campusId)
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }
}
