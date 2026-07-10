<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * #1062 Track A: forward session generation for active prepaid (count-mode) courses.
 *
 * Prepaid sessions are a liability owed to the customer; count-mode courses have no
 * forward-generation job, so active students' remaining sessions never materialise
 * ahead (stranded). This service plans (and, when executed, creates via the single
 * write authority {@see ClassSessionMaterializationService::upsertSlot}) the next
 * sessions for a course, derived from the course's OWN recent weekly cadence.
 *
 * Safety by design:
 *   - Scope is the ACTIVE stranded set only (recent attendance) — dormant courses are a
 *     business decision (resume/refund), never auto-generated (see #1152).
 *   - Cadence must be CONFIRMED (>=2 of the last sessions share weekday+time) or the
 *     course is SKIPPED and flagged — never guess a slot.
 *   - Generation is capped at min(RemainingSessions, horizonWeeks).
 *   - Idempotent: upsertSlot no-ops on an existing slot; the #957 unique index is the
 *     backstop. Planning is 100% read-only.
 */
class ForwardSessionGenerator
{
    public function __construct(private ClassSessionMaterializationService $materializer) {}

    /**
     * Build the generation plan for one course. Read-only.
     *
     * @return array{student_class_id:int,status:string,reason?:string,cadence?:array,slots:list<array{SessionDate:string,StartTime:string,EndTime:string}>}
     */
    public function planCourse(int $studentClassId, int $horizonWeeks = 4, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        $sc = DB::table('StudentClass')->where('ID', $studentClassId)->first();
        if (!$sc) {
            return ['student_class_id' => $studentClassId, 'status' => 'skip', 'reason' => 'course not found', 'slots' => []];
        }
        $remaining = (int) ($sc->RemainingSessions ?? 0);
        if ((int) ($sc->Stop ?? 0) === 1 || strtolower((string) $sc->ScheduleMode) !== 'count' || $remaining <= 0) {
            return ['student_class_id' => $studentClassId, 'status' => 'skip', 'reason' => 'not an active count-mode course with remaining sessions', 'slots' => []];
        }

        // Already has an upcoming session → not stranded.
        $hasUpcoming = DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->whereRaw('SessionDate >= ?', [$today->toDateString()])
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->exists();
        if ($hasUpcoming) {
            return ['student_class_id' => $studentClassId, 'status' => 'skip', 'reason' => 'already has an upcoming session', 'slots' => []];
        }

        // Derive cadence from the last up-to-6 non-cancelled sessions.
        $recent = DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->orderByDesc('SessionDate')->orderByDesc('id')
            ->limit(6)
            ->get(['SessionDate', 'StartTime', 'EndTime']);

        if ($recent->count() < 2) {
            return ['student_class_id' => $studentClassId, 'status' => 'skip', 'reason' => 'insufficient history to derive cadence', 'slots' => []];
        }

        $lastDate = Carbon::parse($recent->first()->SessionDate)->startOfDay();
        // Only the ACTIVE stranded set: last session within 3 weeks.
        if ($lastDate->lt($today->copy()->subWeeks(3))) {
            return ['student_class_id' => $studentClassId, 'status' => 'skip', 'reason' => 'dormant (no session in 3 weeks) — director decision, not auto-generation (#1152)', 'slots' => []];
        }

        $tally = [];
        foreach ($recent as $r) {
            $dow = Carbon::parse($r->SessionDate)->dayOfWeek; // 0=Sun..6=Sat
            $start = substr((string) $r->StartTime, 0, 5);
            $end = substr((string) $r->EndTime, 0, 5);
            $key = "$dow|$start|$end";
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }
        arsort($tally);
        $topKey = array_key_first($tally);
        $topCount = $tally[$topKey];
        if ($topCount < 2) {
            return ['student_class_id' => $studentClassId, 'status' => 'skip', 'reason' => 'no confirmed weekly cadence (sessions scattered)', 'slots' => []];
        }
        [$dow, $start, $end] = explode('|', $topKey);
        $dow = (int) $dow;

        // Generate forward weekly dates from the day after the anchor (last session or today, whichever later).
        $cursor = $lastDate->gt($today) ? $lastDate->copy() : $today->copy();
        $count = min($remaining, $horizonWeeks);
        $slots = [];
        $guard = 0;
        while (count($slots) < $count && $guard < 60) {
            $guard++;
            $cursor->addDay();
            if ($cursor->dayOfWeek !== $dow) {
                continue;
            }
            $dateStr = $cursor->toDateString();
            // Skip if the course already has a non-cancelled session in this slot.
            $occupied = DB::table('ClassSession')
                ->where('StudentClassID', $studentClassId)
                ->whereDate('SessionDate', $dateStr)
                ->whereRaw('SUBSTRING(StartTime,1,5) = ?', [$start])
                ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
                ->exists();
            if ($occupied) {
                continue;
            }
            $slots[] = ['SessionDate' => $dateStr, 'StartTime' => $start . ':00', 'EndTime' => $end . ':00'];
        }

        return [
            'student_class_id' => $studentClassId,
            'status' => $slots === [] ? 'skip' : 'plan',
            'reason' => $slots === [] ? 'no future slots needed within horizon' : '',
            'cadence' => ['dow' => $dow, 'start' => $start, 'end' => $end, 'confidence' => $topCount . '/' . $recent->count()],
            'slots' => $slots,
        ];
    }

    /**
     * Execute a plan via the single write authority. Returns created session ids.
     *
     * @param array{student_class_id:int,slots:list<array<string,string>>} $plan
     * @return list<int>
     */
    public function executePlan(array $plan): array
    {
        $created = [];
        foreach ($plan['slots'] as $slot) {
            $res = $this->materializer->upsertSlot([
                'StudentClassID' => $plan['student_class_id'],
                'SessionDate' => $slot['SessionDate'],
                'StartTime' => $slot['StartTime'],
                'EndTime' => $slot['EndTime'],
                'Status' => 'scheduled',
                'Note' => '系統向前生成 #1062',
            ]);
            if ($res['created']) {
                $created[] = (int) $res['session']->id;
            }
        }

        return $created;
    }
}
