<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for creating the `pending` LearningRecord that a teacher
 * fills for a past attended session.
 *
 * Background (#1078 / in-app #186/#188): the evaluation list only *lists* existing
 * LR rows; nothing creates them except the lazy `LearningRecordController@ensurePastRecords`
 * (triggered when a user opens the eval page). The nightly `learning-records:drift-check`
 * only fixes *existing* drift — it never creates missing rows. So attended sessions whose
 * LR was never lazily created (auto-/backfill-attended sessions, or branches whose eval page
 * was not opened) accumulate with a sign-in but no LR → the evaluation form is empty and the
 * teacher "cannot submit". A live audit found 1,274 such sessions across 6 campuses.
 *
 * This service is the shared creator used by both the interactive controller path and the
 * scheduled `learning-records:backfill-missing` job, so the rule "never duplicate logic" holds.
 *
 * 2026-07-20 (#1078 follow-up): digest `dq_attended_no_LR` and `bugs:verify-reproductions`
 * count only *active* LRs (`VoidedAt IS NULL`). A leave→attended (or status-adjust) cascade
 * can leave a voided LR on an attended session. `ensurePastRecords` already un-voided those,
 * but `createPendingForSession` treated any row as "exists" and skipped — so the nightly
 * backfill could never clear the enforced integrity metric. Unique(`ClassSessionID`) forbids
 * inserting a second row; restore in place.
 */
class LearningRecordBackfillService
{
    /** Session statuses that require a fillable (non-voided) LearningRecord. */
    private const FILLABLE_SESSION_STATUSES = ['attended', 'completed', 'late', 'absent'];

    /**
     * Ensure a fillable `pending` LearningRecord exists for one past session.
     * Returns true if a row was created or a voided row was restored.
     *
     * @param array<int,string> $subjectNameMap  SubjectID => Subject_Name
     */
    public function createPendingForSession(StudentClass $sc, ClassSession $cs, array $subjectNameMap): bool
    {
        $existing = LearningRecord::query()
            ->where('ClassSessionID', $cs->id)
            ->orderByDesc('id')
            ->first();

        if ($existing && !$existing->isVoided()) {
            return false;
        }

        if ($existing && $existing->isVoided()) {
            return $this->restoreVoidedForFillableSession($existing, $cs);
        }

        $scId = (int) $sc->getAttribute('ID');
        $subjectName = $subjectNameMap[(int) $sc->getAttribute('SubjectID')] ?? '未知';
        $tid = SubstituteScheduleService::effectiveInstructorUserId(
            $scId,
            $cs->SessionDate,
            (int) ($sc->TeacherID ?? 0),
            $cs->StartTime
        );

        LearningRecord::query()->create([
            'StudentClassID' => $scId,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $tid > 0 ? $tid : (int) ($sc->TeacherID ?? 0),
            'Content' => '',
            'Subject' => $subjectName,
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'Status' => 'pending',
        ]);

        return true;
    }

    /**
     * Restore a system-voided LR when the ClassSession is again fillable.
     * Mirrors LearningRecordController::ensurePastRecords un-void branch so interactive
     * and scheduled paths stay aligned. Does not insert (unique ClassSessionID).
     */
    private function restoreVoidedForFillableSession(LearningRecord $voided, ClassSession $cs): bool
    {
        $status = strtolower((string) ($cs->Status ?? ''));
        if (!in_array($status, self::FILLABLE_SESSION_STATUSES, true)) {
            return false;
        }

        $voided->fill([
            'VoidedAt' => null,
            'VoidedByUserID' => null,
            'VoidReason' => null,
            'Status' => 'pending',
            'SessionDate' => $cs->SessionDate ? substr((string) $cs->SessionDate, 0, 10) : null,
            'StartTime' => $cs->StartTime ? substr((string) $cs->StartTime, 0, 5) : null,
            'EndTime' => $cs->EndTime ? substr((string) $cs->EndTime, 0, 5) : null,
            'SessionDeducted' => false,
        ]);
        $voided->save();

        return true;
    }

    /**
     * Backfill every missing `pending` LR for past attended sessions at one campus.
     * Read-safe + idempotent: only ever *creates* or *restores* rows that should exist.
     * Returns the number of LR rows created or restored.
     */
    public function backfillBranch(int $branchId): int
    {
        $studentIds = Student::query()->where('CampusID', $branchId)->pluck('id');
        if ($studentIds->isEmpty()) {
            return 0;
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $subjectNameMap = DB::table('Subject')->pluck('Subject_Name', 'id')->all();
        $created = 0;

        StudentClass::query()->whereIn('StudentID', $studentIds)
            ->chunkById(200, function ($classes) use ($now, $subjectNameMap, &$created) {
                foreach ($classes as $sc) {
                    $courseStopped = (int) ($sc->Stop ?? 0) === 1;

                    ClassSession::query()->where('StudentClassID', $sc->ID)
                        ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                        ->whereRaw("CONCAT(SessionDate, ' ', COALESCE(StartTime, '00:00:00')) <= ?", [$now])
                        ->when($courseStopped, function ($query) {
                            $query->whereIn('Status', ['attended', 'late', 'absent']);
                        })
                        ->chunkById(500, function ($sessions) use ($sc, $subjectNameMap, &$created) {
                            foreach ($sessions as $cs) {
                                if ($this->createPendingForSession($sc, $cs, $subjectNameMap)) {
                                    $created++;
                                }
                            }
                        });
                }
            }, 'ID');

        return $created;
    }

    /**
     * Backfill all active campuses. Returns [branchId => createdCount].
     *
     * @return array<int,int>
     */
    public function backfillAllBranches(): array
    {
        $out = [];
        $branchIds = DB::table('Campus')->orderBy('id')->pluck('id');
        foreach ($branchIds as $bid) {
            $out[(int) $bid] = $this->backfillBranch((int) $bid);
        }

        return $out;
    }
}
