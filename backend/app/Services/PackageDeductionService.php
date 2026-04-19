<?php

namespace App\Services;

use App\Models\ClassSession;
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
     *
     * FR-003 — Group class deduplication:
     * In 一對三 / group packages, each member has its own ClassSession row per shared lesson.
     * Without dedup, one group lesson attended by 3 students would write 3 ledger -1 rows
     * (over-deduction), draining the shared pool at 3x the correct rate.
     * Strategy (ClassPass / Gympass "1 credit per group session" model):
     *   Before writing, check whether the package already has a -1 delta for the same
     *   (SessionDate, StartTime) slot. If yes, skip — one group session = 1 pool slot.
     *   Different timeslots on the same day (e.g. Math 16:00 + English 18:00) remain
     *   independent deductions (risk-3 guard).
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
            if (self::groupSessionAlreadyDeducted($packageId, $classSessionId)) {
                self::recomputeCounters($packageId);
                return;
            }
            self::deductForSession($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
        } elseif ($eventType === 'reverse') {
            self::reverseForSession($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
        }

        self::recomputeCounters($packageId);
    }

    /**
     * FR-003 — Returns true when this package already has a -1 ledger entry for a
     * ClassSession sharing the same (SessionDate, StartTime) slot as $classSessionId.
     * Only activated for group classes (class_type one_on_two / one_on_three / tutoring / one_on_many).
     * Returns false for 1:1 classes (preserving legacy per-member deduction behavior).
     */
    protected static function groupSessionAlreadyDeducted(int $packageId, ?int $classSessionId): bool
    {
        if (!$classSessionId || $classSessionId <= 0) {
            return false;
        }

        $pkgClassType = (string) DB::table('course_packages')
            ->where('id', $packageId)
            ->value('class_type');

        $groupTypes = ['one_on_two', 'one_on_three', 'one_on_many', 'tutoring'];
        if (!in_array($pkgClassType, $groupTypes, true)) {
            return false;
        }

        $cs = ClassSession::select('SessionDate', 'StartTime')->find($classSessionId);
        if (!$cs || empty($cs->SessionDate) || empty($cs->StartTime)) {
            return false;
        }

        return DB::table('package_session_ledger as l')
            ->join('ClassSession as c', 'c.id', '=', 'l.class_session_id')
            ->where('l.package_id', $packageId)
            ->where('l.delta', -1)
            ->where('c.SessionDate', $cs->SessionDate)
            ->where('c.StartTime', $cs->StartTime)
            ->exists();
    }

    /**
     * FR-004 — Rebuild the entire ledger from ClassSession (source of truth).
     *
     * Stripe-style data-repair playbook: the canonical state is replayed from the
     * unforgeable event source (attended ClassSessions) rather than patched in-place.
     *
     * Deletes every existing ledger row for the package, then inserts exactly one -1
     * delta per unique (SessionDate, StartTime) slot across all member StudentClasses
     * whose status is 'attended'. Group-class duplicates are collapsed at this step
     * so the rebuilt total equals the physical number of lessons taught.
     *
     * Idempotent: running rebuild twice produces identical ledger state.
     * Atomic: wrapped in a DB transaction; on failure, nothing persists.
     *
     * @return array{
     *   package_id: int,
     *   total_sessions: int,
     *   remaining_sessions: int,
     *   used_sessions: int,
     *   rebuilt_entries: int,
     *   deleted_entries: int,
     *   dry_run: bool,
     *   error?: string
     * }
     */
    public static function rebuildLedgerFromSessions(int $packageId, bool $dryRun = false): array
    {
        $pkg = CoursePackage::find($packageId);
        if (!$pkg) {
            return ['error' => 'Package not found', 'package_id' => $packageId];
        }

        $memberClassIds = StudentClass::where('PackageID', $packageId)
            ->pluck('ID')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rebuiltRows = [];
        if (!empty($memberClassIds)) {
            // Group attended sessions by (SessionDate, StartTime); pick the earliest id as
            // the representative class_session_id for the ledger row.
            $attended = ClassSession::whereIn('StudentClassID', $memberClassIds)
                ->where('Status', 'attended')
                ->orderBy('id')
                ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime']);

            $slotMap = [];
            foreach ($attended as $cs) {
                $date = (string) ($cs->SessionDate ?? '');
                $time = (string) ($cs->StartTime ?? '');
                if ($date === '' || $time === '') {
                    continue;
                }
                $key = $date . '|' . $time;
                if (!isset($slotMap[$key])) {
                    $slotMap[$key] = [
                        'class_session_id' => (int) $cs->id,
                        'student_class_id' => (int) $cs->StudentClassID,
                    ];
                }
            }
            $rebuiltRows = array_values($slotMap);
        }

        $existingCount = PackageSessionLedger::where('package_id', $packageId)->count();

        if ($dryRun) {
            $projectedNet = -1 * count($rebuiltRows);
            $projectedRemaining = max(0, (int) $pkg->total_sessions + $projectedNet);
            return [
                'package_id'         => (int) $pkg->id,
                'total_sessions'     => (int) $pkg->total_sessions,
                'remaining_sessions' => $projectedRemaining,
                'used_sessions'      => max(0, (int) $pkg->total_sessions - $projectedRemaining),
                'rebuilt_entries'    => count($rebuiltRows),
                'deleted_entries'    => $existingCount,
                'dry_run'            => true,
            ];
        }

        DB::transaction(function () use ($packageId, $rebuiltRows) {
            PackageSessionLedger::where('package_id', $packageId)->delete();

            foreach ($rebuiltRows as $row) {
                PackageSessionLedger::create([
                    'package_id'       => $packageId,
                    'student_class_id' => $row['student_class_id'],
                    'class_session_id' => $row['class_session_id'],
                    'delta'            => -1,
                    'reason'           => 'attended',
                    'recorded_by'      => null,
                    'note'             => 'rebuilt by PackageDeductionService::rebuildLedgerFromSessions',
                    'request_id'       => null,
                ]);
            }

            $locked = CoursePackage::lockForUpdate()->find($packageId);
            if ($locked) {
                $locked->recomputeCounters();
            }

            foreach (StudentClass::where('PackageID', $packageId)->get() as $sc) {
                $scNet = PackageSessionLedger::where('package_id', $packageId)
                    ->where('student_class_id', $sc->ID)
                    ->sum('delta');
                $sc->UsedSessions = max(0, abs((int) $scNet));
                $sc->RemainingSessions = max(0, (int) ($locked?->remaining_sessions ?? 0));
                $sc->save();
            }
        });

        $pkg->refresh();

        Log::info('PackageLedger rebuilt', [
            'package_id'         => $pkg->id,
            'total_sessions'     => $pkg->total_sessions,
            'remaining_sessions' => $pkg->remaining_sessions,
            'used_sessions'      => $pkg->used_sessions,
            'rebuilt_entries'    => count($rebuiltRows),
            'deleted_entries'    => $existingCount,
        ]);

        return [
            'package_id'         => (int) $pkg->id,
            'total_sessions'     => (int) $pkg->total_sessions,
            'remaining_sessions' => (int) $pkg->remaining_sessions,
            'used_sessions'      => (int) $pkg->used_sessions,
            'rebuilt_entries'    => count($rebuiltRows),
            'deleted_entries'    => $existingCount,
            'dry_run'            => false,
        ];
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
