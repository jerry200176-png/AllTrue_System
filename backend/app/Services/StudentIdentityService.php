<?php

namespace App\Services;

use App\Models\ParentCrossCampusAccess;
use App\Models\Student;
use App\Models\StudentIdentityAuditLog;
use App\Models\StudentIdentityGroup;
use App\Models\StudentIdentityMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compatibility bridge for one AllTrue student identity across branch-local
 * legacy Student rows. Legacy foreign keys remain branch-local by design.
 */
class StudentIdentityService
{
    public const MODE_OFF = 'off';
    public const MODE_READONLY = 'readonly';
    public const MODE_ACTIONS = 'actions';

    public function groupForStudent(int $studentId): ?StudentIdentityGroup
    {
        return StudentIdentityMember::query()
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->with('group')
            ->first()
            ?->group;
    }

    public function activeMembers(int $groupId): Collection
    {
        return StudentIdentityMember::query()
            ->where('identity_group_id', $groupId)
            ->where('status', 'active')
            ->with(['student', 'group.access'])
            ->orderBy('campus_id')
            ->get();
    }

    public function accessMode(int $groupId): string
    {
        $mode = ParentCrossCampusAccess::query()
            ->where('identity_group_id', $groupId)
            ->value('mode');

        return in_array($mode, [self::MODE_OFF, self::MODE_READONLY, self::MODE_ACTIONS], true)
            ? $mode
            : self::MODE_OFF;
    }

    public function parentContext(int $studentId): array
    {
        $group = $this->groupForStudent($studentId);
        if (!$group || $group->status !== 'active') {
            return [null, self::MODE_OFF, collect()];
        }

        $mode = $this->accessMode((int) $group->id);
        $members = $mode === self::MODE_OFF
            ? collect([$this->activeMembers((int) $group->id)->firstWhere('student_id', $studentId)])
            : $this->activeMembers((int) $group->id);

        return [$group, $mode, $members->filter()->values()];
    }

    public function linkStudents(
        int $firstStudentId,
        int $secondStudentId,
        ?int $actorUserId,
        string $actorRole,
        array $authorizedCampusIds
    ): StudentIdentityGroup {
        if ($firstStudentId <= 0 || $secondStudentId <= 0 || $firstStudentId === $secondStudentId) {
            throw ValidationException::withMessages(['student_id' => '請選擇兩筆不同的學生資料。']);
        }

        /** @var Student|null $first */
        $first = Student::query()->find($firstStudentId);
        /** @var Student|null $second */
        $second = Student::query()->find($secondStudentId);
        if (!$first || !$second) {
            throw ValidationException::withMessages(['student_id' => '找不到指定學生資料。']);
        }

        $campuses = [(int) $first->getAttribute('CampusID'), (int) $second->getAttribute('CampusID')];
        if (count(array_unique($campuses)) < 2) {
            throw ValidationException::withMessages(['student_id' => '身份關聯需來自不同分校；同分校資料請先處理重複資料。']);
        }
        if ($actorRole !== 'super_admin' && count(array_diff($campuses, array_map('intval', $authorizedCampusIds))) > 0) {
            throw ValidationException::withMessages(['campus_id' => '需同時具備兩間分校權限才能建立身份關聯。']);
        }

        return DB::transaction(function () use ($first, $second, $actorUserId, $actorRole) {
            $firstId = (int) $first->getAttribute('id');
            $secondId = (int) $second->getAttribute('id');
            $firstMember = StudentIdentityMember::query()->where('student_id', $firstId)->first();
            $secondMember = StudentIdentityMember::query()->where('student_id', $secondId)->first();

            if ($firstMember && $firstMember->status === 'active' && $secondMember
                && $secondMember->status === 'active'
                && (int) $firstMember->identity_group_id !== (int) $secondMember->identity_group_id) {
                throw ValidationException::withMessages(['student_id' => '兩筆學生已屬於不同身份群組，v1 不自動合併群組。']);
            }

            $groupId = (int) ($firstMember?->identity_group_id ?: $secondMember?->identity_group_id ?: 0);
            if ($groupId > 0) {
                /** @var StudentIdentityGroup $group */
                $group = StudentIdentityGroup::query()->findOrFail($groupId);
            } else {
                /** @var StudentIdentityGroup $group */
                $group = StudentIdentityGroup::query()->create([
                    'display_name' => trim((string) $first->getAttribute('name')) ?: trim((string) $second->getAttribute('name')),
                    'status' => 'active',
                    'created_by_user_id' => $actorUserId,
                ]);
            }

            foreach ([$first, $second] as $student) {
                $studentId = (int) $student->getAttribute('id');
                $campusId = (int) $student->getAttribute('CampusID');
                $member = StudentIdentityMember::query()->where('student_id', $studentId)->first();
                if ($member && (int) $member->getAttribute('identity_group_id') !== (int) $group->getAttribute('id')) {
                    throw ValidationException::withMessages(['student_id' => '學生已被其他身份群組佔用，請先解除原關聯。']);
                }
                if (!$member) {
                    StudentIdentityMember::query()->create([
                        'identity_group_id' => $group->getAttribute('id'),
                        'student_id' => $studentId,
                        'campus_id' => $campusId,
                        'status' => 'active',
                        'created_by_user_id' => $actorUserId,
                    ]);
                    $this->audit((int) $group->getAttribute('id'), $studentId, $campusId, $actorUserId, 'link', [
                        'actor_role' => $actorRole,
                        'source_student_id' => $firstId,
                        'target_student_id' => $secondId,
                    ]);
                }
            }

            ParentCrossCampusAccess::query()->firstOrCreate(
                ['identity_group_id' => $group->getAttribute('id')],
                ['mode' => self::MODE_OFF, 'updated_by_user_id' => $actorUserId]
            );

            return $group->fresh(['members.student', 'access']);
        });
    }

    public function setMode(int $groupId, string $mode, ?int $actorUserId): ParentCrossCampusAccess
    {
        if (!in_array($mode, [self::MODE_OFF, self::MODE_READONLY, self::MODE_ACTIONS], true)) {
            throw ValidationException::withMessages(['mode' => 'mode 必須是 off、readonly 或 actions。']);
        }

        /** @var StudentIdentityGroup $group */
        $group = StudentIdentityGroup::query()->findOrFail($groupId);
        /** @var ParentCrossCampusAccess $access */
        $access = ParentCrossCampusAccess::query()->updateOrCreate(
            ['identity_group_id' => $group->getAttribute('id')],
            ['mode' => $mode, 'updated_by_user_id' => $actorUserId]
        );
        $this->audit((int) $group->getAttribute('id'), null, null, $actorUserId, 'access_mode_changed', ['mode' => $mode]);

        return $access;
    }

    public function createBranchMember(
        int $groupId,
        int $sourceStudentId,
        int $targetCampusId,
        ?int $actorUserId,
        string $actorRole,
        array $authorizedCampusIds
    ): Student {
        /** @var StudentIdentityGroup $group */
        $group = StudentIdentityGroup::query()->findOrFail($groupId);
        /** @var Student $source */
        $source = Student::query()->findOrFail($sourceStudentId);
        $sourceMember = StudentIdentityMember::query()
            ->where('identity_group_id', $groupId)
            ->where('student_id', $sourceStudentId)
            ->where('status', 'active')
            ->first();
        if (!$sourceMember) {
            throw ValidationException::withMessages(['identity_group_id' => '來源學生不是此身份群組的有效成員。']);
        }
        $authorizedCampusIds = array_map('intval', $authorizedCampusIds);
        $existingGroupCampuses = $this->activeMembers($groupId)->pluck('campus_id')->map(fn ($id) => (int) $id)->unique()->all();
        if ($actorRole !== 'super_admin' && (
            !in_array($targetCampusId, $authorizedCampusIds, true)
            || count(array_diff($existingGroupCampuses, $authorizedCampusIds)) > 0
        )) {
            throw ValidationException::withMessages(['branch_id' => '無權限在目標分校建立課程。']);
        }

        $existing = StudentIdentityMember::query()
            ->where('identity_group_id', $groupId)
            ->where('campus_id', $targetCampusId)
            ->where('status', 'active')
            ->with('student')
            ->first();
        if ($existing?->student) {
            return $existing->student;
        }

        return DB::transaction(function () use ($group, $source, $targetCampusId, $actorUserId, $actorRole) {
            /** @var Student $branchStudent */
            $branchStudent = $source->replicate();
            $branchStudent->setAttribute('CampusID', $targetCampusId);
            // LINE authorization is explicitly re-established at the identity
            // level; do not duplicate legacy LINE IDs into the new branch row.
            $branchStudent->setAttribute('LineID', null);
            $branchStudent->save();

            StudentIdentityMember::query()->create([
                'identity_group_id' => $group->getAttribute('id'),
                'student_id' => $branchStudent->getAttribute('id'),
                'campus_id' => $targetCampusId,
                'status' => 'active',
                'created_by_user_id' => $actorUserId,
            ]);
            $this->audit((int) $group->getAttribute('id'), (int) $branchStudent->getAttribute('id'), $targetCampusId, $actorUserId, 'enrollment_member_created', [
                'source_student_id' => $source->getAttribute('id'),
                'actor_role' => $actorRole,
            ]);
            return $branchStudent;
        });
    }

    public function unlinkStudent(int $studentId, ?int $actorUserId, string $reason = ''): StudentIdentityMember
    {
        return DB::transaction(function () use ($studentId, $actorUserId, $reason) {
            /** @var StudentIdentityMember $member */
            $member = StudentIdentityMember::query()->where('student_id', $studentId)->firstOrFail();
            if ($member->getAttribute('status') !== 'active') {
                return $member;
            }
            $member->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by_user_id' => $actorUserId,
                'revoked_reason' => trim($reason) ?: null,
            ]);
            $this->audit((int) $member->getAttribute('identity_group_id'), (int) $member->getAttribute('student_id'), (int) $member->getAttribute('campus_id'), $actorUserId, 'unlink', [
                'reason' => trim($reason),
            ]);

            return $member->fresh();
        });
    }

    public function auditHistory(int $groupId): Collection
    {
        return StudentIdentityAuditLog::query()
            ->where('identity_group_id', $groupId)
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    private function audit(?int $groupId, ?int $studentId, ?int $campusId, ?int $actorUserId, string $action, array $metadata): void
    {
        StudentIdentityAuditLog::query()->create([
            'identity_group_id' => $groupId,
            'student_id' => $studentId,
            'campus_id' => $campusId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
