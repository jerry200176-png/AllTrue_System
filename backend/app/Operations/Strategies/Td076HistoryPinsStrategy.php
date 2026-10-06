<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\OccurrenceAssignmentService;
use App\Services\Scheduling\ContractTeacherChangeCascade;
use Carbon\Carbon;
use RuntimeException;

/**
 * R-2 (design §5): a taught past occurrence with no exception row, whose D2 evidence teacher is not the contract
 * teacher, gets a `pin` row through the single writer so a later contract change cannot rewrite history.
 * Evidence sources that disagree (D2: do not guess) are quarantined and not pinned.
 */
final class Td076HistoryPinsStrategy extends Td076OccurrenceRepair
{
    protected function ref(): string
    {
        return 'repair-td076-r2-history-pins-20261006';
    }

    protected function event(): string
    {
        return 'pop.td076_r2_history_pins';
    }

    protected function build(int $campusId): array
    {
        $today = Carbon::today()->toDateString();
        $actions = $quarantine = [];
        // Chunked: the per-session checks below used to run for every taught session (~10 queries each) and a full
        // campus dry-run outlived the web request limit (HTTP 500). Evidence is read in bulk and only sessions that
        // can matter (a conflict, or a teacher other than the contract teacher) reach the per-session checks.
        StudentClass::query()->select('ID', 'TeacherID')
            ->whereIn('StudentID', fn ($q) => $q->select('id')->from('Student')->where('CampusID', $campusId))
            ->chunkById(200, function ($courses) use ($today, &$actions, &$quarantine) {
                $rows = [];
                foreach ($courses as $course) {
                    foreach (ContractTeacherChangeCascade::taughtPastSessions((int) $course->ID, $today) as $row) {
                        if (substr((string) $row->SessionDate, 0, 10) < $today) {
                            $rows[(int) $row->id] = (int) $course->ID;
                        }
                    }
                }
                $evidence = $this->evidence(array_keys($rows));
                $contract = $courses->pluck('TeacherID', 'ID');
                foreach ($evidence as $sessionId => $teachers) {
                    $courseId = $rows[$sessionId];
                    if (count($teachers) === 1 && $teachers[0] === (int) $contract[$courseId]) {
                        continue;
                    }
                    $session = ClassSession::query()->whereKey($sessionId)->first();
                    if (!$session || !ContractTeacherChangeCascade::isPinnableOccurrence($session)
                        || OccurrenceAssignmentService::onLeave($session) || $this->liveRow($session)->exists()) {
                        continue;
                    }
                    if (count($teachers) > 1) {
                        $quarantine[] = ['reason' => 'evidence_conflict', 'class_session_id' => $sessionId];
                    } else {
                        $actions[] = ['type' => 'pin', 'class_session_id' => $sessionId,
                            'student_class_id' => $courseId, 'teacher_id' => $teachers[0]];
                    }
                }
            }, 'ID');
        usort($actions, fn ($a, $b) => $a['class_session_id'] <=> $b['class_session_id']);
        usort($quarantine, fn ($a, $b) => $a['class_session_id'] <=> $b['class_session_id']);

        return ['actions' => $actions, 'quarantine' => $quarantine];
    }

    /** An exception (substitute/pin) row: a live non-makeup row that hangs off an anchor. */
    private function liveRow(ClassSession $session)
    {
        return Schedule::query()->where('student_course_id', $session->StudentClassID)->where('status', 'scheduled')
            ->whereDate('schedule_date', substr((string) $session->SessionDate, 0, 10))
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [substr((string) $session->StartTime, 0, 5)])
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '<>', 'extra'))->whereNotNull('original_schedule_id');
    }

    /**
     * Distinct teachers named by the non-voided learning records and sign-ins (LR, manual, RFID), keyed by session id.
     * Sessions with no evidence are absent.
     *
     * @param list<int> $sessionIds
     * @return array<int, list<int>>
     */
    private function evidence(array $sessionIds): array
    {
        $found = [];
        foreach (array_chunk($sessionIds, 1000) as $ids) {
            foreach ([LearningRecord::query(), StudentSignIn::query()] as $q) {
                foreach ($q->whereIn('ClassSessionID', $ids)->whereNull('VoidedAt')->get(['ClassSessionID', 'TeacherID']) as $r) {
                    if ((int) $r->TeacherID) {
                        $found[(int) $r->ClassSessionID][(int) $r->TeacherID] = true;
                    }
                }
            }
        }

        foreach ($found as &$teachers) {
            ksort($teachers);
            $teachers = array_keys($teachers);
        }

        return $found;
    }

    protected function apply(array $actions): array
    {
        $writer = app(OccurrenceAssignmentService::class);
        $entries = [];
        foreach ($actions as $a) {
            $session = ClassSession::query()->whereKey($a['class_session_id'])->first()
                ?? throw new RuntimeException('td076_r2_missing_session_' . $a['class_session_id']);
            if ($this->liveRow($session)->exists()) {
                throw new RuntimeException('td076_r2_drift_' . $a['class_session_id']);
            }
            $hadAnchor = Schedule::query()->where('student_course_id', $session->StudentClassID)->where('status', 'rescheduled')
                ->whereDate('schedule_date', substr((string) $session->SessionDate, 0, 10))
                ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [substr((string) $session->StartTime, 0, 5)])->exists();
            $pin = $writer->pinTaughtTeacher($session, $a['teacher_id'], null, 'pin')
                ?? throw new RuntimeException('td076_r2_not_pinnable_' . $a['class_session_id']);
            $entries[] = ['id' => (int) $pin->id, 'teacher_id' => $a['teacher_id'], 'anchor_id' => $hadAnchor ? null : (int) $pin->original_schedule_id];
        }

        return $entries;
    }

    protected function undo(array $entry): bool
    {
        $row = Schedule::query()->whereKey($entry['id'])->lockForUpdate()->first();
        if (!$row || $row->status !== 'scheduled' || (int) $row->teacher_id !== $entry['teacher_id']
            || ScheduleChangeLog::query()->where('schedule_id', $row->id)->where('reason', '<>', 'pin')->exists()) {
            return false; // changed since the pin (substitute, restore, ...): leave it alone
        }
        ScheduleChangeLog::query()->where('schedule_id', $row->id)->where('reason', 'pin')->delete();
        $row->delete();
        $anchor = $entry['anchor_id'] ? Schedule::query()->whereKey($entry['anchor_id'])->first() : null;
        if ($anchor && $anchor->status === 'rescheduled' && !Schedule::query()->where('original_schedule_id', $anchor->id)->exists()) {
            $anchor->delete();
        }

        return true;
    }
}
