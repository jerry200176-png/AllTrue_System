<?php

namespace App\Services\Scheduling;

use App\Services\SubstituteScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Nightly census (#4991 item 7, family "ownership and identity"): for taught lessons of the last DAYS days that carry
 * a live evaluation record, does the one answer to "who teaches this occurrence"
 * (SubstituteScheduleService::teachersForOccurrences, read by pay, subject units and the calendar) name the teacher
 * who wrote the record? Founder rule (#4901): the record's author is the lesson's teacher. A disagreement is the
 * #499 / #488 / #307 shape. SELECT only, never repairs, counts only.
 * ponytail: one window, one LIMIT; the nightly row says `truncated` when the window holds more. Widen if that shows.
 */
final class ScheduleTeacherParityCensus
{
    public const DAYS = 35;
    public const LIMIT = 5000;

    /** @return array{mismatch_count:int, unevaluated:int, truncated:bool, checked:int} */
    public function run(): array
    {
        $today = CarbonImmutable::now('Asia/Taipei');
        $rows = DB::table('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereNull('lr.VoidedAt')
            ->whereColumn('lr.StudentClassID', 'cs.StudentClassID')
            ->where('lr.TeacherID', '>', 0)
            ->whereRaw("LOWER(TRIM(COALESCE(cs.Status, ''))) IN ('attended', 'completed', 'late')")
            ->whereDate('cs.SessionDate', '>=', $today->subDays(self::DAYS)->toDateString())
            ->whereDate('cs.SessionDate', '<=', $today->toDateString())
            ->orderBy('lr.id')
            ->limit(self::LIMIT + 1)
            ->get(['lr.TeacherID as record_teacher', 'cs.StudentClassID', 'cs.SessionDate', 'cs.StartTime', 'sc.TeacherID as contract_teacher']);
        $truncated = $rows->count() > self::LIMIT;
        $rows = $rows->take(self::LIMIT);

        $teachers = SubstituteScheduleService::teachersForOccurrences($rows->map(fn ($r) => [
            'course_id' => (int) $r->StudentClassID, 'date' => substr((string) $r->SessionDate, 0, 10),
            'start_time' => $r->StartTime, 'contract_teacher_id' => (int) $r->contract_teacher,
        ]));

        $out = ['mismatch_count' => 0, 'unevaluated' => 0, 'truncated' => $truncated, 'checked' => $rows->count()];
        foreach ($rows as $r) {
            $resolved = (int) ($teachers[SubstituteScheduleService::occurrenceKey((int) $r->StudentClassID, (string) $r->SessionDate, $r->StartTime)] ?? 0);
            if ($resolved <= 0) {
                $out['unevaluated']++; // no teacher on the contract or the occurrence: nothing to compare
            } elseif ($resolved !== (int) $r->record_teacher) {
                $out['mismatch_count']++;
            }
        }

        return $out;
    }
}
