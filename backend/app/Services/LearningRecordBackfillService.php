<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Support\AttendanceStatus;
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
    /**
     * ClassSession statuses that represent a student actually taking part in a
     * lesson and therefore require a LearningRecord.
     *
     * Keep this derived from AttendanceStatus. In particular, `absent` and
     * every leave/cancelled status must not create an assessment form.
     */
    private static function requiredSessionStatuses(): array
    {
        return AttendanceStatus::requiresLogSessionStatuses();
    }

    /**
     * Ensure a fillable `pending` LearningRecord exists for one past session.
     * Returns true if a row was created or a voided row was restored.
     *
     * @param array<int,string> $subjectNameMap  SubjectID => Subject_Name
     */
    public function createPendingForSession(StudentClass $sc, ClassSession $cs, array $subjectNameMap): bool
    {
        if (!in_array(strtolower((string) ($cs->Status ?? '')), self::requiredSessionStatuses(), true)) {
            return false;
        }

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

        try {
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
        } catch (\Illuminate\Database\QueryException $e) {
            // A concurrent attendance writer may have created the unique
            // ClassSessionID row after the read above. The other transaction
            // owns the desired result, so keep this request idempotent.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Ensure the assessment form exists immediately after an attendance event.
     *
     * This is intentionally idempotent so manual attendance, RFID, and a
     * scheduled repair can all use the same rule without racing into a second
     * LearningRecord (ClassSessionID is unique).
     */
    public function ensureForAttendanceSession(ClassSession $cs): bool
    {
        $sc = StudentClass::query()->where('ID', (int) $cs->StudentClassID)->first();
        if (!$sc) {
            return false;
        }

        $subjectName = DB::table('Subject')
            ->where('id', (int) ($sc->SubjectID ?? 0))
            ->value('Subject_Name');
        if (!$subjectName) {
            $subjectName = DB::table('BaseData')
                ->where('Name', '課程')
                ->where('id', (int) ($sc->SubjectID ?? 0))
                ->value('Val');
        }

        return $this->createPendingForSession($sc, $cs, [
            (int) ($sc->SubjectID ?? 0) => $subjectName ?: '未知',
        ]);
    }

    /**
     * Restore a system-voided LR when the ClassSession is again fillable.
     * Mirrors LearningRecordController::ensurePastRecords un-void branch so interactive
     * and scheduled paths stay aligned. Does not insert (unique ClassSessionID).
     */
    private function restoreVoidedForFillableSession(LearningRecord $voided, ClassSession $cs): bool
    {
        $status = strtolower((string) ($cs->Status ?? ''));
        if (!in_array($status, self::requiredSessionStatuses(), true)) {
            return false;
        }
        if (!LearningRecordResurrectionPolicy::isEligibleForResurrect(
            $voided->getAttribute('VoidReason'),
            $status
        )) {
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
                        ->whereIn('Status', self::requiredSessionStatuses())
                        ->whereRaw("CONCAT(SessionDate, ' ', COALESCE(StartTime, '00:00:00')) <= ?", [$now])
                        ->when($courseStopped, function ($query) {
                            $query->whereIn('Status', self::requiredSessionStatuses());
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
     * Backfill every campus that actually has students. Returns [branchId => createdCount].
     *
     * Enumerates distinct Student.CampusID rather than the `Campus` table: a student whose
     * CampusID has no matching Campus row would otherwise be skipped forever, leaving its
     * attended sessions permanently without a LearningRecord (regression behind the nightly
     * bugs:verify-reproductions failures on past_attended_sessions_without_learning_record).
     *
     * @return array<int,int>
     */
    public function backfillAllBranches(): array
    {
        $out = [];
        $branchIds = Student::query()
            ->whereNotNull('CampusID')
            ->distinct()
            ->orderBy('CampusID')
            ->pluck('CampusID');
        foreach ($branchIds as $bid) {
            $out[(int) $bid] = $this->backfillBranch((int) $bid);
        }

        return $out;
    }
}
