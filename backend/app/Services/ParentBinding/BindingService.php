<?php

namespace App\Services\ParentBinding;

use App\Models\Guardian;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Campus;
use App\Support\ParentBinding\ParentBindingCodes as C;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * W31 P1-1: Central parent-binding lifecycle service.
 *
 * Manages create / query / update / delete of student_line_bindings,
 * cross-campus access rules, and orphan detection.
 */
final class BindingService
{
    public function __construct(
        private readonly ParentBindingClassifier $classifier,
        private readonly ParentBindingObservability $observability,
        private readonly GuardianSyncService $guardianSync,
        private readonly ParentGuardianAccessService $guardianAccess,
    ) {}

    // ── Query ──────────────────────────────────────────────

    /** @return Collection<int, \stdClass> */
    public function listBindings(?int $campusId = null, ?int $studentId = null, ?string $lineUserId = null, int $limit = 100, ?array $allowedCampusIds = null, ?string $studentName = null): Collection
    {
        $q = DB::table('student_line_bindings')
            ->join('Student', 'student_line_bindings.student_id', '=', 'Student.id')
            ->leftJoin('Campus', 'student_line_bindings.campus_id', '=', 'Campus.id')
            ->select([
                'student_line_bindings.id',
                'student_line_bindings.student_id',
                'student_line_bindings.line_user_id',
                'student_line_bindings.campus_id',
                'student_line_bindings.bound_at',
                'student_line_bindings.verified_at',
                'Student.name as student_name',
                'Student.enable as student_enable',
                'Campus.name as campus_name',
            ]);

        if ($campusId !== null) {
            $q->where('student_line_bindings.campus_id', $campusId);
        }
        if ($allowedCampusIds !== null) {
            $q->whereIn('student_line_bindings.campus_id', $allowedCampusIds);
        }
        if ($studentId !== null) {
            $q->where('student_line_bindings.student_id', $studentId);
        }
        if ($lineUserId !== null) {
            $q->where('student_line_bindings.line_user_id', $lineUserId);
        }
        if ($studentName !== null && trim($studentName) !== '') {
            $q->where('Student.name', 'like', '%' . addcslashes(trim($studentName), '%_\\') . '%');
        }

        return $q->orderBy('student_line_bindings.bound_at', 'desc')->limit($limit)->get();
    }

    /**
     * The binding page's list (in-app #400): paged, filtered by student name and
     * verification status, and with the LINE user ID masked (never sent raw).
     *
     * @return array{data: list<\stdClass>, current_page: int, last_page: int, total: int}
     */
    public function pageBindings(?int $campusId, ?array $allowedCampusIds, ?string $studentName, ?string $status, int $page, int $perPage, ?int $studentId = null, ?string $lineUserId = null): array
    {
        // A director sees a binding only when both its campus_id and the student's current
        // campus are in scope: the same pair destroy() authorizes and ParentLinePush sends to.
        // A transferred student's stale binding is left to super admins (null scope).
        $q = DB::table('student_line_bindings')
            ->join('Student', 'student_line_bindings.student_id', '=', 'Student.id')
            ->leftJoin('Campus', 'Student.CampusID', '=', 'Campus.id')
            ->select([
                'student_line_bindings.id',
                'student_line_bindings.student_id',
                'student_line_bindings.line_user_id',
                'Student.CampusID as campus_id',
                'student_line_bindings.bound_at',
                'student_line_bindings.verified_at',
                'Student.name as student_name',
                'Campus.name as campus_name',
            ]);
        if ($campusId !== null) {
            $q->where('Student.CampusID', $campusId)->where('student_line_bindings.campus_id', $campusId);
        }
        if ($allowedCampusIds !== null) {
            $q->whereIn('Student.CampusID', $allowedCampusIds)->whereIn('student_line_bindings.campus_id', $allowedCampusIds);
        }
        if ($studentId !== null) {
            $q->where('student_line_bindings.student_id', $studentId);
        }
        if ($lineUserId !== null) {
            $q->where('student_line_bindings.line_user_id', $lineUserId);
        }
        if ($studentName !== null && trim($studentName) !== '') {
            $q->where('Student.name', 'like', '%' . addcslashes(trim($studentName), '%_\\') . '%');
        }
        if ($status === 'verified') {
            $q->whereNotNull('student_line_bindings.verified_at');
        } elseif ($status === 'unverified') {
            $q->whereNull('student_line_bindings.verified_at');
        }

        $total = (clone $q)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage); // e.g. after unbinding the only row on the last page
        $rows = $q->orderBy('student_line_bindings.bound_at', 'desc')
            ->orderBy('student_line_bindings.id', 'desc')
            ->forPage($page, $perPage)
            ->get()
            ->map(function (\stdClass $row): \stdClass {
                $row->line_user_id_masked = self::maskLineUserId((string) $row->line_user_id);
                unset($row->line_user_id);

                return $row;
            })
            ->values()
            ->all();

        return ['data' => $rows, 'current_page' => $page, 'last_page' => $lastPage, 'total' => $total];
    }

    /**
     * Binding-page stat cards (in-app #400), all scoped by the student's current
     * campus: enabled students, those with a verified same-campus binding (bound),
     * and those whose same-campus bindings are all still unverified.
     *
     * @return array{bound_count:int, unverified_bound_count:int, student_total:int}
     */
    public function stats(?int $campusId = null, ?array $allowedCampusIds = null): array
    {
        $students = function () use ($campusId, $allowedCampusIds) {
            $q = DB::table('Student')->where('Student.enable', 1);
            if ($campusId !== null) {
                $q->where('Student.CampusID', $campusId);
            }
            if ($allowedCampusIds !== null) {
                $q->whereIn('Student.CampusID', $allowedCampusIds);
            }

            return $q;
        };
        $hasBinding = fn (bool $verified) => function ($sub) use ($verified) {
            // Same-campus only, like ParentLinePush: a stale binding left at another campus
            // reaches no parent, so it is not counted as bound here.
            $sub->select(DB::raw(1))->from('student_line_bindings as slb')
                ->whereColumn('slb.student_id', 'Student.id')
                ->whereColumn('slb.campus_id', 'Student.CampusID')
                ->when($verified, fn ($w) => $w->whereNotNull('slb.verified_at'));
        };

        return [
            'bound_count' => (int) $students()->whereExists($hasBinding(true))->count(),
            'unverified_bound_count' => (int) $students()->whereExists($hasBinding(false))->whereNotExists($hasBinding(true))->count(),
            'student_total' => (int) $students()->count(),
        ];
    }

    private static function maskLineUserId(string $uid): string
    {
        return strlen($uid) <= 12 ? $uid : substr($uid, 0, 8) . '…' . substr($uid, -4);
    }

    public function findBinding(int $bindingId): ?object
    {
        return DB::table('student_line_bindings')
            ->join('Student', 'student_line_bindings.student_id', '=', 'Student.id')
            ->leftJoin('Campus', 'student_line_bindings.campus_id', '=', 'Campus.id')
            ->select([
                'student_line_bindings.id',
                'student_line_bindings.student_id',
                'student_line_bindings.line_user_id',
                'student_line_bindings.campus_id',
                'student_line_bindings.bound_at',
                'Student.name as student_name',
                'Student.enable as student_enable',
                'Campus.name as campus_name',
            ])
            ->where('student_line_bindings.id', $bindingId)
            ->first();
    }

    /** @return Collection<int, \stdClass> */
    public function bindingsForStudent(int $studentId): Collection
    {
        return $this->listBindings(studentId: $studentId, limit: 500);
    }

    /** @return Collection<int, \stdClass> */
    public function bindingsForLineUser(string $lineUserId): Collection
    {
        return $this->listBindings(lineUserId: $lineUserId, limit: 500);
    }

    // ── Create ─────────────────────────────────────────────

    /**
     * Create a binding for a student→LINE user pair.
     *
     * @return array{status: string, binding_id?: int, reason_code?: string}
     */
    public function createBinding(int $studentId, string $lineUserId, int $campusId, ?string $correlationId = null): array
    {
        $correlationId = $this->observability->newCorrelationId($correlationId);

        /** @var \App\Models\Student|null $student */
        $student = Student::query()->whereKey($studentId)->first();
        $result = $this->classifier->classifyLineStudentId($student, '', $campusId);

        // For create, we use a simpler check — just verify student exists in campus
        if (!$student) {
            $this->observability->observe($correlationId, C::CHANNEL_LINE, C::METHOD_STUDENT_ID, [
                'outcome' => C::OUTCOME_FAILURE, 'reasonCode' => C::STUDENT_NOT_FOUND,
                'campusId' => $campusId, 'studentId' => $studentId, 'candidateCount' => 0, 'phoneMatchCount' => 0,
            ]);
            return ['status' => 'failure', 'reason_code' => C::STUDENT_NOT_FOUND];
        }

        if ((int) ($student->getAttribute('CampusID') ?? 0) !== $campusId) {
            $this->observability->observe($correlationId, C::CHANNEL_LINE, C::METHOD_STUDENT_ID, [
                'outcome' => C::OUTCOME_FAILURE, 'reasonCode' => C::CAMPUS_MISMATCH,
                'campusId' => $campusId, 'studentId' => $studentId, 'candidateCount' => 1, 'phoneMatchCount' => 0,
            ]);
            return ['status' => 'failure', 'reason_code' => C::CAMPUS_MISMATCH];
        }

        // Check for already bound
        $existing = DB::table('student_line_bindings')
            ->where('student_id', $studentId)
            ->where('line_user_id', $lineUserId)
            ->exists();

        if ($existing) {
            $this->observability->observe($correlationId, C::CHANNEL_LINE, C::METHOD_STUDENT_ID, [
                'outcome' => C::OUTCOME_NOOP, 'reasonCode' => C::ALREADY_BOUND,
                'campusId' => $campusId, 'studentId' => $studentId, 'candidateCount' => 1, 'phoneMatchCount' => 1,
            ]);
            return ['status' => 'noop', 'reason_code' => C::ALREADY_BOUND];
        }

        // Check cross-campus conflict
        $crossCampusBinding = DB::table('student_line_bindings')
            ->where('line_user_id', $lineUserId)
            ->where('campus_id', '!=', $campusId)
            ->exists();

        $bindingId = DB::table('student_line_bindings')->insertGetId([
            'student_id' => $studentId,
            'line_user_id' => $lineUserId,
            'campus_id' => $campusId,
            'bound_at' => now(),
        ]);

        $this->observability->observe($correlationId, C::CHANNEL_LINE, C::METHOD_STUDENT_ID, [
            'outcome' => C::OUTCOME_SUCCESS, 'reasonCode' => null,
            'campusId' => $campusId, 'studentId' => $studentId, 'candidateCount' => 1, 'phoneMatchCount' => 1,
        ]);

        Log::info('parent_binding.created', [
            'binding_id' => $bindingId, 'student_id' => $studentId,
            'line_user_id' => $lineUserId, 'campus_id' => $campusId,
            'cross_campus_conflict' => $crossCampusBinding,
        ]);

        return [
            'status' => 'success',
            'binding_id' => $bindingId,
            'cross_campus_conflict' => $crossCampusBinding,
        ];
    }

    // ── Delete / Unbind ────────────────────────────────────

    public function deleteBinding(int $bindingId): bool
    {
        $binding = DB::table('student_line_bindings')->where('id', $bindingId)->first();
        if (!$binding) {
            return false;
        }

        return DB::transaction(function () use ($binding, $bindingId) {
            // Revoke before deleting. Lock order: student_line_bindings (#3988)
            // -> student_guardians -> parent_sessions, as in GuardianSyncService::revoke().
            $revoked = $this->revokeGuardianAccess((int) $binding->student_id, (string) $binding->line_user_id);
            if (DB::table('student_line_bindings')->where('id', $bindingId)->delete() === 0) {
                return false;
            }

            Log::info('parent_binding.deleted', [
                'binding_id' => $bindingId,
                'student_id' => $binding->student_id,
                'line_user_id' => $binding->line_user_id,
                'guardian_links_revoked' => $revoked['guardian_links_revoked'],
                'parent_sessions_invalidated' => $revoked['parent_sessions_invalidated'],
            ]);

            return true;
        });
    }

    /**
     * #3933: unbinding also revokes guardian access. Call it right before the
     * (student, LINE) binding row is deleted (one row per pair: unique index).
     * Revokes this LINE subject's guardian link to this student via
     * GuardianSyncService::revoke() and expires its parent sessions for this
     * student. Other guardians and this LINE subject's other students are
     * untouched. Idempotent; applies whether or not PERF_MULTI_GUARDIAN is on.
     *
     * @return array{guardian_links_revoked: int, parent_sessions_invalidated: int}
     */
    public function revokeGuardianAccess(int $studentId, string $lineUserId): array
    {
        $lineUserId = trim($lineUserId);
        $result = ['guardian_links_revoked' => 0, 'parent_sessions_invalidated' => 0];
        if ($lineUserId === '') {
            return $result;
        }

        return DB::transaction(function () use ($studentId, $lineUserId, $result) {
            // #3988: lock the binding first. LINE login holds a shared lock on
            // the same rows while it creates its session, so the two serialize
            // at any isolation level: either this waits and then expires the
            // committed session, or login waits and then finds no binding.
            DB::table('student_line_bindings')
                ->where('student_id', $studentId)
                ->where('line_user_id', $lineUserId)
                ->lockForUpdate()
                ->get(['id']);

            // Telemetry only: revoke() expires these too, so count them first.
            $result['parent_sessions_invalidated'] = ParentSession::query()
                ->where('StudentID', $studentId)
                ->where('line_user_id', $lineUserId)
                ->where('ExpiresAt', '>', now())
                ->count();

            if (GuardianSyncService::dualWriteEnabled()) {
                $guardianId = Guardian::query()->where('line_user_id', $lineUserId)->value('id');
                if ($guardianId !== null) {
                    $links = StudentGuardian::query()
                        ->where('student_id', $studentId)
                        ->where('guardian_id', (int) $guardianId)
                        ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
                        ->lockForUpdate()
                        ->get();
                    foreach ($links as $link) {
                        $this->guardianSync->revoke($link);
                        $result['guardian_links_revoked']++;
                    }
                }
            }

            // Also covers a LINE subject with no guardian row yet (compat window).
            $this->guardianAccess->invalidateSessionsForLineUser($studentId, $lineUserId);

            return $result;
        });
    }

    public function unbindStudent(int $studentId, string $lineUserId): int
    {
        return DB::table('student_line_bindings')
            ->where('student_id', $studentId)
            ->where('line_user_id', $lineUserId)
            ->delete();
    }

    // ── Orphan Detection (P1-3) ────────────────────────────

    /** @return Collection<int, \stdClass> */
    public function findOrphanBindings(?array $allowedCampusIds = null): Collection
    {
        // Orphans: bindings where student no longer exists or is disabled
        return DB::table('student_line_bindings')
            ->leftJoin('Student', 'student_line_bindings.student_id', '=', 'Student.id')
            ->select([
                'student_line_bindings.id',
                'student_line_bindings.student_id',
                'student_line_bindings.line_user_id',
                'student_line_bindings.campus_id',
                'student_line_bindings.bound_at',
                'Student.name as student_name',
                'Student.enable as student_enable',
            ])
            ->where(function ($q) {
                $q->whereNull('Student.id')
                  ->orWhere('Student.enable', 0);
            })
            ->when($allowedCampusIds !== null, fn ($q) => $q->whereIn('student_line_bindings.campus_id', $allowedCampusIds))
            ->orderBy('student_line_bindings.bound_at', 'desc')
            ->get();
    }

    /** @return int number of deleted orphan bindings */
    public function cleanupOrphans(): int
    {
        $orphans = $this->findOrphanBindings();
        $count = 0;

        foreach ($orphans as $orphan) {
            // #3944 follow-up: revoke guardian access + sessions, not a bare delete.
            if ($this->deleteBinding((int) $orphan->id)) {
                $count++;
            }
        }

        if ($count > 0) {
            Log::info('parent_binding.orphans_cleaned', ['count' => $count]);
        }

        return $count;
    }

    // ── Cross-Campus Conflict Detection (P2-1) ─────────────

    /** @return Collection<int, \stdClass> */
    public function findCrossCampusConflicts(?array $allowedCampusIds = null): Collection
    {
        // Find line_user_ids with bindings in multiple campuses
        $conflictedLineUsers = DB::table('student_line_bindings')
            ->select('line_user_id')
            ->groupBy('line_user_id')
            ->havingRaw('COUNT(DISTINCT campus_id) > 1')
            ->pluck('line_user_id');

        if ($conflictedLineUsers->isEmpty()) {
            return collect();
        }

        return DB::table('student_line_bindings')
            ->join('Student', 'student_line_bindings.student_id', '=', 'Student.id')
            ->leftJoin('Campus', 'student_line_bindings.campus_id', '=', 'Campus.id')
            ->leftJoin('parent_cross_campus_access as pcca', function ($join) {
                $join->on('student_line_bindings.campus_id', '=', 'pcca.identity_group_id');
            })
            ->select([
                'student_line_bindings.id',
                'student_line_bindings.student_id',
                'student_line_bindings.line_user_id',
                'student_line_bindings.campus_id',
                'student_line_bindings.bound_at',
                'Student.name as student_name',
                'Campus.name as campus_name',
                'pcca.mode as access_mode',
            ])
            ->whereIn('student_line_bindings.line_user_id', $conflictedLineUsers)
            ->when($allowedCampusIds !== null, fn ($q) => $q->whereIn('student_line_bindings.campus_id', $allowedCampusIds))
            ->orderBy('student_line_bindings.line_user_id')
            ->orderBy('student_line_bindings.bound_at', 'desc')
            ->get();
    }

    // ── Metrics (P3-1) ─────────────────────────────────────

    /** @return array<string, mixed> */
    public function metrics(?int $campusId = null, int $days = 7, ?array $allowedCampusIds = null): array
    {
        $end = now();
        $start = now()->subDays($days);

        $totalBindingsQuery = DB::table('student_line_bindings');
        $activeBindingsQuery = DB::table('student_line_bindings')
            ->join('Student', 'student_line_bindings.student_id', '=', 'Student.id')
            ->where('Student.enable', 1);
        foreach ([$totalBindingsQuery, $activeBindingsQuery] as $query) {
            if ($campusId !== null) {
                $query->where('student_line_bindings.campus_id', $campusId);
            }
            if ($allowedCampusIds !== null) {
                $query->whereIn('student_line_bindings.campus_id', $allowedCampusIds);
            }
        }
        $totalBindings = $totalBindingsQuery->count();
        $activeBindings = $activeBindingsQuery->count();
        $orphanCount = $this->findOrphanBindings($allowedCampusIds)->when($campusId !== null, fn ($rows) => $rows->where('campus_id', $campusId))->count();
        $crossCampusConflicts = $this->findCrossCampusConflicts($allowedCampusIds)
            ->when($campusId !== null, fn ($rows) => $rows->where('campus_id', $campusId))
            ->groupBy('line_user_id')->count();

        // Attempt stats from PB-00 log
        $attempts = DB::table('parent_binding_attempts')
            ->whereBetween('occurred_at', [$start, $end])
            ->when($campusId, fn ($q) => $q->where('campus_id', $campusId))
            ->when($allowedCampusIds !== null && $campusId === null, fn ($q) => $q->whereIn('campus_id', $allowedCampusIds))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as successes,
                SUM(CASE WHEN outcome = 'failure' THEN 1 ELSE 0 END) as failures,
                SUM(CASE WHEN outcome = 'noop' THEN 1 ELSE 0 END) as noops
            ")
            ->first();

        $denom = ($attempts->successes ?? 0) + ($attempts->failures ?? 0);

        return [
            'generated_at' => now()->toIso8601String(),
            'period_days' => $days,
            'campus_id' => $campusId,
            'total_bindings' => $totalBindings,
            'active_bindings' => $activeBindings,
            'orphan_bindings' => $orphanCount,
            'cross_campus_conflicts' => $crossCampusConflicts,
            'attempts' => [
                'total' => (int) ($attempts->total ?? 0),
                'successes' => (int) ($attempts->successes ?? 0),
                'failures' => (int) ($attempts->failures ?? 0),
                'noops' => (int) ($attempts->noops ?? 0),
                'success_rate' => $denom > 0 ? round(($attempts->successes ?? 0) / $denom, 4) : null,
            ],
            'health' => $this->healthSummary($orphanCount, $crossCampusConflicts, $denom > 0 ? ($attempts->failures ?? 0) / $denom : null),
        ];
    }

    /** @return array{status: string, alerts: array<int, string>} */
    private function healthSummary(int $orphans, int $conflicts, ?float $failureRate): array
    {
        $alerts = [];
        $status = 'healthy';

        if ($orphans > 0) {
            $alerts[] = "{$orphans} orphan binding(s) detected";
            $status = 'warning';
        }
        if ($conflicts > 0) {
            $alerts[] = "{$conflicts} cross-campus conflict(s) detected";
            $status = 'warning';
        }
        if ($failureRate !== null && $failureRate > 0.5) {
            $alerts[] = 'Binding failure rate exceeds 50%';
            $status = 'critical';
        }

        return compact('status', 'alerts');
    }
}
