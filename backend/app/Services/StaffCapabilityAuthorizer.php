<?php
namespace App\Services;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserCapabilityGrant;
use Illuminate\Support\Facades\Schema;
/**
 * Campus-aware staff capability resolution (in-app #299 Phase A).
 */
class StaffCapabilityAuthorizer
{
    public const CAP_DIRECTOR = 'director';
    public const CAP_TEACHER = 'teacher';
    public function enabled(): bool
    {
        return (bool) config('staff_capabilities.multi_role_v1_enabled', false);
    }
    /**
     * @return array{
     *   role: string,
     *   teacher_id: ?int,
     *   campus_ids: list<int>,
     *   capabilities: list<string>,
     *   acting_as: ?string,
     *   capability_campus_ids: array<string, list<int>>,
     *   context_denied: bool
     * }
     */
    public function resolve(User $user, ?string $actingAsHeader): array
    {
        $capabilityCampuses = $this->capabilityCampusMap($user);
        $capabilities = array_values(array_filter(
            array_keys($capabilityCampuses),
            static fn (string $cap) => $capabilityCampuses[$cap] !== []
        ));
        $requestedContext = is_string($actingAsHeader) && trim($actingAsHeader) !== '';
        $actingAs = $this->normalizeActingAs($actingAsHeader);
        $contextDenied = $requestedContext
            && ($actingAs === null || !in_array($actingAs, $capabilities, true));
        if ($contextDenied) {
            return [
                'role' => 'forbidden',
                'teacher_id' => null,
                'campus_ids' => [],
                'capabilities' => $capabilities,
                'acting_as' => null,
                'capability_campus_ids' => $capabilityCampuses,
                'context_denied' => true,
            ];
        }
        $userType = (string) $user->getAttribute('type');
        $userId = (int) $user->getKey();
        if ($actingAs === null) {
            if (count($capabilities) === 1) {
                $actingAs = $capabilities[0];
            } elseif ($userType === 'T' && in_array(self::CAP_TEACHER, $capabilities, true)) {
                $actingAs = self::CAP_TEACHER;
            } elseif (in_array(self::CAP_DIRECTOR, $capabilities, true)) {
                $actingAs = self::CAP_DIRECTOR;
            } elseif (in_array(self::CAP_TEACHER, $capabilities, true)) {
                $actingAs = self::CAP_TEACHER;
            }
        }
        $role = match ($actingAs) {
            self::CAP_TEACHER => 'teacher',
            self::CAP_DIRECTOR => 'director',
            default => $this->legacyRoleFromType($userType),
        };
        $campusIds = $actingAs && isset($capabilityCampuses[$actingAs])
            ? $capabilityCampuses[$actingAs]
            : $this->legacyCampusIds($userId);
        $teacherId = null;
        if ($role === 'teacher' && in_array(self::CAP_TEACHER, $capabilities, true) && $campusIds !== []) {
            $teacherId = $userId;
        }
        return [
            'role' => $role,
            'teacher_id' => $teacherId,
            'campus_ids' => $campusIds,
            'capabilities' => $capabilities,
            'acting_as' => $actingAs,
            'capability_campus_ids' => $capabilityCampuses,
            'context_denied' => false,
        ];
    }
    public function hasCapabilityOnCampus(User $user, string $capability, int $campusId): bool
    {
        $map = $this->capabilityCampusMap($user);
        return in_array($campusId, $map[$capability] ?? [], true);
    }
    /**
     * @return array<string, list<int>>
     */
    public function capabilityCampusMap(User $user): array
    {
        $map = [
            self::CAP_DIRECTOR => [],
            self::CAP_TEACHER => [],
        ];
        $userId = (int) $user->getKey();
        $userType = (string) $user->getAttribute('type');
        if (Schema::hasTable('user_capability_grants')) {
            $rows = UserCapabilityGrant::query()
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->get(['capability', 'campus_id']);
            foreach ($rows as $row) {
                $cap = (string) $row->getAttribute('capability');
                if (!isset($map[$cap])) {
                    continue;
                }
                $map[$cap][] = (int) $row->getAttribute('campus_id');
            }
        }
        // Legacy synthesis for single-role accounts (not dual-account merge).
        if ($map[self::CAP_DIRECTOR] === [] && $map[self::CAP_TEACHER] === []) {
            $campusIds = $this->legacyCampusIds($userId);
            if ($userType === 'T') {
                $map[self::CAP_TEACHER] = $campusIds;
            } elseif ($userType !== 'S' && $userType !== 'U') {
                $map[self::CAP_DIRECTOR] = $campusIds;
            }
        }
        foreach ($map as $cap => $ids) {
            $map[$cap] = array_values(array_unique(array_map('intval', $ids)));
        }
        return $map;
    }
    private function normalizeActingAs(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = strtolower(trim($value));
        return in_array($value, [self::CAP_DIRECTOR, self::CAP_TEACHER], true) ? $value : null;
    }
    private function legacyRoleFromType(string $type): string
    {
        if ($type === 'S') {
            return 'super_admin';
        }
        if ($type === 'T') {
            return 'teacher';
        }
        if ($type === 'U') {
            return 'pending';
        }
        return 'director';
    }
    /**
     * @return list<int>
     */
    private function legacyCampusIds(int $userId): array
    {
        return UserCampus::query()
            ->where('UserID', $userId)
            ->where(function ($q) {
                $q->where('Approved', true)->orWhereNull('Approved');
            })
            ->pluck('CampusID')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
