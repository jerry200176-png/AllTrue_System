<?php

namespace App\Services\ParentBinding;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentLineBinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Portal authZ over guardians + student_guardians (PB-04 GSR analogue).
 *
 * When PERF_MULTI_GUARDIAN is on (canonical cutover):
 * - LINE subject with a Guardian row → only active/read_only student_guardians
 * - LINE subject without Guardian → verified SLB compat fallback (orphan window)
 * - Non-access statuses (suspended/pending/revoked) never fall through to SLB
 * Flag off → verified SLB only (rollback).
 */
final class ParentGuardianAccessService
{
    public static function portalDualReadEnabled(): bool
    {
        return GuardianSyncService::enabled() && GuardianSyncService::dualWriteEnabled();
    }

    /**
     * Students this LINE subject may access (active/read_only), campus-ordered.
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
                // Canonical: guardian subject → relationship table only (no SLB union).
                $ids = StudentGuardian::activeAccess()
                    ->where('guardian_id', (int) $guardian->getKey())
                    ->pluck('student_id');
            } else {
                // Compat: no Guardian row yet → verified SLB until backfill links them.
                $ids = StudentLineBinding::query()
                    ->whereNotNull('verified_at')
                    ->where('line_user_id', $lineUserId)
                    ->pluck('student_id');
            }
        } else {
            $ids = StudentLineBinding::query()
                ->whereNotNull('verified_at')
                ->where('line_user_id', $lineUserId)
                ->pluck('student_id');
        }

        $ids = $ids->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $students = Student::query()->whereIn('id', $ids->all())->get();
        if ($preferredCampusId) {
            $preferred = $students->filter(fn ($s) => (int) $s->CampusID === $preferredCampusId)->values();
            if ($preferred->isNotEmpty()) {
                $others = $students->filter(fn ($s) => (int) $s->CampusID !== $preferredCampusId)->values();
                $students = $preferred->concat($others)->values();
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
     * Multi-child switch for phone-login sessions: students sharing an
     * active/read_only guardian with the current student (canonical).
     *
     * @return Collection<int, Student>
     */
    public function studentsSharingActiveGuardians(int $studentId): Collection
    {
        if (!self::portalDualReadEnabled()) {
            return collect();
        }

        $guardianIds = StudentGuardian::activeAccess()
            ->where('student_id', $studentId)
            ->pluck('guardian_id')
            ->unique()
            ->values();
        if ($guardianIds->isEmpty()) {
            return collect();
        }

        $ids = StudentGuardian::activeAccess()
            ->whereIn('guardian_id', $guardianIds->all())
            ->pluck('student_id')
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Student::query()->whereIn('id', $ids->all())->get();
    }

    public function phoneMayAccessStudent(string $normalizedPhone, int $studentId): bool
    {
        $normalizedPhone = Guardian::normalizePhone($normalizedPhone);
        if ($normalizedPhone === '') {
            return false;
        }

        if (!self::portalDualReadEnabled()) {
            $student = Student::query()->whereKey($studentId)->first();
            return $student instanceof Student
                && \App\Support\StudentContactPhone::matchesNormalizedInput($student, $normalizedPhone);
        }

        return StudentGuardian::activeAccess()
            ->where('student_id', $studentId)
            ->whereHas('guardian', function ($q) use ($normalizedPhone) {
                $q->where('phone_normalized', $normalizedPhone);
            })
            ->exists();
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

    /**
     * Unverify matching StudentLineBinding so legacy SLB cannot re-grant after revoke.
     */
    public function unverifyLineBindingForLink(StudentGuardian $link): int
    {
        $studentId = (int) $link->student_id;
        $guardian = $link->relationLoaded('guardian') ? $link->guardian : $link->guardian()->first();
        $lineUserId = $guardian !== null ? trim((string) ($guardian->line_user_id ?? '')) : '';
        if ($lineUserId === '') {
            return 0;
        }

        return StudentLineBinding::query()
            ->where('student_id', $studentId)
            ->where('line_user_id', $lineUserId)
            ->whereNotNull('verified_at')
            ->update(['verified_at' => null]);
    }

    /**
     * Read-only cutover readiness report (mismatches / orphans). Never writes.
     *
     * @return array<string, mixed>
     */
    public function cutoverAudit(int $limit = 20000): array
    {
        $limit = max(1, $limit);
        $slbOrphans = [];
        $revokedWithVerifiedSlb = [];
        $phoneAmbiguity = [];

        $bindings = StudentLineBinding::query()
            ->whereNotNull('verified_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'student_id', 'line_user_id', 'campus_id']);

        foreach ($bindings as $binding) {
            $line = trim((string) $binding->line_user_id);
            $studentId = (int) $binding->student_id;
            if ($line === '') {
                continue;
            }
            $guardian = Guardian::query()->where('line_user_id', $line)->first();
            if (!$guardian) {
                $slbOrphans[] = [
                    'slb_id' => (int) $binding->id,
                    'student_id' => $studentId,
                    'line_user_id_suffix' => substr($line, -6),
                    'reason' => 'no_guardian_for_line',
                ];
                continue;
            }
            $link = StudentGuardian::query()
                ->where('guardian_id', (int) $guardian->getKey())
                ->where('student_id', $studentId)
                ->first();
            if (!$link) {
                $slbOrphans[] = [
                    'slb_id' => (int) $binding->id,
                    'student_id' => $studentId,
                    'guardian_id' => (int) $guardian->getKey(),
                    'line_user_id_suffix' => substr($line, -6),
                    'reason' => 'missing_student_guardian_link',
                ];
                continue;
            }
            if ($link->status === StudentGuardian::STATUS_REVOKED) {
                $revokedWithVerifiedSlb[] = [
                    'slb_id' => (int) $binding->id,
                    'student_id' => $studentId,
                    'guardian_id' => (int) $guardian->getKey(),
                    'line_user_id_suffix' => substr($line, -6),
                ];
            }
        }

        $dupPhones = Guardian::query()
            ->select('phone_normalized', DB::raw('COUNT(*) as c'), DB::raw('GROUP_CONCAT(id) as ids'))
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->groupBy('phone_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->limit(100)
            ->get();
        foreach ($dupPhones as $row) {
            $phoneAmbiguity[] = [
                'phone_normalized_suffix' => substr((string) $row->phone_normalized, -4),
                'guardian_count' => (int) $row->c,
                'guardian_ids' => array_map('intval', explode(',', (string) $row->ids)),
            ];
        }

        $ok = $slbOrphans === [] && $revokedWithVerifiedSlb === [];

        return [
            'mode' => 'cutover_audit',
            'scanned_verified_slb' => $bindings->count(),
            'slb_orphan_count' => count($slbOrphans),
            'slb_orphan_sample' => array_slice($slbOrphans, 0, 30),
            'revoked_with_verified_slb_count' => count($revokedWithVerifiedSlb),
            'revoked_with_verified_slb_sample' => array_slice($revokedWithVerifiedSlb, 0, 30),
            'phone_shared_guardian_count' => count($phoneAmbiguity),
            'phone_shared_guardian_sample' => array_slice($phoneAmbiguity, 0, 30),
            'ok' => $ok,
            'flag_multi_guardian' => GuardianSyncService::enabled(),
            'blocking' => [
                // Shared phone across guardians is reported but not auto-blocking:
                // multi-LINE parents may both mirror the same legacy parent_phone.
                // Agents must inspect sample before Founder-escalating merge ambiguity.
                'phone_shared_guardians' => false,
                'slb_orphans' => count($slbOrphans) > 0,
                'revoked_slb_bypass' => count($revokedWithVerifiedSlb) > 0,
            ],
        ];
    }
}
