<?php

namespace App\Services;

use App\Exceptions\SuccessorShiftRefused;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Support\SessionStatus;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * In-app #410 / #4152: a leave on an old count contract whose make-up tail would land inside an already
 * pre-created renewal (same student and subject). Founder design: preview, then shift the renewal back.
 *
 * - The old contract's tail takes the renewal's first slot; every renewal lesson moves to the next lesson's
 *   slot and the last one to the renewal's next contract slot.
 * - Only lessons that are scheduled, not in the past, with no attendance, no deduction ledger row and no
 *   approved evaluation may move. Any ineligible lesson or teacher / student / classroom conflict blocks
 *   the whole operation; nothing is ever partially applied.
 * - Apply re-plans under row locks and writes only when the plan hashes to the previewed token.
 * - No ledger row is written for either contract (never deduct across contracts).
 * ClassSession rows stay the source of truth (G-010); moved rows keep their occurrence identity (G-007).
 */
class CourseLeaveCascadeSuccessorShiftService
{
    public const NOTE_SHIFT = 'successor-shift-after-leave';
    public const NOTE_ANCHOR = 'successor-shift-anchor';

    public function __construct(private ScheduleGuardService $guard, private LeaveAttendanceService $leaveAttendance)
    {
    }

    /** #4210 Q9: both the affected slots and renewal boundary must be reconciled explicitly. */
    public static function assertUndoAllowed(StudentClass $old, int $leaveSessionId): void
    {
        $marker = self::NOTE_SHIFT . ':ls=' . $leaveSessionId . ':from=';
        $leaveNote = (string) ClassSession::query()->where('StudentClassID', (int) $old->ID)->whereKey($leaveSessionId)->lockForUpdate()->value('Note');
        $tail = self::shiftTail((int) $old->ID, $leaveSessionId);
        $anchor = [];
        $hasAnchor = preg_match('/(?:^|;\s*)' . self::NOTE_ANCHOR . ':succ=(\d+):n=(\d+):state=(active|undone)(?=;|$)/', $leaveNote, $anchor);
        if ($hasAnchor && $anchor[3] === 'undone' && !$tail) {
            return; // Historical row notes must not block a later ordinary leave.
        }
        $tailMatch = [];
        if (!$hasAnchor && $tail) {
            preg_match(self::tailPattern($leaveSessionId), (string) $tail->Note, $tailMatch);
        }
        $expectedCourse = $hasAnchor ? (int) $anchor[1] : (isset($tailMatch[1]) ? (int) $tailMatch[1] : null);
        $expectedCount = $hasAnchor && $anchor[3] === 'active' ? (int) $anchor[2] : (isset($tailMatch[2]) ? (int) $tailMatch[2] : null);

        $rows = ClassSession::query()->select('ClassSession.*')
            ->join('StudentClass as renewal', 'renewal.ID', '=', 'ClassSession.StudentClassID')
            ->where('renewal.StudentID', (int) $old->StudentID)->where('renewal.ID', '!=', (int) $old->ID)
            ->when($expectedCourse !== null, fn ($q) => $q->where('renewal.ID', $expectedCourse))
            ->where('ClassSession.Note', 'like', '%' . $marker . '%')->orderBy('ClassSession.id')->lockForUpdate()->get();
        // Supported contract updates cannot change StudentID, but can change SubjectID.
        // Provenance therefore stays student-scoped without depending on mutable subjects.
        $affected = [];
        $boundaries = [];
        $seen = 0;
        foreach ($rows as $row) {
            if (!preg_match('/(?:^|;\s*)' . preg_quote($marker, '/') . '(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}(?::\d{2})?)(?::end=(\d{2}:\d{2}:\d{2}))?(?=;|$)/', (string) $row->Note, $match)) {
                continue;
            }
            $seen++;
            $date = Carbon::parse($row->SessionDate)->toDateString();
            $start = substr((string) $row->StartTime, 0, 8);
            $end = substr((string) $row->EndTime, 0, 8);
            $fromStart = strlen($match[2]) === 5 ? $match[2] . ':00' : $match[2];
            $fromEnd = $match[3] ?? null;
            $courseId = (int) $row->StudentClassID;
            $boundary = [];
            if ($expectedCount && $fromEnd !== null && $date === $match[1] && $start === $fromStart && $end === $fromEnd) {
                if (!array_key_exists($courseId, $boundaries)) {
                    $renewal = StudentClass::query()->whereKey($courseId)->lockForUpdate()->first();
                    $lessons = ClassSession::query()->where('StudentClassID', $courseId)->orderBy('id')->lockForUpdate()->get();
                    $lastDate = $lessons->filter(fn ($lesson) => strtolower((string) $lesson->Status) !== 'cancelled')
                        ->map(fn ($lesson) => Carbon::parse($lesson->SessionDate)->toDateString())->max();
                    $endDate = $renewal && $renewal->EndDate ? Carbon::parse($renewal->EndDate)->toDateString() : null;
                    $boundaries[$courseId] = ['course_end_date' => $endDate, 'last_lesson_date' => $lastDate];
                }
                $boundary = $boundaries[$courseId];
                if ($boundary['course_end_date'] !== null && $boundary['last_lesson_date'] !== null
                    && $boundary['course_end_date'] === $boundary['last_lesson_date']) {
                    continue;
                }
            }
            // Include past/consumed/cancelled rows too: undo must never rewrite their history.
            $affected[] = ['course_id' => $courseId, 'session_id' => (int) $row->id,
                'reason' => $fromEnd === null || !$expectedCount ? 'shift_provenance_incomplete' : ($boundary === [] ? 'shifted_slot' : 'renewal_end_date_mismatch'),
                'from' => ['date' => $match[1], 'start' => $fromStart, 'end' => $fromEnd],
                'to' => ['date' => $date, 'start' => $start, 'end' => $end]] + $boundary;
        }
        if ($expectedCourse !== null && (!$expectedCount || $seen !== $expectedCount || !$tail)) {
            $owner = StudentClass::query()->whereKey($expectedCourse)->where('StudentID', (int) $old->StudentID)->lockForUpdate()->first();
            $lesson = $owner ? ClassSession::query()->where('StudentClassID', $expectedCourse)->orderBy('id')->lockForUpdate()->first() : null;
            $affected[] = ['course_id' => $owner ? $expectedCourse : (int) $old->ID,
                'session_id' => $owner ? ($lesson ? (int) $lesson->id : null) : $leaveSessionId,
                'reason' => 'shift_provenance_missing', 'expected_lessons' => $expectedCount, 'observed_lessons' => $seen];
        }
        if ($affected !== []) {
            throw new SuccessorShiftRefused('此請假的續約順延尚未確認完整復原，無法撤銷請假。請先核對受影響的續約堂次與順延紀錄。', 422,
                ['affected_lessons' => $affected]);
        }
    }

    /** Stable old-contract tail identity; renewal rows may have been rebuilt. */
    public static function shiftTail(int $courseId, int $leaveSessionId): ?ClassSession
    {
        return ClassSession::query()->where('StudentClassID', $courseId)
            ->where('Note', 'like', '%:ls=' . $leaveSessionId . ':succ=%')->orderBy('id')->lockForUpdate()->get()
            ->first(fn ($row) => preg_match(self::tailPattern($leaveSessionId), (string) $row->Note));
    }

    private static function tailPattern(int $leaveSessionId): string
    {
        return '/(?:^|;\s*)' . CourseLeaveCascadeService::NOTE_AUTO_EXTENDED . ':ld=\d{4}-\d{2}-\d{2}:ls='
            . $leaveSessionId . ':succ=(\d+)(?::n=(\d+))?(?=;|$)/';
    }

    public static function resolvedAnchorNote(string $note): string
    {
        return (string) preg_replace('/(' . self::NOTE_ANCHOR . ':succ=\d+:n=\d+:state=)active(?=;|$)/', '${1}undone', $note);
    }

    private static function boundedNote(string $note, ClassSession $session): string
    {
        if (mb_strlen($note) > 255) {
            throw new SuccessorShiftRefused('堂次備註空間不足以保留完整順延紀錄，請先整理備註後再重試，未做任何變更。', 422,
                ['affected_lessons' => [['course_id' => (int) $session->StudentClassID, 'session_id' => (int) $session->id, 'reason' => 'shift_note_capacity']]]);
        }
        return $note;
    }

    /** @return array<string, mixed> */
    public function preview(int $leaveSessionId): array
    {
        return $this->plan($leaveSessionId, false);
    }

    /**
     * @return array<string, mixed> the applied plan, or ['already_applied' => true, …] on a repeat
     * @throws SuccessorShiftRefused when the plan is not applicable, blocked or no longer matches the token
     */
    public function apply(int $leaveSessionId, string $token, int $actorUserId): array
    {
        if (!config('perfflags.leave_successor_shift_apply_enabled', false)) { // off until undo-leave refuses an active shift (Q9a)
            throw new SuccessorShiftRefused('續約順延尚未開放（撤銷請假的保護尚未上線），未做任何變更', 403);
        }
        return DB::transaction(function () use ($leaveSessionId, $token, $actorUserId) {
            $plan = $this->plan($leaveSessionId, true);
            if (!empty($plan['already_applied'])) {
                return $plan;
            }
            if (empty($plan['applicable'])) {
                throw new SuccessorShiftRefused((string) ($plan['reason'] ?? '此請假不需要順延續約課程'), 422, $plan);
            }
            if (!empty($plan['blockers'])) {
                throw new SuccessorShiftRefused('有堂次不可移動或時段衝突，未做任何變更', 422, $plan);
            }
            if (!hash_equals((string) $plan['preview_token'], $token)) {
                throw new SuccessorShiftRefused('課表已變動，請重新預覽後再確認', 409, $plan);
            }

            /** @var ClassSession $leave */ $leave = ClassSession::query()->whereKey($leaveSessionId)->firstOrFail();
            $leave->Note = trim((string) preg_replace('/(?:^|;\s*)' . self::NOTE_ANCHOR . ':[^;]*(?=;|$)/', '', (string) $leave->Note), '; ');
            $leaveSuffix = CourseLeaveCascadeService::NOTE_LEAVE . '; ' . self::NOTE_ANCHOR . ':succ='
                . $plan['successor_course_id'] . ':n=' . (count($plan['moves']) - 1) . ':state=active';
            self::boundedNote(CourseLeaveCascadeService::appendNote(
                CourseLeaveCascadeService::appendNote($leave->Note, $leaveSuffix), CourseLeaveCascadeService::NOTE_REVERT_TO_SCHEDULED), $leave);
            CourseLeaveCascadeService::markSessionLeaveOnly($leave, $leaveSuffix);
            /** @var StudentClass $old */ $old = StudentClass::query()->whereKey($plan['old_course_id'])->firstOrFail();
            $campusId = (int) (DB::table('Student')->where('id', (int) $old->StudentID)->value('CampusID') ?? 0);
            if (!StudentSignIn::query()->where('ClassSessionID', $leaveSessionId)->exists()) {
                $this->leaveAttendance->createClosedForSession($leave, $old, $actorUserId ?: null, null, $campusId ?: null);
            }

            CourseLeaveCascadeService::recordLeaveMarker($old, $leave, $campusId);

            // Last lesson first, so each row moves into a slot its successor has already left.
            foreach (array_reverse($plan['moves']) as $move) {
                if ($move['role'] !== 'successor') {
                    continue;
                }
                /** @var ClassSession $row */ $row = ClassSession::query()->whereKey((int) $move['session_id'])->firstOrFail();
                $row->SessionDate = $move['to']['date'];
                $row->StartTime = $move['to']['start'];
                $row->EndTime = $move['to']['end'];
                $previousNote = trim((string) preg_replace('/(?:^|;\s*)' . self::NOTE_SHIFT . ':ls=' . $leaveSessionId . ':from=[^;]*(?=;|$)/', '', (string) $row->Note), '; ');
                $row->Note = self::boundedNote(CourseLeaveCascadeService::appendNote($previousNote, self::NOTE_SHIFT . ':ls=' . $leaveSessionId . ':from='
                    . $move['from']['date'] . 'T' . $move['from']['start'] . ':end=' . $move['from']['end']), $row);
                $row->save();
                CourseLeaveCascadeService::syncLearningRecordSessionDate($row);
            }

            $tail = $plan['moves'][0]['to'];
            $created = app(ClassSessionMaterializationService::class)->upsertSlot([
                'StudentClassID' => $plan['old_course_id'],
                'SessionDate' => $tail['date'],
                'StartTime' => $tail['start'],
                'EndTime' => $tail['end'],
                'Status' => 'scheduled',
                'Note' => CourseLeaveCascadeService::NOTE_AUTO_EXTENDED . ':ld=' . $plan['leave_session']['date']
                    . ':ls=' . $leaveSessionId . ':succ=' . $plan['successor_course_id'] . ':n=' . (count($plan['moves']) - 1),
                '_revive_cancelled' => true,
            ]);
            if (!$created['created']) {
                throw new SuccessorShiftRefused('補堂時段已被其他堂次占用，未做任何變更', 409, $plan);
            }

            DB::table('StudentClass')->where('ID', $plan['old_course_id'])->update(['EndDate' => $plan['old_end_date']]);
            DB::table('StudentClass')->where('ID', $plan['successor_course_id'])->update(['EndDate' => $plan['successor_end_date']]);

            return $plan + ['applied' => true];
        }, 3); // Existing leave entry points can contend; retry the whole atomic re-plan, never a partial write.
    }

    /** @return array<string, mixed> */
    private function plan(int $leaveSessionId, bool $lock): array
    {
        // Match leaveBySession: selected ClassSession, its course, then the remaining sessions.
        $identity = ClassSession::query()->whereKey($leaveSessionId)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $courseId = (int) ($identity->StudentClassID ?? 0);
        $old = StudentClass::query()->whereKey($courseId)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (!$old) {
            throw new \InvalidArgumentException('找不到堂次');
        }
        $oldSessions = $this->sessions((int) $old->ID, $lock);
        $leave = $oldSessions->first(fn ($s) => (int) $s->id === $leaveSessionId);
        if (!$leave instanceof ClassSession) {
            throw new \InvalidArgumentException('找不到堂次');
        }
        $leaveDate = Carbon::parse($leave->SessionDate)->toDateString();
        $status = strtolower((string) $leave->Status);

        if ($status === CourseLeaveCascadeService::NOTE_LEAVE && $oldSessions->contains(fn ($s) => str_contains((string) $s->Note, ':ls=' . $leaveSessionId . ':succ='))) {
            return ['applicable' => true, 'already_applied' => true, 'leave_session' => ['id' => $leaveSessionId, 'date' => $leaveDate]];
        }
        if (strtolower((string) ($old->ScheduleMode ?? 'count')) === 'date'
            || (string) ($old->scheduling_policy ?? 'auto_recurrence') !== 'auto_recurrence' || (int) ($old->Stop ?? 0) === 1) {
            return $this->notApplicable('此課程請假不會補尾堂');
        }
        if ($status !== 'scheduled') {
            return $this->notApplicable('只有尚未上課的排定堂次可以請假');
        }
        $append = CourseLeaveCascadeService::previewLeaveCascadeForCourse((int) $old->ID, $leaveDate, $leaveSessionId)['append'] ?? null;
        if (!$append) {
            return $this->notApplicable('此請假不需要補尾堂');
        }

        [$successor, $chain] = $this->successor($old, $leaveDate, $lock);
        if (!$successor || (string) $append < Carbon::parse($chain->first()->SessionDate)->toDateString()) {
            return $this->notApplicable('尾堂不會排進續約課程，使用一般請假即可');
        }

        $blockers = [];
        $leaveReason = $this->ineligibleReason($leave, '請假堂次', $lock);
        if ($leaveReason !== null) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $old->ID, 'session_id' => (int) $leave->id, 'message' => $leaveReason];
        }
        if ($old->isUsageSettlementLocked()) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $old->ID, 'session_id' => (int) $leave->id, 'message' => '原課程已結算，無法自動順延'];
        }
        if ((string) ($successor->scheduling_policy ?? 'auto_recurrence') !== 'auto_recurrence' || $successor->isUsageSettlementLocked()) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => null, 'message' => '續約課程為手動排課或已結算，無法自動順延'];
        }
        foreach ($chain as $row) {
            if ($reason = $this->ineligibleReason($row, '續約堂次', $lock)) {
                $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => (int) $row->id, 'date' => Carbon::parse($row->SessionDate)->toDateString(), 'message' => $reason];
            }
        }

        $slots = $chain->map(fn ($s) => $this->slot($s->SessionDate, $s->StartTime, $s->EndTime))->values()->all();
        $minutes = Carbon::parse($leaveDate . ' ' . $leave->StartTime)->diffInMinutes(Carbon::parse($leaveDate . ' ' . $leave->EndTime), false);
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('堂次時間無效，無法自動順延');
        }
        $first = $slots[0];
        $tailStart = Carbon::parse($first['date'] . ' ' . $first['start']);
        $tailEnd = $tailStart->copy()->addMinutes($minutes);
        if (!$tailEnd->isSameDay($tailStart)) {
            throw new \InvalidArgumentException('跨日補堂需先核對，無法自動順延');
        }
        $moves = [[
            'role' => 'old_tail', 'course_id' => (int) $old->ID, 'session_id' => null, 'from' => null,
            'to' => $this->slot($first['date'], $first['start'], $tailEnd->format('H:i:s')),
        ]];
        foreach ($chain->values() as $i => $row) {
            $moves[] = [
                'role' => 'successor', 'course_id' => (int) $successor->ID, 'session_id' => (int) $row->id,
                'from' => $slots[$i], 'to' => $slots[$i + 1] ?? $this->nextSuccessorSlot($successor, $chain),
            ];
        }

        $movingIds = array_merge([(int) $leave->id], $chain->pluck('id')->map(fn ($id) => (int) $id)->all());
        $branchId = (int) (DB::table('Student')->where('id', (int) $old->StudentID)->value('CampusID') ?? 0);
        foreach ($moves as $move) {
            $course = $move['role'] === 'old_tail' ? $old : $successor;
            $blockers = array_merge($blockers, $this->conflicts($course, $branchId, $move['to'], $movingIds, $move['session_id'], $lock, $move['from']));
        }

        $oldDates = $oldSessions->filter(fn ($s) => strtolower((string) $s->Status) !== 'cancelled')
            ->map(fn ($s) => Carbon::parse($s->SessionDate)->toDateString())->push($moves[0]['to']['date'])->all();
        $plan = [
            'applicable' => true,
            'leave_session' => ['id' => (int) $leave->id, 'date' => $leaveDate, 'start' => substr((string) $leave->StartTime, 0, 5), 'end' => substr((string) $leave->EndTime, 0, 5)],
            'old_course_id' => (int) $old->ID,
            'successor_course_id' => (int) $successor->ID,
            // Bind current boundaries as well as proposed endings before overwriting either contract.
            'current_end_dates' => [
                'old' => $old->EndDate ? Carbon::parse($old->EndDate)->toDateString() : null,
                'successor' => $successor->EndDate ? Carbon::parse($successor->EndDate)->toDateString() : null,
            ],
            'moves' => $moves,
            'old_end_date' => max($oldDates),
            'successor_end_date' => (string) end($moves)['to']['date'],
        ];
        $plan['preview_token'] = hash('sha256', (string) json_encode($plan));

        return $plan + ['eligible' => $blockers === [], 'blockers' => $blockers];
    }

    /** @return array{0: ?StudentClass, 1: Collection<int, ClassSession>} the earliest live renewal starting after the leave, with its live lessons */
    private function successor(StudentClass $old, string $leaveDate, bool $lock): array
    {
        $candidates = StudentClass::query()
            ->where('StudentID', (int) $old->StudentID)
            ->where('SubjectID', (int) $old->SubjectID)
            ->where('ID', '!=', (int) $old->ID)
            ->where(fn ($q) => $q->where('Stop', 0)->orWhereNull('Stop'))
            ->whereRaw("LOWER(COALESCE(ScheduleMode, 'count')) <> 'date'")
            ->orderBy('ID')
            ->get();
        $best = [null, collect()];
        foreach ($candidates as $candidate) {
            $chain = $this->sessions((int) $candidate->ID, false)
                ->filter(fn ($s) => strtolower((string) $s->Status) !== 'cancelled')->values();
            $start = $chain->isEmpty() ? null : Carbon::parse($chain->first()->SessionDate)->toDateString();
            if ($start !== null && $start > $leaveDate
                && ($best[0] === null || $start < Carbon::parse($best[1]->first()->SessionDate)->toDateString())) {
                $best = [$candidate, $chain];
            }
        }

        if ($lock && $best[0] !== null) {
            $chosen = StudentClass::query()->whereKey((int) $best[0]->ID)->lockForUpdate()->first();
            if (!$chosen) throw new \InvalidArgumentException('續約課程已變動，請重新預覽');
            $best = [$chosen, $this->sessions((int) $chosen->ID, true)
                ->filter(fn ($s) => strtolower((string) $s->Status) !== 'cancelled')->values()];
            if ((int) $chosen->StudentID !== (int) $old->StudentID || (int) $chosen->SubjectID !== (int) $old->SubjectID
                || (int) $chosen->Stop === 1 || $best[1]->isEmpty()) {
                throw new \InvalidArgumentException('續約課程已變動，請重新預覽');
            }
        }
        return $best;
    }

    /** @return Collection<int, ClassSession> */
    private function sessions(int $courseId, bool $lock): Collection
    {
        return ClassSession::query()->where('StudentClassID', $courseId)
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    private function ineligibleReason(ClassSession $row, string $label = '續約堂次', bool $lock = false): ?string
    {
        $id = (int) $row->id;

        return match (true) {
            strtolower((string) $row->Status) !== 'scheduled' => $label . '不是排定狀態（已上課、請假或其他）',
            Carbon::parse($row->SessionDate . ' ' . $row->EndTime)->lte(Carbon::parse($row->SessionDate . ' ' . $row->StartTime)) => $label . '時間無效或跨日，需先核對',
            Carbon::parse($row->SessionDate . ' ' . $row->StartTime)->lte(Carbon::now()) => $label . '上課時間已過',
            StudentSignIn::query()->where('ClassSessionID', $id)->whereNull('VoidedAt')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null => $label . '已有出缺勤紀錄',
            SessionDeductionLedger::query()->where('class_session_id', $id)
                ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null => $label . '已有扣堂紀錄',
            $this->hasApprovedEvaluation($id, $lock) => $label . '已有核准評量',
            StudentSignIn::query()->where('ClassSessionID', $id)->whereNotNull('VoidedAt')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null => $label . '已有作廢出缺勤歷史，需先核對紀錄，無法自動順延',
            LearningRecord::query()->where('ClassSessionID', $id)->whereNotNull('VoidedAt')
                ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null => $label . '已有作廢評量歷史，需先核對紀錄，無法自動順延',
            $this->hasOccurrenceAnchor((int) $row->StudentClassID, (string) $row->SessionDate, (string) $row->StartTime, $lock)
                || ($row->OriginalSessionDate && $this->hasOccurrenceAnchor((int) $row->StudentClassID, (string) $row->OriginalSessionDate, (string) $row->OriginalStartTimeHM, $lock)) => $label . '已有單堂排課或代課紀錄，請先核對該堂，無法自動順延',
            default => null,
        };
    }

    private function hasApprovedEvaluation(int $sessionId, bool $lock = false): bool
    {
        return LearningRecord::query()->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->where('Status', 'approved')
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null;
    }

    /** @return array{date: string, start: string, end: string} */
    private function nextSuccessorSlot(StudentClass $successor, Collection $chain): array
    {
        $last = $chain->last();
        $slots = ContractSessionSchedule::buildSlotsByWeekdayMap(
            ContractSessionSchedule::resolveScheduleSlotsForRebuild($successor), (int) $successor->SessionDuration);
        $cursor = Carbon::parse($last->SessionDate)->startOfDay();
        $after = Carbon::parse($last->SessionDate . ' ' . $last->EndTime);
        for ($i = 0; $i < 3660; $i++, $cursor->addDay()) {
            foreach ($slots[(int) $cursor->dayOfWeekIso] ?? [] as $slot) {
                $start = Carbon::parse($cursor->toDateString() . ' ' . $slot['time']);
                if ($start->lt($after) || $slot['dur'] <= 0) continue;
                $end = $start->copy()->addMinutes($slot['dur']);
                if (!$end->isSameDay($start)) throw new \InvalidArgumentException('跨日堂次需先核對，無法自動順延');
                return $this->slot($cursor->toDateString(), $start->format('H:i:s'), $end->format('H:i:s'));
            }
        }
        throw new \InvalidArgumentException('找不到續約下一個合約時段');
    }

    private function hasOccurrenceAnchor(int $courseId, string $date, string $start, bool $lock): bool
    {
        return DB::table('schedules')->where('student_course_id', $courseId)
            ->where(function ($q) use ($date, $start) {
                $q->where(fn ($q) => $q->whereDate('schedule_date', $date)->whereRaw('SUBSTRING(start_time,1,5) = ?', [substr($start, 0, 5)]))
                    ->orWhere(fn ($q) => $q->whereDate('original_schedule_date', $date)->whereRaw('SUBSTRING(original_start_time,1,5) = ?', [substr($start, 0, 5)]));
            })->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null;
    }

    /** @return array{date: string, start: string, end: string} */
    private function slot(mixed $date, mixed $start, mixed $end): array
    {
        $hms = fn ($t) => strlen(substr((string) $t, 0, 8)) === 5 ? substr((string) $t, 0, 5) . ':00' : substr((string) $t, 0, 8);

        return ['date' => Carbon::parse((string) $date)->toDateString(), 'start' => $hms($start), 'end' => $hms($end)];
    }

    /**
     * Teacher and classroom load come from the canonical ScheduleGuardService (same student excluded); the
     * student check sees every other live lesson of the student except the rows this plan moves.
     * Each blocker names the course and moving lesson (null for the new tail) so the UI can link to the fix (Q9a).
     *
     * @param  array{date: string, start: string, end: string}  $to
     * @param  list<int>  $movingIds
     * @return list<array<string, mixed>>
     */
    private function conflicts(StudentClass $course, int $branchId, array $to, array $movingIds, ?int $sessionId, bool $lock, ?array $from): array
    {
        $out = [];
        $at = ['course_id' => (int) $course->ID, 'session_id' => $sessionId, 'date' => $to['date'], 'start' => $to['start']];
        if ($this->hasOccurrenceAnchor((int) $course->ID, $to['date'], $to['start'], $lock)) {
            $out[] = ['type' => 'ineligible'] + $at + ['message' => '目的時段已有單堂排課紀錄，請先核對'];
        }
        $source = $from ?? $to;
        $effective = clone $course;
        $effective->TeacherID = SubstituteScheduleService::teacherForOccurrence((int) $course->ID, $source['date'], (int) $course->TeacherID, $source['start']);
        foreach ($this->guard->validateCourseOccurrence($effective, $branchId, $to['date'], $to['start'], $to['end']) as $c) {
            $room = str_contains((string) ($c['type'] ?? ''), 'room');
            $out[] = ['type' => $room ? 'classroom' : 'teacher'] + $at + ['message' => $room ? '教室在此時段已有課程衝突，請核對課表' : '授課老師在此時段已有課程衝突，請核對課表'];
        }
        $studentClash = ClassSession::query()->from('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', (int) $course->StudentID)
            ->whereDate('cs.SessionDate', $to['date'])
            ->whereNotIn('cs.id', $movingIds)
            ->whereRaw('LOWER(cs.Status) NOT IN (' . implode(',', array_fill(0, count(SessionStatus::futureReservationExclusionStatuses()), '?')) . ')', SessionStatus::futureReservationExclusionStatuses())
            ->where(fn ($q) => $q->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->where('cs.StartTime', '<', $to['end'])
            ->where('cs.EndTime', '>', $to['start'])
            ->exists();
        if ($studentClash) {
            $out[] = ['type' => 'student'] + $at + ['message' => '學生在此時段已有其他課程'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function notApplicable(string $reason): array
    {
        return ['applicable' => false, 'reason' => $reason];
    }
}
