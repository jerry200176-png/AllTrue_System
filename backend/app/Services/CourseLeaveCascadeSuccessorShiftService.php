<?php

namespace App\Services;

use App\Exceptions\SuccessorShiftRefused;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
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

    public function __construct(private ScheduleGuardService $guard, private LeaveAttendanceService $leaveAttendance)
    {
    }

    /** #4210 Q9: provenance remains active until the affected slot is restored explicitly. */
    public static function assertUndoAllowed(StudentClass $old, int $leaveSessionId): void
    {
        $marker = self::NOTE_SHIFT . ':ls=' . $leaveSessionId . ':from=';
        $rows = ClassSession::query()
            ->whereIn('StudentClassID', StudentClass::query()->select('ID')
                ->where('StudentID', (int) $old->StudentID)->where('SubjectID', (int) $old->SubjectID)
                ->where('ID', '!=', (int) $old->ID))
            ->where('Note', 'like', '%' . $marker . '%')->orderBy('id')->lockForUpdate()->get();
        $affected = [];
        foreach ($rows as $row) {
            if (!preg_match('/(?:^|;\s*)' . preg_quote($marker, '/') . '(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?=;|$)/', (string) $row->Note, $match)) {
                continue;
            }
            $date = Carbon::parse($row->SessionDate)->toDateString();
            $start = substr((string) $row->StartTime, 0, 5);
            if ($date === $match[1] && $start === $match[2]) {
                continue;
            }
            // Include past/consumed/cancelled rows too: undo must never rewrite their history.
            $affected[] = ['course_id' => (int) $row->StudentClassID, 'session_id' => (int) $row->id,
                'from' => ['date' => $match[1], 'start' => $match[2]],
                'to' => ['date' => $date, 'start' => $start]];
        }
        if ($affected !== []) {
            throw new SuccessorShiftRefused('此請假已順延續約堂次，順延仍有效，無法撤銷請假。請先查看受影響的續約堂次。', 422,
                ['affected_lessons' => $affected]);
        }
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
            CourseLeaveCascadeService::markSessionLeaveOnly($leave);
            /** @var StudentClass $old */ $old = StudentClass::query()->whereKey($plan['old_course_id'])->firstOrFail();
            $campusId = (int) (DB::table('Student')->where('id', (int) $old->StudentID)->value('CampusID') ?? 0);
            if (!StudentSignIn::query()->where('ClassSessionID', $leaveSessionId)->exists()) {
                $this->leaveAttendance->createClosedForSession($leave, $old, $actorUserId ?: null, null, $campusId ?: null);
            }

            // Last lesson first, so each row moves into a slot its successor has already left.
            foreach (array_reverse($plan['moves']) as $move) {
                if ($move['role'] !== 'successor') {
                    continue;
                }
                /** @var ClassSession $row */ $row = ClassSession::query()->whereKey((int) $move['session_id'])->firstOrFail();
                $row->SessionDate = $move['to']['date'];
                $row->StartTime = $move['to']['start'];
                $row->EndTime = $move['to']['end'];
                $row->Note = CourseLeaveCascadeService::appendNote($row->Note, self::NOTE_SHIFT . ':ls=' . $leaveSessionId . ':from=' . $move['from']['date'] . 'T' . substr($move['from']['start'], 0, 5));
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
                    . ':ls=' . $leaveSessionId . ':succ=' . $plan['successor_course_id'],
                '_revive_cancelled' => true,
            ]);
            if (!$created['created']) {
                throw new SuccessorShiftRefused('補堂時段已被其他堂次占用，未做任何變更', 409, $plan);
            }

            DB::table('StudentClass')->where('ID', $plan['old_course_id'])->update(['EndDate' => $plan['old_end_date']]);
            DB::table('StudentClass')->where('ID', $plan['successor_course_id'])->update(['EndDate' => $plan['successor_end_date']]);

            return $plan + ['applied' => true];
        });
    }

    /** @return array<string, mixed> */
    private function plan(int $leaveSessionId, bool $lock): array
    {
        // Lock order matches the ordinary leave path: course first, then its sessions.
        $courseId = (int) ClassSession::query()->whereKey($leaveSessionId)->value('StudentClassID');
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
        if ($this->hasApprovedEvaluation((int) $leave->id)) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $old->ID, 'session_id' => (int) $leave->id, 'message' => '請假堂次已有核准評量'];
        }
        if ((string) ($successor->scheduling_policy ?? 'auto_recurrence') !== 'auto_recurrence'
            || $successor->isUsageSettlementLocked() || $old->isUsageSettlementLocked()) {
            $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => null, 'message' => '續約課程為手動排課或已結算，無法自動順延'];
        }
        foreach ($chain as $row) {
            if ($reason = $this->ineligibleReason($row)) {
                $blockers[] = ['type' => 'ineligible', 'course_id' => (int) $successor->ID, 'session_id' => (int) $row->id, 'date' => Carbon::parse($row->SessionDate)->toDateString(), 'message' => $reason];
            }
        }

        $slots = $chain->map(fn ($s) => $this->slot($s->SessionDate, $s->StartTime, $s->EndTime))->values()->all();
        $minutes = max(30, Carbon::parse($leaveDate . ' ' . $leave->StartTime)->diffInMinutes(Carbon::parse($leaveDate . ' ' . $leave->EndTime)));
        $first = $slots[0];
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
        foreach ($moves as $move) {
            $course = $move['role'] === 'old_tail' ? $old : $successor;
            $blockers = array_merge($blockers, $this->conflicts($course, $branchId, $move['to'], $movingIds, $move['session_id']));
        }

        $oldDates = $oldSessions->filter(fn ($s) => strtolower((string) $s->Status) !== 'cancelled')
            ->map(fn ($s) => Carbon::parse($s->SessionDate)->toDateString())->push($moves[0]['to']['date'])->all();
        $plan = [
            'applicable' => true,
            'leave_session' => ['id' => (int) $leave->id, 'date' => $leaveDate, 'start' => substr((string) $leave->StartTime, 0, 5), 'end' => substr((string) $leave->EndTime, 0, 5)],
            'old_course_id' => (int) $old->ID,
            'successor_course_id' => (int) $successor->ID,
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
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
        $best = [null, collect()];
        foreach ($candidates as $candidate) {
            $chain = $this->sessions((int) $candidate->ID, $lock)
                ->filter(fn ($s) => strtolower((string) $s->Status) !== 'cancelled')->values();
            $start = $chain->isEmpty() ? null : Carbon::parse($chain->first()->SessionDate)->toDateString();
            if ($start !== null && $start > $leaveDate
                && ($best[0] === null || $start < Carbon::parse($best[1]->first()->SessionDate)->toDateString())) {
                $best = [$candidate, $chain];
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

    private function ineligibleReason(ClassSession $row): ?string
    {
        $id = (int) $row->id;

        return match (true) {
            strtolower((string) $row->Status) !== 'scheduled' => '續約堂次不是排定狀態（已上課、請假或其他）',
            Carbon::parse($row->SessionDate)->toDateString() < Carbon::today()->toDateString() => '續約堂次日期已過',
            StudentSignIn::query()->where('ClassSessionID', $id)->whereNull('VoidedAt')->exists() => '續約堂次已有出缺勤紀錄',
            SessionDeductionLedger::query()->where('class_session_id', $id)->exists() => '續約堂次已有扣堂紀錄',
            $this->hasApprovedEvaluation($id) => '續約堂次已有核准評量',
            default => null,
        };
    }

    private function hasApprovedEvaluation(int $sessionId): bool
    {
        return LearningRecord::query()->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->where('Status', 'approved')->exists();
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
     * student check sees every other live lesson of the student except the rows this plan moves.
     * Each blocker names the course and moving lesson (null for the new tail) so the UI can link to the fix (Q9a).
     *
     * @param  array{date: string, start: string, end: string}  $to
     * @param  list<int>  $movingIds
     * @return list<array<string, mixed>>
     */
    private function conflicts(StudentClass $course, int $branchId, array $to, array $movingIds, ?int $sessionId): array
    {
        $out = [];
        $at = ['course_id' => (int) $course->ID, 'session_id' => $sessionId, 'date' => $to['date'], 'start' => $to['start']];
        foreach ($this->guard->validateCourseOccurrence($course, $branchId, $to['date'], $to['start'], $to['end']) as $c) {
            $out[] = ['type' => str_contains((string) ($c['type'] ?? ''), 'room') ? 'classroom' : 'teacher'] + $at + ['message' => (string) ($c['message'] ?? '時段衝突')];
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
