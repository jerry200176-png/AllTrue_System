<?php

namespace App\Services\ParentBinding;

use App\Models\Campus;
use App\Models\StudentLineBinding;
use Illuminate\Support\Collection;

/**
 * Cross-campus LINE binding conflict detection + resolution (Phase 2).
 *
 * A "conflict" is one student who carries active bindings in ≥2 different
 * campuses. Directors resolve conflicts by deleting their own campus binding
 * (a student wrongly bound here), while super admins may keep one binding and
 * purge the rest across all campuses.
 *
 * @see docs/adr/ADR-PARENT-STUDENT-BINDING.md  (cross-campus conflict, campus isolation)
 */
final class BindingConflictService
{
    /**
     * List cross-campus binding conflicts.
     *
     * @param int[]|null $campusIds  When set, only conflicts touching these
     *                               campuses are returned (campus isolation).
     *
     * @return array<int, array{
     *     id: int,
     *     student_id: int,
     *     student_name: string|null,
     *     campus_count: int,
     *     bindings: array<int, array<string, mixed>>
     * }>
     */
    public function findConflicts(?array $campusIds = null): array
    {
        $conflictStudentIds = StudentLineBinding::query()
            ->whereNotNull('campus_id')
            ->select('student_id')
            ->groupBy('student_id')
            ->havingRaw('COUNT(DISTINCT campus_id) > 1')
            ->pluck('student_id')
            ->all();

        if (empty($conflictStudentIds)) {
            return [];
        }

        $bindings = StudentLineBinding::with('student')
            ->whereIn('student_id', $conflictStudentIds)
            ->whereNotNull('campus_id')
            ->orderBy('student_id')
            ->orderBy('campus_id')
            ->orderBy('id')
            ->get();

        $campusNameMap = $this->campusNameMap($bindings);
        $conflicts = [];

        foreach ($bindings->groupBy('student_id') as $studentId => $studentBindings) {
            $bindingCampusIds = $studentBindings->pluck('campus_id')->unique();
            if ($bindingCampusIds->count() <= 1) {
                continue; // guard: should not happen given HAVING above, but cheap
            }
            // Campus isolation: when scoped, the conflict must touch at least one allowed campus.
            if ($campusIds !== null && $bindingCampusIds->intersect($campusIds)->isEmpty()) {
                continue;
            }

            $first = $studentBindings->first();
            $student = $first?->student;

            $conflicts[] = [
                'id'           => (int) $studentId,
                'student_id'   => (int) $studentId,
                'student_name' => $student?->name,
                'campus_count' => $bindingCampusIds->count(),
                'bindings'     => $studentBindings->map(fn (StudentLineBinding $b) => [
                    'id'                  => $b->id,
                    'campus_id'           => $b->campus_id,
                    'campus_name'         => $campusNameMap->get((int) $b->campus_id),
                    'line_user_id_masked' => $this->maskLineUserId($b->line_user_id),
                    'bound_at'            => $b->bound_at?->toIso8601String(),
                    'verified_at'         => $b->verified_at?->toIso8601String(),
                    'verification_method' => $b->verification_method,
                ])->values()->all(),
            ];
        }

        return $conflicts;
    }

    /**
     * Keep one binding and purge every other cross-campus binding of the same student.
     *
     * @param int[]|null $allowedCampusIds  When set, the kept binding must belong
     *                                      to one of these campuses.
     *
     * @return array{kept_binding_id: int, removed_binding_ids: int[]}
     */
    public function resolveKeep(int $studentId, int $bindingId, ?array $allowedCampusIds = null): array
    {
        $keep = $this->bindingOf($studentId, $bindingId, $allowedCampusIds);

        $removed = StudentLineBinding::query()
            ->where('student_id', $studentId)
            ->where('id', '!=', $bindingId)
            ->whereNotNull('campus_id')
            ->get()
            ->each(fn (StudentLineBinding $b) => $b->delete());

        return [
            'kept_binding_id'   => $keep->id,
            'removed_binding_ids' => $removed->pluck('id')->map(fn ($v) => (int) $v)->values()->all(),
        ];
    }

    /**
     * Delete a single binding.
     *
     * @param int[]|null $allowedCampusIds  When set, the binding must belong to one
     *                                      of these campuses.
     *
     * @return array{removed_binding_id: int}
     */
    public function resolveDelete(int $studentId, int $bindingId, ?array $allowedCampusIds = null): array
    {
        $binding = $this->bindingOf($studentId, $bindingId, $allowedCampusIds);
        $binding->delete();

        return ['removed_binding_id' => $binding->id];
    }

    /**
     * Fetch a binding that belongs to the given student, enforcing optional
     * campus scoping. Throws a domain exception when not found or out of scope.
     */
    private function bindingOf(int $studentId, int $bindingId, ?array $allowedCampusIds = null): StudentLineBinding
    {
        $binding = StudentLineBinding::where('id', $bindingId)
            ->where('student_id', $studentId)
            ->first();

        if (!$binding) {
            throw new \InvalidArgumentException('Binding not found for this student');
        }
        if ($allowedCampusIds !== null && !in_array((int) $binding->campus_id, $allowedCampusIds, true)) {
            throw new \DomainException('Binding is outside the operator campus scope');
        }

        return $binding;
    }

    /** @return Collection<int, string|null> keyed by campus id */
    private function campusNameMap(Collection $bindings): Collection
    {
        $ids = $bindings->pluck('campus_id')->filter()->unique()->all();
        if (empty($ids)) {
            return collect();
        }

        return Campus::whereIn('id', $ids)->pluck('name', 'id');
    }

    private function maskLineUserId(string $uid): string
    {
        if (strlen($uid) <= 12) {
            return $uid;
        }

        return substr($uid, 0, 8) . '…' . substr($uid, -4);
    }
}
