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
     *
     * Idempotency is net-based, like SessionDeductionService (#1338): a session is debited
     * only while its package net is not already negative, so undo + re-attend debits again
     * (#4275). Each cycle gets its own reason code (attendance, attendance#2, ...) to satisfy
     * the unique key (package_id, class_session_id, reason) without a schema change.
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
        if (!$classSessionId || $classSessionId <= 0) {
            return self::writeLedgerRow($packageId, $studentClassId, null, -1, $reason, $recordedBy, $note, $requestId);
        }

        return (bool) DB::transaction(function () use ($packageId, $studentClassId, $classSessionId, $reason, $recordedBy, $note, $requestId) {
            self::lockPackage($packageId);
            if (self::sessionNet($packageId, $classSessionId) < 0) {
                return false;
            }

            return self::writeLedgerRow(
                $packageId, $studentClassId, $classSessionId, -1,
                self::cycleReason($packageId, $classSessionId, $reason), $recordedBy, $note, $requestId
            );
        });
    }

    /**
     * Record a reverse (+1) in the package ledger.
     * Net-based: only a session whose package net is negative (debited) is credited back.
     *
     * @return bool true if a new row was written
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
        if (!$classSessionId || $classSessionId <= 0) {
            return self::writeLedgerRow($packageId, $studentClassId, null, 1, $reason, $recordedBy, $note, $requestId);
        }

        return (bool) DB::transaction(function () use ($packageId, $studentClassId, $classSessionId, $reason, $recordedBy, $note, $requestId) {
            self::lockPackage($packageId);
            if (self::sessionNet($packageId, $classSessionId) >= 0) {
                return false;
            }

            return self::writeLedgerRow(
                $packageId, $studentClassId, $classSessionId, 1,
                self::cycleReason($packageId, $classSessionId, $reason), $recordedBy, $note, $requestId
            );
        });
    }

    private static function writeLedgerRow(
        int $packageId,
        int $studentClassId,
        ?int $classSessionId,
        int $delta,
        string $reason,
        ?int $recordedBy,
        ?string $note,
        ?string $requestId
    ): bool {
        try {
            PackageSessionLedger::create([
                'package_id'       => $packageId,
                'student_class_id' => $studentClassId,
                'class_session_id' => $classSessionId,
                'delta'            => $delta,
                'reason'           => $reason,
                'recorded_by'      => $recordedBy,
                'note'             => $note,
                'request_id'       => $requestId,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint')) {
                // Per-cycle reasons make a duplicate unexpected: a concurrent write saw the same net. Leave a trace.
                Log::warning('PackageLedger duplicate write skipped', [
                    'package_id' => $packageId, 'class_session_id' => $classSessionId, 'delta' => $delta, 'reason' => $reason,
                ]);
                return false;
            }
            throw $e;
        }

        return true;
    }

    /**
     * Serialize package ledger writers. Under REPEATABLE READ a caller whose outer transaction already read before
     * this lock still sees that older snapshot in the plain net reads below (as the course ledger does); see #4454.
     */
    private static function lockPackage(int $packageId): void
    {
        CoursePackage::query()->whereKey($packageId)->lockForUpdate()->first();
    }

    /** Package ledger net for one session: negative = debited. */
    private static function sessionNet(int $packageId, int $classSessionId): int
    {
        return (int) PackageSessionLedger::where('package_id', $packageId)
            ->where('class_session_id', $classSessionId)
            ->sum('delta');
    }

    /** First unused reason for this session: $reason, then $reason#2, $reason#3, ... */
    private static function cycleReason(int $packageId, int $classSessionId, string $reason): string
    {
        $used = array_flip(PackageSessionLedger::where('package_id', $packageId)
            ->where('class_session_id', $classSessionId)
            ->pluck('reason')
            ->map(fn ($r) => (string) $r)
            ->all());
        $candidate = $reason;
        for ($n = 2; isset($used[$candidate]); $n++) {
            $suffix = '#' . $n;
            $candidate = substr($reason, 0, 64 - strlen($suffix)) . $suffix;
        }

        return $candidate;
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
     *   A (SessionDate, StartTime) slot is debited once. Deduct skips when the slot's package net
     *   is already negative (net, so a slot credited back can be debited again, #4275); a reverse
     *   credits only a session that carries a debit. Reverting the carrier while another member is
     *   still charged leaves the slot credited, as before; moving the debit needs current reads
     *   (#4454). Different timeslots on the same day (e.g. Math 16:00 + English 18:00) remain
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

        DB::transaction(function () use ($packageId, $studentClassId, $classSessionId, $eventType, $source, $createdBy, $note) {
            self::lockPackage($packageId);
            if ($eventType === 'deduct') {
                $slot = self::groupSlot($packageId, $classSessionId);
                if ($slot === null || self::slotNet($packageId, $slot) >= 0) {
                    self::deductForSession($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
                }
            } elseif ($eventType === 'reverse') {
                self::reverseForSession($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
            }

            self::recomputeCounters($packageId);
        });
    }

    /**
     * FR-003 — The (SessionDate, StartTime) slot of $classSessionId when the package is a
     * group class (one_on_two / one_on_three / one_on_many / tutoring); null for 1:1 packages
     * (per-member deduction) or when the session has no slot.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function groupSlot(int $packageId, ?int $classSessionId): ?array
    {
        if (!$classSessionId || $classSessionId <= 0) {
            return null;
        }

        $pkgClassType = (string) DB::table('course_packages')
            ->where('id', $packageId)
            ->value('class_type');

        $groupTypes = ['one_on_two', 'one_on_three', 'one_on_many', 'tutoring'];
        if (!in_array($pkgClassType, $groupTypes, true)) {
            return null;
        }

        $cs = ClassSession::select('SessionDate', 'StartTime')->find($classSessionId);
        if (!$cs || empty($cs->SessionDate) || empty($cs->StartTime)) {
            return null;
        }

        return [(string) $cs->SessionDate, (string) $cs->StartTime];
    }

    /**
     * Package ledger net of every session in the slot: negative = the slot is debited.
     *
     * @param array{0: string, 1: string} $slot
     */
    private static function slotNet(int $packageId, array $slot): int
    {
        return (int) DB::table('package_session_ledger as l')
            ->join('ClassSession as c', 'c.id', '=', 'l.class_session_id')
            ->where('l.package_id', $packageId)
            ->where('c.SessionDate', $slot[0])
            ->where('c.StartTime', $slot[1])
            ->sum('l.delta');
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
