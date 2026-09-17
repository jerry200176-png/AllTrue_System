<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserCapabilityGrant;
use Illuminate\Support\Facades\Schema;

/**
 * Campus-aware staff capability resolution (in-app #299 Phase A).
 * acting_as is context only — never authority by itself.
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
     *   capability_campus_ids: array<string, list<int>>
     * }
     */
    public function resolve(User $user, ?string $actingAsHeader): array
    {
        $capabilityCampuses = $this->capabilityCampusMap($user);
        $capabilities = array_values(array_filter(
            array_keys($capabilityCampuses),
            static fn (string $cap) => $capabilityCampuses[$cap] !== []
        ));

        $actingAs = $this->normalizeActingAs($actingAsHeader);
        if ($actingAs !== null && !in_array($actingAs, $capabilities, true)) {
            // Invalid context — do not elevate; fall back to safe single-capability default.
            $actingAs = null;
        }

        if ($actingAs === null) {
            if (count($capabilities) === 1) {
                $actingAs = $capabilities[0];
            } elseif ($user->type === 'T' && in_array(self::CAP_TEACHER, $capabilities, true)) {
                $actingAs = self::CAP_TEACHER;
            } elseif (in_array(self::CAP_DIRECTOR, $capabilities, true)) {
                // Shared/non-role-sensitive calls may omit acting_as; prefer director when both
                // exist only as a default for campus list / role middleware compatibility.
                // Role-sensitive UI should still send explicit acting_as.
                $actingAs = self::CAP_DIRECTOR;
            } elseif (in_array(self::CAP_TEACHER, $capabilities, true)) {
                $actingAs = self::CAP_TEACHER;
            }
        }

        $role = match ($actingAs) {
            self::CAP_TEACHER => 'teacher',
            self::CAP_DIRECTOR => 'director',
            default => $this->legacyRole($user),
        };

        $campusIds = $actingAs && isset($capabilityCampuses[$actingAs])
            ? $capabilityCampuses[$actingAs]
            : $this->legacyCampusIds($user);

        $teacherId = null;
        if ($role === 'teacher' && in_array(self::CAP_TEACHER, $capabilities, true) && $campusIds !== []) {
            $teacherId = (int) $user->id;
        }

        return [
            'role' => $role,
            'teacher_id' => $teacherId,
            'campus_ids' => $campusIds,
            'capabilities' => $capabilities,
            'acting_as' => $actingAs,
            'capability_campus_ids' => $capabilityCampuses,
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

        if (Schema::hasTable('user_capability_grants')) {
            $rows = UserCapabilityGrant::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->get(['capability', 'campus_id']);
            foreach ($rows as $row) {
                $cap = (string) $row->capability;
                if (!isset($map[$cap])) {
                    continue;
                }
                $map[$cap][] = (int) $row->campus_id;
            }
        }

        // Legacy synthesis for single-role accounts (not dual-account merge).
        if ($map[self::CAP_DIRECTOR] === [] && $map[self::CAP_TEACHER] === []) {
            $campusIds = $this->legacyCampusIds($user);
            if ($user->type === 'T') {
                $map[self::CAP_TEACHER] = $campusIds;
            } elseif ($user->type !== 'S' && $user->type !== 'U') {
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

    private function legacyRole(User $user): string
    {
        if ($user->type === 'S') {
            return 'super_admin';
        }
        if ($user->type === 'T') {
            return 'teacher';
        }
        if ($user->type === 'U') {
            return 'pending';
        }

        return 'director';
    }

    /**
     * @return list<int>
     */
    private function legacyCampusIds(User $user): array
    {
        return UserCampus::where('UserID', $user->id)
            ->where(function ($q) {
                $q->where('Approved', true)->orWhereNull('Approved');
            })
            ->pluck('CampusID')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
