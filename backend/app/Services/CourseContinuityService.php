<?php

namespace App\Services;

use App\Models\CourseContractGroup;
use App\Models\CourseContractGroupMember;
use App\Models\StudentClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Course Continuity (#1382) — link StudentClass rows into groups without merging finance/sessions.
 */
class CourseContinuityService
{
    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     * @return \Illuminate\Support\Collection<int, CourseContractGroup>
     */
    public function listForStudent(array $scope, int $studentId, ?int $campusId = null)
    {
        $q = CourseContractGroup::query()
            ->with(['activeMembers' => function ($m) {
                $m->orderBy('sequence')->orderBy('id');
            }])
            ->where('student_id', $studentId);

        if ($campusId) {
            $q->where('campus_id', $campusId);
        }

        if (($scope['mode'] ?? '') !== 'all') {
            $ids = array_map('intval', $scope['campus_ids'] ?? []);
            if ($ids === []) {
                return collect();
            }
            $q->whereIn('campus_id', $ids);
        }

        return $q->orderByDesc('id')->get();
    }

    /**
     * @param  array{
     *   student_id:int,
     *   campus_id:int,
     *   subject_id?:int|null,
     *   label?:string|null,
     *   members: array<int, array{student_class_id:int, relation_type:string, effective_from?:string|null, decision_reason?:string|null}>
     * }  $payload
     */
    public function createGroup(array $payload, ?int $actorId, array $scope): CourseContractGroup
    {
        $this->assertCampusInScope($scope, (int) $payload['campus_id']);

        $members = $payload['members'] ?? [];
        if ($members === []) {
            throw ValidationException::withMessages(['members' => '至少需要一筆合約成員']);
        }

        return DB::transaction(function () use ($payload, $actorId, $members) {
            $group = CourseContractGroup::create([
                'student_id' => (int) $payload['student_id'],
                'campus_id' => (int) $payload['campus_id'],
                'subject_id' => isset($payload['subject_id']) ? (int) $payload['subject_id'] : null,
                'label' => $payload['label'] ?? null,
                'created_by' => $actorId,
            ]);

            $seq = 0;
            foreach ($members as $row) {
                $this->attachMember($group, $row, $actorId, $seq);
                $seq++;
            }

            return $group->fresh(['activeMembers']);
        });
    }

    /**
     * @param  array{student_class_id:int, relation_type:string, effective_from?:string|null, decision_reason?:string|null}  $row
     */
    public function addMember(CourseContractGroup $group, array $row, ?int $actorId, array $scope): CourseContractGroupMember
    {
        $this->assertCampusInScope($scope, (int) $group->campus_id);

        return DB::transaction(function () use ($group, $row, $actorId) {
            $nextSeq = (int) $group->activeMembers()->max('sequence') + 1;

            return $this->attachMember($group, $row, $actorId, $nextSeq);
        });
    }

    public function unlinkMember(CourseContractGroup $group, int $memberId, array $scope): CourseContractGroupMember
    {
        $this->assertCampusInScope($scope, (int) $group->campus_id);

        $member = $group->members()->where('id', $memberId)->first();
        if (!$member) {
            throw ValidationException::withMessages(['member' => '成員不存在']);
        }

        // Soft-mark then delete so unique(student_class_id) allows future re-link; SC row untouched.
        $snapshot = $member->replicate();
        $snapshot->unlinked_at = now();
        $member->delete();

        return $snapshot;
    }

    /**
     * @param  array{student_class_id:int, relation_type:string, effective_from?:string|null, decision_reason?:string|null}  $row
     */
    private function attachMember(CourseContractGroup $group, array $row, ?int $actorId, int $sequence): CourseContractGroupMember
    {
        $scId = (int) ($row['student_class_id'] ?? 0);
        $type = (string) ($row['relation_type'] ?? '');
        if ($scId <= 0) {
            throw ValidationException::withMessages(['student_class_id' => '合約無效']);
        }
        if (!in_array($type, CourseContractGroupMember::RELATION_TYPES, true)) {
            throw ValidationException::withMessages(['relation_type' => '關聯類型無效']);
        }
        if ($type === 'replacement' && empty($row['effective_from'])) {
            throw ValidationException::withMessages(['effective_from' => '替換關聯必須指定生效日']);
        }

        /** @var StudentClass|null $sc */
        $sc = StudentClass::query()->where('ID', $scId)->first();
        if (!$sc) {
            throw ValidationException::withMessages(['student_class_id' => '找不到合約']);
        }
        if ((int) $sc->StudentID !== (int) $group->student_id) {
            throw ValidationException::withMessages(['student_class_id' => '不可跨學生加入同一群組']);
        }
        if ((int) $sc->by1 !== (int) $group->campus_id) {
            throw ValidationException::withMessages(['student_class_id' => '不可跨校區加入同一群組']);
        }
        if (!empty($sc->PackageID)) {
            throw ValidationException::withMessages(['student_class_id' => 'MVP 不將方案（package）合約納入 Continuity 群組']);
        }
        if ($group->subject_id && (int) $sc->SubjectID !== (int) $group->subject_id) {
            throw ValidationException::withMessages(['student_class_id' => '科目與群組不一致']);
        }

        $existing = CourseContractGroupMember::query()
            ->where('student_class_id', $scId)
            ->whereNull('unlinked_at')
            ->first();
        if ($existing) {
            if ((int) $existing->group_id === (int) $group->id) {
                return $existing;
            }
            throw ValidationException::withMessages(['student_class_id' => '此合約已屬於其他群組']);
        }

        return CourseContractGroupMember::create([
            'group_id' => $group->id,
            'student_class_id' => $scId,
            'relation_type' => $type,
            'effective_from' => $row['effective_from'] ?? null,
            'sequence' => $sequence,
            'decision_reason' => $row['decision_reason'] ?? null,
            'created_by' => $actorId,
        ]);
    }

    /**
     * @param  array{mode: string, campus_ids: array<int>}  $scope
     */
    private function assertCampusInScope(array $scope, int $campusId): void
    {
        if (($scope['mode'] ?? '') === 'all') {
            return;
        }
        $ids = array_map('intval', $scope['campus_ids'] ?? []);
        if (!in_array($campusId, $ids, true)) {
            throw ValidationException::withMessages(['campus_id' => '校區不在授權範圍']);
        }
    }
}
