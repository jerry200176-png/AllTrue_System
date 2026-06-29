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
 */
class LearningRecordBackfillService
{
    /**
     * Create the `pending` LearningRecord for one attended session, if it does not exist.
     * Returns true if a row was created. Mirrors the contract that CourseManagement and the
     * eval list expect (date/time copied from ClassSession; teacher resolved via substitute).
     *
     * @param array<int,string> $subjectNameMap  SubjectID => Subject_Name
     */
    public function createPendingForSession(StudentClass $sc, ClassSession $cs, array $subjectNameMap): bool
    {
        if (LearningRecord::where('ClassSessionID', $cs->id)->exists()) {
            return false;
        }

        $subjectName = $subjectNameMap[(int) $sc->SubjectID] ?? '未知';
        $tid = SubstituteScheduleService::effectiveInstructorUserId(
            (int) $sc->ID,
            $cs->SessionDate,
            (int) ($sc->TeacherID ?? 0),
            $cs->StartTime
        );

        LearningRecord::create([
            'StudentClassID' => $sc->ID,
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
     * Backfill every missing `pending` LR for past attended sessions at one campus.
     * Read-safe + idempotent: only ever *creates* rows that should already exist.
     * Returns the number of LR rows created.
     */
    public function backfillBranch(int $branchId): int
    {
        $studentIds = Student::where('CampusID', $branchId)->pluck('id');
        if ($studentIds->isEmpty()) {
            return 0;
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $subjectNameMap = DB::table('Subject')->pluck('Subject_Name', 'id')->all();
        $created = 0;

        StudentClass::whereIn('StudentID', $studentIds)
            ->chunkById(200, function ($classes) use ($now, $subjectNameMap, &$created) {
                foreach ($classes as $sc) {
                    $courseStopped = (int) ($sc->Stop ?? 0) === 1;

                    ClassSession::where('StudentClassID', $sc->ID)
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
