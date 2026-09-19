<?php
namespace App\Services;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentCampusPresence;
use App\Support\SessionStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
class StudentCampusPresenceService
{
    public const MATCH_WINDOW_MINUTES = 30;
    public const DEBOUNCE_SECONDS = 60;
    public function recordArrival(Student $student, int $campusId, Carbon $arrivedAt, string $source = StudentCampusPresence::SOURCE_RFID, ?string $deviceId = null, ?string $rfidUid = null, ?string $idempotencyKey = null): StudentCampusPresence {
        $this->assertStudentCampus($student, $campusId);
        $key = $idempotencyKey ?: $this->buildIdempotencyKey($campusId, (string) ($rfidUid ?: (int) $student->getKey()), $deviceId, $arrivedAt, 'in');
        return DB::transaction(function () use ($student, $campusId, $arrivedAt, $source, $deviceId, $rfidUid, $key) {
            if (!$student->newQuery()->whereKey($student->getKey())->lockForUpdate()->first()) {
                throw new \RuntimeException('student disappeared while locking');
            }
            if ($hit = $this->idempotencyHit($key, (int) $student->getKey(), $campusId)) {
                return $hit;
            }
            $open = $this->lockOpen((int) $student->getKey(), $campusId);
            if ($open) {
                return $open;
            }
            try {
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
            } catch (QueryException $exception) {
                $constraint = $this->duplicateConstraint($exception);
                if ($constraint === 'scp_idempotency_unique') {
                    return $this->idempotencyWinner($key, (int) $student->getKey(), $campusId);
                }
                if ($constraint === 'scp_one_open_student_campus') {
                    return $this->openWinner((int) $student->getKey(), $campusId);
                }
                if ($constraint === null) {
                    throw $exception;
                }
                throw $exception;
            }
        });
    }
    public function recordDeparture(Student $student, int $campusId, Carbon $departedAt, string $closeReason = StudentCampusPresence::CLOSE_SWIPE_OUT): ?StudentCampusPresence {
        $this->assertStudentCampus($student, $campusId);
        return DB::transaction(function () use ($student, $campusId, $departedAt, $closeReason) {
            if (!$student->newQuery()->whereKey($student->getKey())->lockForUpdate()->first()) {
                throw new \RuntimeException('student disappeared while locking');
            }
            $open = $this->lockOpen((int) $student->getKey(), $campusId);
            if (!$open) {
                return null;
            }
            if ($departedAt->lt(Carbon::parse($open->ArrivedAt))) {
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
    public function toggleSwipe(Student $student, int $campusId, Carbon $at, ?string $deviceId = null, ?string $rfidUid = null): array
    {
        $this->assertStudentCampus($student, $campusId);
        return DB::transaction(function () use ($student, $campusId, $at, $deviceId, $rfidUid) {
            if (!$student->newQuery()->whereKey($student->getKey())->lockForUpdate()->first()) {
                throw new \RuntimeException('student disappeared while locking');
            }
            $open = $this->lockOpen((int) $student->getKey(), $campusId);
            if ($open) {
                if (Carbon::parse($open->ArrivedAt)->diffInSeconds($at) <= self::DEBOUNCE_SECONDS) {
                    return ['action' => 'duplicate_ignored', 'presence' => $open];
                }
                if ($at->lt(Carbon::parse($open->ArrivedAt))) {
                    return ['action' => 'duplicate_ignored', 'presence' => $open];
                }
                $open->fill([
                    'DepartedAt' => $at,
                    'Status' => StudentCampusPresence::STATUS_CLOSED,
                    'CloseReason' => StudentCampusPresence::CLOSE_SWIPE_OUT,
                ])->save();
                return ['action' => 'sign_out', 'presence' => $open];
            }
            $key = $this->buildIdempotencyKey($campusId, (string) ($rfidUid ?: (int) $student->getKey()), $deviceId, $at, 'in');
            try {
                $presence = StudentCampusPresence::create([
                    'CampusID' => $campusId, 'StudentID' => (int) $student->getKey(),
                    'Source' => StudentCampusPresence::SOURCE_RFID, 'DeviceID' => $deviceId,
                    'RfidUidHash' => $rfidUid ? hash('sha256', $rfidUid) : null,
                    'ArrivedAt' => $at, 'Status' => StudentCampusPresence::STATUS_OPEN,
                    'IdempotencyKey' => $key,
                ]);
            } catch (QueryException $exception) {
                $constraint = $this->duplicateConstraint($exception);
                if ($constraint === 'scp_idempotency_unique') {
                    $presence = $this->idempotencyWinner($key, (int) $student->getKey(), $campusId);
                    return ['action' => 'duplicate_ignored', 'presence' => $presence];
                } elseif ($constraint === 'scp_one_open_student_campus') {
                    $presence = $this->openWinner((int) $student->getKey(), $campusId);
                    return ['action' => 'duplicate_ignored', 'presence' => $presence];
                } elseif ($constraint === null) {
                    throw $exception;
                } else {
                    throw $exception;
                }
            }
            return ['action' => 'sign_in', 'presence' => $presence];
        });
    }
    public function orphanCloseBefore(string $beforeDate): int
    {
        $count = 0;
        $ids = StudentCampusPresence::query()
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->whereDate('ArrivedAt', '<', $beforeDate)
            ->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $beforeDate, &$count): void {
                $studentId = StudentCampusPresence::query()->whereKey($id)->value('StudentID');
                if (!$studentId) {
                    return;
                }
                if (!(new Student())->newQuery()->whereKey($studentId)->lockForUpdate()->first()) {
                    throw new \RuntimeException('student disappeared while locking');
                }
                $row = StudentCampusPresence::query()->whereKey($id)->lockForUpdate()->first();
                if (!$row || !$row->isOpen() || Carbon::parse($row->ArrivedAt)->gte(Carbon::parse($beforeDate))) {
                    return;
                }
                $row->fill([
                    'DepartedAt' => Carbon::parse($row->ArrivedAt)->endOfDay(),
                    'Status' => StudentCampusPresence::STATUS_ORPHAN_CLOSED,
                    'CloseReason' => StudentCampusPresence::CLOSE_ORPHAN_JOB,
                ])->save();
                $count++;
            });
        }
        return $count;
    }
    public function candidatesForStudent(Student $student, Carbon $at, ?Carbon $departedAt = null): array
    {
        if (!$this->hasPresenceAt($student, $at, $departedAt)) {
            return ['candidates' => [], 'ambiguous' => false, 'exception' => null];
        }
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
    public function hasPresenceAt(Student $student, Carbon $at, ?Carbon $departedAt = null): bool
    {
        return StudentCampusPresence::query()->where('StudentID', (int) $student->getKey())
            ->where('CampusID', (int) $student->CampusID)
            ->where('ArrivedAt', '<=', $at)
            ->whereNull('VoidedAt')
            ->whereIn('Status', [StudentCampusPresence::STATUS_OPEN, StudentCampusPresence::STATUS_CLOSED, StudentCampusPresence::STATUS_ORPHAN_CLOSED])
            ->where(function ($query) use ($at, $departedAt) {
                $query->whereNull('DepartedAt')->orWhere('DepartedAt', '>=', $departedAt ?: $at);
            })->exists();
    }
    public function openForCampus(int $campusId): Collection
    {
        return StudentCampusPresence::query()
            ->with('student')
            ->where('CampusID', $campusId)
            ->where('Status', StudentCampusPresence::STATUS_OPEN)
            ->whereNull('DepartedAt')
            ->whereNull('VoidedAt')
            ->orderBy('ArrivedAt')
            ->get();
    }
    public function buildIdempotencyKey(int $campusId, string $identity, ?string $deviceId, Carbon $at, string $direction): string
    { $window = (int) floor($at->timestamp / self::DEBOUNCE_SECONDS); return hash('sha256', implode('|', [$campusId, $identity, $deviceId ?: '-', $direction, $window])); }
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
    private function idempotencyHit(string $key, int $studentId, int $campusId): ?StudentCampusPresence
    {
        $hit = StudentCampusPresence::query()->where('IdempotencyKey', $key)->lockForUpdate()->first();
        if ($hit && ((int) $hit->StudentID !== $studentId || (int) $hit->CampusID !== $campusId)) {
            throw new \RuntimeException('idempotency key belongs to another student/campus');
        }
        return $hit;
    }
    private function assertStudentCampus(Student $student, int $campusId): void
    {
        if ((int) $student->CampusID !== $campusId) {
            throw new \InvalidArgumentException('student campus mismatch');
        }
    }
    private function idempotencyWinner(string $key, int $studentId, int $campusId): StudentCampusPresence
    {
        $winner = (new StudentCampusPresence())->newQuery()->where('IdempotencyKey', $key)->where('StudentID', $studentId)->where('CampusID', $campusId)->lockForUpdate()->first();
        if (!$winner) {
            throw new \RuntimeException('idempotency winner disappeared');
        }
        return $winner;
    }
    private function openWinner(int $studentId, int $campusId): StudentCampusPresence
    {
        return $this->lockOpen($studentId, $campusId)
            ?? throw new \RuntimeException('open-slot duplicate winner disappeared');
    }
    private function duplicateConstraint(QueryException $exception): ?string
    {
        $info = $exception->errorInfo ?? [];
        if ((string) ($info[0] ?? '') !== '23000' || (string) ($info[1] ?? '') !== '1062') {
            return null;
        }
        $detail = strtolower((string) ($info[2] ?? $exception->getMessage()));
        foreach (['scp_idempotency_unique', 'scp_one_open_student_campus'] as $index) {
            if (str_contains($detail, strtolower($index))) {
                return $index;
            }
        }
        return 'unknown';
    }
}
