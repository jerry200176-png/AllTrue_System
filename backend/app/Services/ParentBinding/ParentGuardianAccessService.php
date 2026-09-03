<?php

namespace App\Services\ParentBinding;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentLineBinding;
use Illuminate\Support\Collection;

/**
 * Portal dual-read authZ over guardians + student_guardians (PB-04 GSR analogue).
 * Flag off → legacy StudentLineBinding only. Does not introduce ParentIdentity tables.
 */
final class ParentGuardianAccessService
{
    public static function portalDualReadEnabled(): bool
    {
        return GuardianSyncService::enabled() && GuardianSyncService::dualWriteEnabled();
    }

    /**
     * Students this LINE subject may access (active/read_only), campus-filtered.
     *
     * @return Collection<int, Student>
     */
    public function studentsForLineUser(string $lineUserId, ?int $preferredCampusId = null): Collection
    {
        $lineUserId = trim($lineUserId);
        if ($lineUserId === '') {
            return collect();
        }

        $ids = collect();

        if (self::portalDualReadEnabled()) {
            $guardian = Guardian::query()->where('line_user_id', $lineUserId)->first();
            if ($guardian) {
                // Campus preference only reorders below; do not drop other
                // active/read_only children (multi-child switcher).
                $ids = $ids->merge(
                    StudentGuardian::activeAccess()
                        ->where('guardian_id', (int) $guardian->getKey())
                        ->pluck('student_id')
                );
            }
        }

        // Dual-read / flag-off: verified SLB remains a valid access path.
        $slb = StudentLineBinding::query()
            ->whereNotNull('verified_at')
            ->where('line_user_id', $lineUserId)
            ->pluck('student_id');
        $ids = $ids->merge($slb)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $students = Student::query()->whereIn('id', $ids->all())->get();
        if ($preferredCampusId) {
            $preferred = $students->filter(fn ($s) => (int) $s->CampusID === $preferredCampusId)->values();
            if ($preferred->isNotEmpty()) {
                // Prefer campus match but keep other children for switcher.
                $others = $students->filter(fn ($s) => (int) $s->CampusID !== $preferredCampusId)->values();
                $students = $preferred->concat($others)->values();
            }
        }

        // Drop students whose guardian link for this LINE was explicitly revoked
        // while still present on SLB (revoke must win over dual-read SLB).
        if (self::portalDualReadEnabled()) {
            $guardian = Guardian::query()->where('line_user_id', $lineUserId)->first();
            if ($guardian) {
                $revokedStudentIds = StudentGuardian::query()
                    ->where('guardian_id', (int) $guardian->getKey())
                    ->where('status', StudentGuardian::STATUS_REVOKED)
                    ->pluck('student_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                if ($revokedStudentIds !== []) {
                    $students = $students->reject(fn ($s) => in_array((int) $s->id, $revokedStudentIds, true))->values();
                }
            }
        }

        return $students;
    }

    public function lineMayAccessStudent(string $lineUserId, int $studentId): bool
    {
        return $this->studentsForLineUser($lineUserId)
            ->contains(fn ($s) => (int) $s->id === $studentId);
    }

    /**
     * Immediately expire ParentSessions for a revoked relationship (PB-04 AC).
     * Scoped to the guardian's LINE subject. Phone-only guardians (no LINE)
     * are a no-op here so we never fan out to unrelated phoneless sessions.
     */
    public function invalidateSessionsForLink(StudentGuardian $link): int
    {
        $studentId = (int) $link->student_id;
        $guardian = $link->relationLoaded('guardian') ? $link->guardian : $link->guardian()->first();
        $lineUserId = $guardian !== null ? trim((string) ($guardian->line_user_id ?? '')) : '';
        if ($lineUserId === '') {
            return 0;
        }

        return \App\Models\ParentSession::query()
            ->where('StudentID', $studentId)
            ->where('line_user_id', $lineUserId)
            ->where('ExpiresAt', '>', now())
            ->update(['ExpiresAt' => now()]);
    }
}
