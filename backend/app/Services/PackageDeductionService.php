<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\StudentClass;
use Illuminate\Support\Collection;
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
     * Serialize package ledger writers. The ledger reads after it go through currentLedger(), because under
     * REPEATABLE READ a caller whose outer transaction already read before this lock keeps that older snapshot (#4454).
     */
    private static function lockPackage(int $packageId): void
    {
        CoursePackage::query()->whereKey($packageId)->lockForUpdate()->first();
    }

    /**
     * The package's ledger rows as last committed, plus this transaction's own writes: a locking read, which InnoDB
     * answers from the latest rows instead of the caller's snapshot (#4454). Filtered on package_id alone, so the shared
     * locks cover this package's index range only, never a class_session_id range that other packages' lessons share.
     * Callers hold the package lock, so no other writer of these rows is in flight.
     */
    private static function currentLedger(int $packageId): Collection
    {
        return DB::table('package_session_ledger')->where('package_id', $packageId)->sharedLock()
            ->get(['class_session_id', 'delta', 'reason']);
    }

    /** Package ledger net for one session: negative = debited. */
    private static function sessionNet(int $packageId, int $classSessionId): int
    {
        return (int) self::currentLedger($packageId)->where('class_session_id', $classSessionId)->sum('delta');
    }

    /** First unused reason for this session: $reason, then $reason#2, $reason#3, ... */
    private static function cycleReason(int $packageId, int $classSessionId, string $reason): string
    {
        $used = array_flip(self::currentLedger($packageId)->where('class_session_id', $classSessionId)
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

            $pkg->recomputeCounters(true); // current ledger read, as above (#4454)
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
     *   A (SessionDate, HH:MM) slot is debited once (deductUnderSlotRule). Deduct skips when the
     *   slot's package net is already negative (net, so a slot credited back can be debited again,
     *   #4275); a reverse credits only a session that carries a debit. Reverting the carrier while
     *   another member is still charged leaves the slot credited, as before (moving the debit to that
     *   member is still open, #4454). Different timeslots on the same day (e.g. Math 16:00 + English
     *   18:00) remain independent deductions (risk-3 guard).
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
                self::deductUnderSlotRule($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
            } elseif ($eventType === 'reverse') {
                self::reverseForSession($packageId, $studentClassId, $classSessionId, $source, $createdBy, $note);
            }

            self::recomputeCounters($packageId);
        });
    }

    /**
     * FR-003 — Debit one charged lesson under the slot rule: a group package debits each (SessionDate, HH:MM) slot
     * once, so the lesson is skipped while its slot's package net is already negative; other packages debit per lesson.
     * Attendance (via syncFromStudentClassDeduction) and package creation with past dates both debit through here, so
     * neither can debit a slot twice (#4455); reslotRetimedSession() applies the same rule after a time change.
     *
     * @return bool true if a new row was written
     */
    public static function deductUnderSlotRule(
        int $packageId,
        int $studentClassId,
        ?int $classSessionId,
        string $reason = 'attendance',
        ?int $recordedBy = null,
        ?string $note = null,
        ?string $requestId = null
    ): bool {
        return (bool) DB::transaction(function () use ($packageId, $studentClassId, $classSessionId, $reason, $recordedBy, $note, $requestId) {
            self::lockPackage($packageId);
            $slot = self::groupSlot($packageId, $classSessionId);
            if ($slot !== null && self::slotNet($packageId, $slot) < 0) {
                return false;
            }

            return self::deductForSession($packageId, $studentClassId, $classSessionId, $reason, $recordedBy, $note, $requestId);
        });
    }

    /**
     * FR-003 after a lesson's start time changed on the same date (#4455). Slots come from the lessons' current times, so a
     * debit the lesson carries has already left $oldStartHm and counts at its new time. Both slots then get the rule again:
     * - carried: the old slot keeps its one debit on another member lesson still charged there (chargedLessons()), and
     *   the carried debit is credited if the new slot is already debited;
     * - not carried: a lesson charged before this edit ($statusBefore attended/late, or a course-ledger debit) debits the
     *   new slot if that slot has no debit, so moving a lesson back restores it. A lesson marked attended in the same
     *   request is debited by the attendance deduction that follows instead.
     * Idempotent; 1:1 packages are untouched. The caller runs it in the transaction of the time edit after lockForRetime(),
     * so its reads see every member write committed before the package lock, and later member writes apply their own
     * slot rule after it.
     *
     * @return bool true if a ledger row was written
     */
    public static function reslotRetimedSession(int $classSessionId, string $oldStartHm, string $statusBefore, ?int $recordedBy = null): bool
    {
        $lesson = DB::table('ClassSession as c')->join('StudentClass as sc', 'sc.ID', '=', 'c.StudentClassID')
            ->where('c.id', $classSessionId)->first(['sc.ID as course_id', 'sc.PackageID as package_id']);
        $packageId = (int) ($lesson->package_id ?? 0);
        if ($packageId <= 0) {
            return false;
        }
        $courseId = (int) $lesson->course_id;

        return (bool) DB::transaction(function () use ($packageId, $courseId, $classSessionId, $oldStartHm, $statusBefore, $recordedBy) {
            self::lockPackage($packageId);
            $slot = self::groupSlot($packageId, $classSessionId);
            if ($slot === null || $slot[1] === $oldStartHm) {
                return false;
            }
            $note = '改時段：同一時段只扣一堂（FR-003）';
            $wrote = false;
            if (self::sessionNet($packageId, $classSessionId) < 0) {
                $oldSlot = [$slot[0], $oldStartHm];
                $heir = self::slotNet($packageId, $oldSlot) >= 0 ? self::chargedLessons($packageId, $oldSlot)->first() : null;
                if ($heir !== null) {
                    $wrote = self::deductForSession($packageId, (int) $heir->StudentClassID, (int) $heir->id, 'retime', $recordedBy, $note);
                }
                if (self::slotNet($packageId, $slot, $classSessionId) < 0) {
                    $wrote = self::reverseForSession($packageId, $courseId, $classSessionId, 'retime', $recordedBy, $note) || $wrote;
                }
            } elseif (self::slotNet($packageId, $slot, $classSessionId) >= 0) {
                $self = self::chargedLessons($packageId, $slot)->firstWhere('id', $classSessionId);
                if ($self !== null && (in_array(strtolower($statusBefore), ['attended', 'late'], true) || (int) $self->course_net > 0)) {
                    $wrote = self::deductForSession($packageId, $courseId, $classSessionId, 'retime', $recordedBy, $note);
                }
            }
            if ($wrote) {
                self::recomputeCounters($packageId);
            }

            return $wrote;
        });
    }

    /**
     * Before a lesson's start time changes: lock the lesson, its course and then its package (the attendance order), with
     * locking reads only. Called before the transaction's first plain read, so the snapshot those reads take is newer than
     * the package lock (#4454) and the time edit commits together with reslotRetimedSession().
     */
    public static function lockForRetime(int $classSessionId): void
    {
        $packageId = (int) DB::table('ClassSession as c')->join('StudentClass as sc', 'sc.ID', '=', 'c.StudentClassID')
            ->where('c.id', $classSessionId)->lockForUpdate()->value('sc.PackageID');
        if ($packageId > 0) {
            self::lockPackage($packageId);
        }
    }

    /**
     * The package's member lessons charged in a slot, oldest first: attended / late, or completed with a course-ledger debit
     * (a learning-record approval deducts a completed lesson; whether an uncharged completed lesson debits is not decided).
     * Read plainly, never locked.
     *
     * @param array{0: string, 1: string} $slot
     */
    private static function chargedLessons(int $packageId, array $slot): Collection
    {
        $courseNet = "(SELECT COALESCE(SUM(CASE WHEN l.event_type = 'deduct' THEN 1 WHEN l.event_type = 'reverse' THEN -1 ELSE 0 END), 0)"
            . ' FROM session_deduction_ledger l WHERE l.student_class_id = c.StudentClassID AND l.class_session_id = c.id)';

        return DB::table('ClassSession as c')->join('StudentClass as sc', 'sc.ID', '=', 'c.StudentClassID')
            ->where('sc.PackageID', $packageId)->where('c.SessionDate', $slot[0])->where('c.StartTimeHM', $slot[1])
            ->whereIn('c.Status', ['attended', 'late', 'completed'])->orderBy('c.id')
            ->get(['c.id', 'c.StudentClassID', 'c.Status', DB::raw("{$courseNet} AS course_net")])
            ->filter(fn ($l) => strtolower((string) $l->Status) !== 'completed' || (int) $l->course_net > 0)->values();
    }

    /**
     * FR-003 — The (SessionDate, HH:MM) slot of $classSessionId when the package is a
     * group class (one_on_two / one_on_three / one_on_many / tutoring); null for 1:1 packages
     * (per-member deduction) or when the session has no slot. HH:MM (the StartTimeHM column),
     * because swipe and self-study lessons store seconds in StartTime.
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

        $cs = DB::table('ClassSession')->where('id', $classSessionId)->first(['SessionDate', 'StartTimeHM']);
        if (!$cs || empty($cs->SessionDate) || empty($cs->StartTimeHM)) {
            return null;
        }

        return [(string) $cs->SessionDate, (string) $cs->StartTimeHM];
    }

    /**
     * Package ledger net of every session in the slot, optionally leaving one session out: negative = the slot is
     * debited. Ledger rows are read current; ClassSession rows plainly and never locked, because attendance writers
     * hold them while they wait for the package lock (#4454).
     *
     * @param array{0: string, 1: string} $slot
     */
    private static function slotNet(int $packageId, array $slot, ?int $exceptSessionId = null): int
    {
        $ledger = self::currentLedger($packageId)->whereNotNull('class_session_id');
        $ids = $ledger->pluck('class_session_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return 0;
        }
        $inSlot = array_flip(DB::table('ClassSession')->whereIn('id', $ids)
            ->where('SessionDate', $slot[0])->where('StartTimeHM', $slot[1])
            ->pluck('id')->map(fn ($id) => (int) $id)->all());
        if ($exceptSessionId !== null) {
            unset($inSlot[$exceptSessionId]);
        }

        return (int) $ledger->filter(fn ($row) => isset($inSlot[(int) $row->class_session_id]))->sum('delta');
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
