<?php

namespace App\Services;

use App\Exceptions\SuccessorShiftRefused;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Support\SessionStatus;
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
            if (!StudentSignIn::query()->where('ClassSessionID', $leaveSessionId)->whereNull('VoidedAt')->exists()) {
                $this->leaveAttendance->createClosedForSession($leave, $old, $actorUserId ?: null, null, $campusId ?: null);
            }
            CourseLeaveCascadeService::recordLeaveMarker($old, $leave, $campusId); // the same marker as leaveBySession (#4210 P2-3)

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
        }, 3); // A deadlock victim retries the whole atomic re-plan under locks; a retry never replays a partial write.
    }

    /** @return array<string, mixed> */
    private function plan(int $leaveSessionId, bool $lock): array
    {
        // Lock order matches leaveBySession: the selected ClassSession, its course, then the course's sessions (#4210 P3-1).
        $courseId = (int) ClassSession::query()->whereKey($leaveSessionId)->when($lock, fn ($q) => $q->lockForUpdate())->value('StudentClassID');
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
        // #4210 P2-3: the leave lesson itself must carry no attendance, deduction or approved evaluation. Voided
        // sign-in history refuses too: the closed leave sign-in written below needs the lesson's ClassSessionID,
        // which migrated schemas keep unique, so fail closed here instead of mid-write.
        if ($reason = $this->evidenceReason((int) $leave->id, '請假堂次', $lock, true)) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $old->ID, 'session_id' => (int) $leave->id, 'message' => $reason];
        }
        if ($old->isUsageSettlementLocked()) { // #4210 P3-3: point at the course the director has to open
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $old->ID, 'session_id' => (int) $leave->id, 'message' => '原課程已結算，無法自動順延'];
        }
        if ((string) ($successor->scheduling_policy ?? 'auto_recurrence') !== 'auto_recurrence'
            || $successor->isUsageSettlementLocked()) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => null, 'message' => '續約課程為手動排課或已結算，無法自動順延'];
        }
        // #4210 (Codex r4296589467): nextSuccessorSlot() steps by date, so a renewal with two slots on one weekday would
        // skip that day's later slot. Rare; refuse rather than plan a week too late.
        $weekdays = array_column(ContractSessionSchedule::resolveScheduleSlotsForRebuild($successor), 'weekday');
        if (count($weekdays) !== count(array_unique($weekdays))) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => null, 'message' => '續約課程同一天有兩個以上時段，無法自動順延，請手動調課'];
        }
        foreach ($chain as $row) {
            if ($reason = $this->ineligibleReason($row, $lock)) {
                $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => (int) $row->id, 'date' => Carbon::parse($row->SessionDate)->toDateString(), 'message' => $reason];
            }
        }
        // #4210 r4359415468: the conflict checks ignore a dated row of a moving lesson, but the old tail takes the
        // renewal's first slot, so a renewal row there (makeup, single-lesson booking or substitute) would be left
        // without its lesson (a makeup row also decides how that lesson is billed). Refuse instead of orphaning it.
        $opening = $chain->first();
        if (DB::table('schedules')->where('student_course_id', (int) $successor->ID)->where('status', 'scheduled')
            ->whereDate('schedule_date', Carbon::parse($opening->SessionDate)->toDateString())
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [substr((string) $opening->StartTime, 0, 5)])
            ->when($lock, fn ($q) => $q->lockForUpdate())->value('id') !== null) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => (int) $opening->id,
                'date' => Carbon::parse($opening->SessionDate)->toDateString(), 'message' => '續約第一堂在原時段有單堂排課紀錄（補課、單堂或代課），順延會讓它失去對應堂次，請先核對該堂'];
        }

        $slots = $chain->map(fn ($s) => $this->slot($s->SessionDate, $s->StartTime, $s->EndTime))->values()->all();
        // #4210 P3-4: the tail keeps the leave lesson's exact length (no 30-minute floor; lesson length is per course, G-011).
        $minutes = Carbon::parse($leaveDate . ' ' . $leave->StartTime)->diffInMinutes(Carbon::parse($leaveDate . ' ' . $leave->EndTime), false);
        $first = $slots[0];
        if ($minutes <= 0) {
            throw new \InvalidArgumentException('堂次時間無效，無法自動順延');
        }
        if (!Carbon::parse($first['date'] . ' ' . $first['start'])->addMinutes($minutes)->isSameDay(Carbon::parse($first['date']))) {
            throw new \InvalidArgumentException('跨日補堂需先核對，無法自動順延');
        }
        $moves = [[
            'role' => 'old_tail', 'course_id' => (int) $old->ID, 'session_id' => null, 'from' => null,
            'to' => $this->slot($first['date'], $first['start'], Carbon::parse($first['date'] . ' ' . $first['start'])->addMinutes($minutes)->format('H:i:s')),
        ]];
        foreach ($chain->values() as $i => $row) {
            $moves[] = [
                'role' => 'successor', 'course_id' => (int) $successor->ID, 'session_id' => (int) $row->id,
                'from' => $slots[$i], 'to' => $slots[$i + 1] ?? $this->nextSuccessorSlot($successor, $chain),
            ];
        }

        $movingIds = array_merge([(int) $leave->id], $chain->pluck('id')->map(fn ($id) => (int) $id)->all());
        $branchId = (int) (DB::table('Student')->where('id', (int) $old->StudentID)->value('CampusID') ?? 0);
        // #4210 review: the external checks below skip every moving row and each course's own lessons, so compare
        // this plan's destinations with each other and with the old contract's staying lessons (strict overlap:
        // back-to-back lessons stay allowed).
        $taken = array_merge($moves, $oldSessions->filter(fn ($s) => (int) $s->id !== (int) $leave->id
            && !in_array(strtolower((string) $s->Status), SessionStatus::futureReservationExclusionStatuses(), true))
            ->map(fn ($s) => ['to' => $this->slot($s->SessionDate, $s->StartTime, $s->EndTime)])->values()->all());
        foreach ($moves as $i => $move) {
            foreach (array_slice($taken, $i + 1) as $other) {
                if ($move['to']['date'] === $other['to']['date'] && $move['to']['start'] < $other['to']['end'] && $other['to']['start'] < $move['to']['end']) {
                    $blockers[] = ['type' => 'student', 'course_id' => $move['course_id'], 'session_id' => $move['session_id'],
                        'date' => $move['to']['date'], 'start' => $move['to']['start'], 'message' => '本次順延的目的時段互相重疊，請先核對'];
                }
            }
        }
        foreach ($moves as $move) {
            $course = $move['role'] === 'old_tail' ? $old : $successor;
            $blockers = array_merge($blockers, $this->conflicts($course, $branchId, $move['to'], $movingIds, $move['session_id'], $lock));
        }

        $oldDates = $oldSessions->filter(fn ($s) => strtolower((string) $s->Status) !== 'cancelled')
            ->map(fn ($s) => Carbon::parse($s->SessionDate)->toDateString())->push($moves[0]['to']['date'])->all();
        $plan = [
            'applicable' => true,
            'leave_session' => ['id' => (int) $leave->id, 'date' => $leaveDate, 'start' => substr((string) $leave->StartTime, 0, 5), 'end' => substr((string) $leave->EndTime, 0, 5)],
            'old_course_id' => (int) $old->ID,
            'successor_course_id' => (int) $successor->ID,
            // #4210 r4296589495: bind both current contract boundaries, so apply never overwrites an EndDate edited after preview.
            'current_end_dates' => array_map(fn (StudentClass $c) => $c->EndDate ? Carbon::parse($c->EndDate)->toDateString() : null,
                ['old' => $old, 'successor' => $successor]),
            'moves' => $moves,
            'old_end_date' => max($oldDates),
            'successor_end_date' => (string) end($moves)['to']['date'],
        ];
        $plan['preview_token'] = hash('sha256', (string) json_encode($plan));

        return $plan + ['eligible' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * The earliest live renewal starting after the leave, with its live lessons. The scan reads without locks; apply
     * then locks only the chosen renewal and its lessons and judges it again, on the current rows, by the same
     * isSuccessorCandidate() as preview, so no separate locked re-check can drift from it (#4210 P3-1 review).
     *
     * @return array{0: ?StudentClass, 1: Collection<int, ClassSession>}
     */
    private function successor(StudentClass $old, string $leaveDate, bool $lock): array
    {
        $candidates = StudentClass::query()
            ->where('StudentID', (int) $old->StudentID)
            ->where('SubjectID', (int) $old->SubjectID)
            ->where('ID', '!=', (int) $old->ID)
            ->orderBy('ID')
            ->get()
            ->filter(fn (StudentClass $candidate) => $this->isSuccessorCandidate($candidate));
        $best = [null, collect()];
        foreach ($candidates as $candidate) {
            $chain = $this->liveLessons((int) $candidate->ID, false);
            $start = $chain->isEmpty() ? null : Carbon::parse($chain->first()->SessionDate)->toDateString();
            if ($start !== null && $start > $leaveDate
                && ($best[0] === null || $start < Carbon::parse($best[1]->first()->SessionDate)->toDateString())) {
                $best = [$candidate, $chain];
            }
        }
        if (!$lock || $best[0] === null) {
            return $best;
        }
        $chosen = StudentClass::query()->whereKey((int) $best[0]->ID)->lockForUpdate()->first();
        $chain = $this->liveLessons((int) $best[0]->ID, true);

        return $chosen && $this->isSuccessorCandidate($chosen) && $chain->isNotEmpty()
            && Carbon::parse($chain->first()->SessionDate)->toDateString() > $leaveDate ? [$chosen, $chain] : [null, collect()];
    }

    /** @return Collection<int, ClassSession> */
    private function liveLessons(int $courseId, bool $lock): Collection
    {
        return $this->sessions($courseId, $lock)->filter(fn ($s) => strtolower((string) $s->Status) !== 'cancelled')->values();
    }

    /** A live count-mode contract; legacy blank or NULL modes are count, as everywhere else. */
    private function isSuccessorCandidate(StudentClass $candidate): bool
    {
        return (int) ($candidate->Stop ?? 0) !== 1
            && (strtolower(trim((string) $candidate->ScheduleMode)) ?: 'count') === 'count';
    }

    /** @return Collection<int, ClassSession> */
    private function sessions(int $courseId, bool $lock): Collection
    {
        return ClassSession::query()->where('StudentClassID', $courseId)
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    private function ineligibleReason(ClassSession $row, bool $lock): ?string
    {
        return match (true) {
            strtolower((string) $row->Status) !== 'scheduled' => '續約堂次不是排定狀態（已上課、請假或其他）',
            Carbon::parse($row->SessionDate)->toDateString() < Carbon::today()->toDateString() => '續約堂次日期已過',
            default => $this->evidenceReason((int) $row->id, '續約堂次', $lock),
        };
    }

    /** Live attendance (any sign-in with $anySignIn), a deduction or an approved evaluation; apply uses current locking reads. */
    private function evidenceReason(int $id, string $label, bool $lock, bool $anySignIn = false): ?string
    {
        $found = fn ($query) => $query->when($lock, fn ($q) => $q->lockForUpdate())->value('id') !== null;

        return match (true) {
            $found(StudentSignIn::query()->where('ClassSessionID', $id)->whereNull('VoidedAt')) => $label . '已有出缺勤紀錄',
            $anySignIn && $found(StudentSignIn::query()->where('ClassSessionID', $id)) => $label . '已有作廢的出缺勤紀錄，請先核對，無法自動順延',
            $found(SessionDeductionLedger::query()->where('class_session_id', $id)) => $label . '已有扣堂紀錄',
            $found(LearningRecord::query()->where('ClassSessionID', $id)->whereNull('VoidedAt')->where('Status', 'approved')) => $label . '已有核准評量',
            default => null,
        };
    }

    /** @return array{date: string, start: string, end: string} */
    private function nextSuccessorSlot(StudentClass $successor, Collection $chain): array
    {
        $last = $chain->last();
        $occupied = $chain->mapWithKeys(fn ($s) => [Carbon::parse($s->SessionDate)->toDateString() => true])->all();
        $weekdays = CourseLeaveCascadeService::resolveCourseWeekdays($successor, (int) Carbon::parse($last->SessionDate)->dayOfWeekIso);
        $date = CourseLeaveCascadeService::nextRecurringDate(Carbon::parse($last->SessionDate)->startOfDay(), $weekdays, $occupied);
        $times = CourseLeaveCascadeService::resolveContractSlotTimes($successor, $date);

        return $this->slot($date, $times['start'] ?: $last->StartTime, $times['end'] ?: $last->EndTime);
    }

    /** @return array{date: string, start: string, end: string} */
    private function slot(mixed $date, mixed $start, mixed $end): array
    {
        $hms = fn ($t) => strlen(substr((string) $t, 0, 8)) === 5 ? substr((string) $t, 0, 5) . ':00' : substr((string) $t, 0, 8);

        return ['date' => Carbon::parse((string) $date)->toDateString(), 'start' => $hms($start), 'end' => $hms($end)];
    }

    /**
     * Teacher and classroom load come from the canonical ScheduleGuardService (same student excluded); the
     * student check is the materializer's write-time guard (schedule-only rows included), minus the rows this
     * plan moves. Each blocker names the course and moving lesson (null for the new tail) so the UI can link to
     * the fix (Q9a); its message never repeats the guard's occupant names (#4210: other people's details stay private).
     *
     * @param  array{date: string, start: string, end: string}  $to
     * @param  list<int>  $movingIds
     * @return list<array<string, mixed>>
     */
    private function conflicts(StudentClass $course, int $branchId, array $to, array $movingIds, ?int $sessionId, bool $lock): array
    {
        $out = [];
        $at = ['course_id' => (int) $course->ID, 'session_id' => $sessionId, 'date' => $to['date'], 'start' => $to['start']];
        // apply() keeps date-bound schedule assignments in place; validate the written destination.
        $source = $to;
        foreach ($this->guard->validateCourseOccurrence(
            $course, $branchId, $to['date'], $to['start'], $to['end'], $lock,
            ['date' => $source['date'], 'start_time' => $source['start']]
        ) as $c) {
            $room = str_contains((string) ($c['type'] ?? ''), 'room');
            $out[] = ['type' => $room ? 'classroom' : 'teacher'] + $at
                + ['message' => $room ? '教室在此時段已有其他課程，請先核對課表' : '授課老師在此時段已有其他課程，請先核對課表'];
        }
        $studentClash = app(ClassSessionMaterializationService::class)
            ->findStudentSlotConflict($course, $to['date'], $to['start'], $to['end'], null, $movingIds, $lock) !== null;
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
