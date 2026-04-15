<?php

namespace App\Services;

use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\StudentClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackageDeductionService
{
    /**
     * Record a deduction (-1) in the package ledger.
     * Idempotent: unique key (package_id, class_session_id, reason) prevents duplicates.
     *
     * @return bool true if a new row was written
     */
    public static function deductForSession(
        int $packageId,
        int $studentClassId,
        ?int $classSessionId,
        string $reason = 'attendance',
        ?int $recordedBy = null,
        ?string $note = null,
        ?string $requestId = null
    ): bool {
        if ($classSessionId && $classSessionId > 0) {
            $exists = PackageSessionLedger::where('package_id', $packageId)
                ->where('class_session_id', $classSessionId)
                ->where('reason', $reason)
                ->where('delta', -1)
                ->exists();
            if ($exists) {
                return false;
            }
        }

        try {
            PackageSessionLedger::create([
                'package_id'       => $packageId,
                'student_class_id' => $studentClassId,
                'class_session_id' => $classSessionId ?: null,
                'delta'            => -1,
                'reason'           => $reason,
                'recorded_by'      => $recordedBy,
                'note'             => $note,
                'request_id'       => $requestId,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Record a reverse (+1) in the package ledger.
     * Idempotent via unique key.
     */
    public static function reverseForSession(
        int $packageId,
        int $studentClassId,
        ?int $classSessionId,
        string $reason = 'retro_leave',
        ?int $recordedBy = null,
        ?string $note = null,
        ?string $requestId = null
    ): bool {
        if ($classSessionId && $classSessionId > 0) {
            $exists = PackageSessionLedger::where('package_id', $packageId)
                ->where('class_session_id', $classSessionId)
                ->where('reason', $reason)
                ->where('delta', 1)
                ->exists();
            if ($exists) {
                return false;
            }
        }

        try {
            PackageSessionLedger::create([
                'package_id'       => $packageId,
                'student_class_id' => $studentClassId,
                'class_session_id' => $classSessionId ?: null,
                'delta'            => 1,
                'reason'           => $reason,
                'recorded_by'      => $recordedBy,
                'note'             => $note,
                'request_id'       => $requestId,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Recompute and persist the package counters from ledger.
     */
    public static function recomputeCounters(int $packageId): void
    {
        DB::transaction(function () use ($packageId) {
            $pkg = CoursePackage::lockForUpdate()->find($packageId);
            if (!$pkg) {
                return;
            }

            $pkg->recomputeCounters();
        });
    }

    /**
     * Called by SessionDeductionService hooks: if the StudentClass belongs to
     * a package, also write a package ledger entry and recompute.
     */
    public static function syncFromStudentClassDeduction(
        int $studentClassId,
        ?int $classSessionId,
        string $eventType,
        string $source,
        ?int $createdBy = null,
        ?string $note = null
    ): void {
        $sc = StudentClass::where('ID', $studentClassId)->first();
        if (!$sc || !$sc->isPartOfPackage()) {
            return;
        }

        $packageId = (int) $sc->PackageID;
        if ($packageId <= 0) {
            return;
        }

        if ($eventType === 'deduct') {
            self::deductForSession($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
        } elseif ($eventType === 'reverse') {
            self::reverseForSession($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
        }

        self::recomputeCounters($packageId);
    }

    /**
     * Full recompute: recalculate from all ledger rows (for repair).
     * Also refreshes each member StudentClass.RemainingSessions for display compatibility.
     */
    public static function fullRecompute(int $packageId): array
    {
        $pkg = CoursePackage::find($packageId);
        if (!$pkg) {
            return ['error' => 'Package not found'];
        }

        DB::transaction(function () use ($pkg) {
            $pkg = CoursePackage::lockForUpdate()->find($pkg->id);
            $pkg->recomputeCounters();

            $memberClasses = StudentClass::where('PackageID', $pkg->id)->get();
            foreach ($memberClasses as $sc) {
                $scNet = PackageSessionLedger::where('package_id', $pkg->id)
                    ->where('student_class_id', $sc->ID)
                    ->sum('delta');
                $scUsed = max(0, abs((int) $scNet));

                $sc->UsedSessions = $scUsed;
                $sc->RemainingSessions = max(0, $pkg->remaining_sessions);
                $sc->save();
            }
        });

        $pkg->refresh();

        return [
            'package_id'         => $pkg->id,
            'total_sessions'     => $pkg->total_sessions,
            'remaining_sessions' => $pkg->remaining_sessions,
            'used_sessions'      => $pkg->used_sessions,
        ];
    }
}
