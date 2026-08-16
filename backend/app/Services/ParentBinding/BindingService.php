<?php

namespace App\Services\ParentBinding;

use App\Models\Student;
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
    ) {}

    // ── Query ──────────────────────────────────────────────

    /** @return Collection<int, object> */
    public function listBindings(?int $campusId = null, ?int $studentId = null, ?string $lineUserId = null, int $limit = 100): Collection
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
                'Student.name as student_name',
                'Student.enable as student_enable',
                'Campus.name as campus_name',
            ]);

        if ($campusId !== null) {
            $q->where('student_line_bindings.campus_id', $campusId);
        }
        if ($studentId !== null) {
            $q->where('student_line_bindings.student_id', $studentId);
        }
        if ($lineUserId !== null) {
            $q->where('student_line_bindings.line_user_id', $lineUserId);
        }

        return $q->orderBy('student_line_bindings.bound_at', 'desc')->limit($limit)->get();
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

    /** @return Collection<int, object> */
    public function bindingsForStudent(int $studentId): Collection
    {
        return $this->listBindings(studentId: $studentId, limit: 500);
    }

    /** @return Collection<int, object> */
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

        DB::table('student_line_bindings')->where('id', $bindingId)->delete();

        Log::info('parent_binding.deleted', [
            'binding_id' => $bindingId,
            'student_id' => $binding->student_id,
            'line_user_id' => $binding->line_user_id,
        ]);

        return true;
    }

    public function unbindStudent(int $studentId, string $lineUserId): int
    {
        return DB::table('student_line_bindings')
            ->where('student_id', $studentId)
            ->where('line_user_id', $lineUserId)
            ->delete();
    }

    // ── Orphan Detection (P1-3) ────────────────────────────

    /** @return Collection<int, object> */
    public function findOrphanBindings(): Collection
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
            ->orderBy('student_line_bindings.bound_at', 'desc')
            ->get();
    }

    /** @return int number of deleted orphan bindings */
    public function cleanupOrphans(): int
    {
        $orphans = $this->findOrphanBindings();
        $count = 0;

        foreach ($orphans as $orphan) {
            DB::table('student_line_bindings')->where('id', $orphan->id)->delete();
            $count++;
        }

        if ($count > 0) {
            Log::info('parent_binding.orphans_cleaned', ['count' => $count]);
        }

        return $count;
    }

    // ── Cross-Campus Conflict Detection (P2-1) ─────────────

    /** @return Collection<int, object> */
    public function findCrossCampusConflicts(): Collection
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
            ->orderBy('student_line_bindings.line_user_id')
            ->orderBy('student_line_bindings.bound_at', 'desc')
            ->get();
    }

    // ── Metrics (P3-1) ─────────────────────────────────────

    /** @return array<string, mixed> */
    public function metrics(?int $campusId = null, int $days = 7): array
    {
        $end = now();
        $start = now()->subDays($days);

        $totalBindings = DB::table('student_line_bindings')->count();
        $activeBindings = DB::table('student_line_bindings')
            ->join('Student', 'student_line_bindings.student_id', '=', 'Student.id')
            ->where('Student.enable', 1)
            ->count();
        $orphanCount = $this->findOrphanBindings()->count();
        $crossCampusConflicts = $this->findCrossCampusConflicts()->groupBy('line_user_id')->count();

        // Attempt stats from PB-00 log
        $attempts = DB::table('parent_binding_attempts')
            ->whereBetween('occurred_at', [$start, $end])
            ->when($campusId, fn ($q) => $q->where('campus_id', $campusId))
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
