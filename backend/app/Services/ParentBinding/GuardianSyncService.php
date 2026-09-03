<?php

namespace App\Services\ParentBinding;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Support\StudentContactPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Additive dual-write / dual-read bridge between legacy Student.parent_* and
 * guardians + student_guardians. Safe when tables missing (pre-migration).
 */
final class GuardianSyncService
{
    public static function enabled(): bool
    {
        return (bool) config('perfflags.multi_guardian_enabled', false);
    }

    public static function dualWriteEnabled(): bool
    {
        // Dual-write is intentionally ON whenever tables exist so cutover can
        // flip read paths without a data scramble. Flag only gates dual-read /
        // staff multi-guardian UX.
        return Schema::hasTable('guardians') && Schema::hasTable('student_guardians');
    }

    /**
     * Mirror Student.parent_name / parent_phone into a primary StudentGuardian.
     * Never deletes legacy columns. Idempotent.
     */
    public function syncPrimaryFromStudent(Student $student): ?StudentGuardian
    {
        if (!self::dualWriteEnabled()) {
            return null;
        }

        $studentId = (int) $student->getKey();
        $phone = trim((string) StudentContactPhone::forStudent($student));
        $name = trim((string) ($student->getAttribute('parent_name') ?? ''));
        $normalized = Guardian::normalizePhone($phone);

        if ($normalized === '' && $name === '') {
            return null;
        }

        return DB::transaction(function () use ($student, $studentId, $phone, $name, $normalized) {
            $guardian = $this->findOrCreateGuardian($name !== '' ? $name : null, $phone, $normalized, null);

            /** @var StudentGuardian|null $existingPrimary */
            $existingPrimary = StudentGuardian::query()
                ->where('student_id', $studentId)
                ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
                ->where('is_primary', true)
                ->lockForUpdate()
                ->first();

            if ($existingPrimary && (int) $existingPrimary->guardian_id === (int) $guardian->getKey()) {
                $existingPrimary->fill([
                    'campus_id' => (int) ($student->CampusID ?? 0) ?: null,
                    'status' => StudentGuardian::STATUS_ACTIVE,
                    'source' => StudentGuardian::SOURCE_LEGACY_PHONE,
                ])->save();
                return $existingPrimary->fresh(['guardian']);
            }

            // Demote other primaries (keep rows; only one primary at a time).
            StudentGuardian::query()
                ->where('student_id', $studentId)
                ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            /** @var StudentGuardian $link */
            $link = StudentGuardian::query()->firstOrNew([
                'student_id' => $studentId,
                'guardian_id' => $guardian->getKey(),
            ]);
            $link->fill([
                'campus_id' => (int) ($student->CampusID ?? 0) ?: null,
                'role' => $link->exists ? ($link->role ?: StudentGuardian::ROLE_GUARDIAN) : StudentGuardian::ROLE_GUARDIAN,
                'is_primary' => true,
                'status' => StudentGuardian::STATUS_ACTIVE,
                'notify_learning_feedback' => $link->exists ? (bool) $link->notify_learning_feedback : true,
                'notify_tuition' => $link->exists ? (bool) $link->notify_tuition : true,
                'source' => StudentGuardian::SOURCE_LEGACY_PHONE,
                'revoked_at' => null,
            ]);
            $link->save();

            Log::info('guardian.dual_write.primary_synced', [
                'student_id' => $studentId,
                'guardian_id' => (int) $guardian->getKey(),
                'student_guardian_id' => (int) $link->getKey(),
            ]);

            return $link->fresh(['guardian']);
        });
    }

    /**
     * Attach / update a non-primary guardian for a student (staff CRUD).
     *
     * @param  array{display_name?:?string,phone?:?string,line_user_id?:?string,role?:string,is_primary?:bool,notify_learning_feedback?:bool,notify_tuition?:bool}  $attrs
     */
    public function upsertRelationship(Student $student, array $attrs, string $source = StudentGuardian::SOURCE_STAFF): StudentGuardian
    {
        if (!self::dualWriteEnabled()) {
            throw new \RuntimeException('guardian tables unavailable');
        }

        $studentId = (int) $student->getKey();
        $phone = trim((string) ($attrs['phone'] ?? ''));
        $normalized = Guardian::normalizePhone($phone);
        $lineUserId = trim((string) ($attrs['line_user_id'] ?? ''));
        $lineUserId = $lineUserId !== '' ? $lineUserId : null;
        $name = trim((string) ($attrs['display_name'] ?? ''));
        $name = $name !== '' ? $name : null;

        if ($normalized === '' && $lineUserId === null) {
            throw new \InvalidArgumentException('guardian requires phone or line_user_id');
        }

        return DB::transaction(function () use ($student, $studentId, $attrs, $source, $phone, $normalized, $lineUserId, $name) {
            $guardian = $this->findOrCreateGuardian($name, $phone !== '' ? $phone : null, $normalized, $lineUserId);

            $makePrimary = (bool) ($attrs['is_primary'] ?? false);
            if ($makePrimary) {
                StudentGuardian::query()
                    ->where('student_id', $studentId)
                    ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            /** @var StudentGuardian $link */
            $link = StudentGuardian::query()->firstOrNew([
                'student_id' => $studentId,
                'guardian_id' => $guardian->getKey(),
            ]);
            $link->fill([
                'campus_id' => (int) ($student->CampusID ?? 0) ?: null,
                'role' => (string) ($attrs['role'] ?? $link->role ?: StudentGuardian::ROLE_GUARDIAN),
                'is_primary' => $makePrimary || (!$link->exists && !StudentGuardian::query()->where('student_id', $studentId)->where('status', '!=', StudentGuardian::STATUS_REVOKED)->where('is_primary', true)->exists()),
                'status' => StudentGuardian::STATUS_ACTIVE,
                'notify_learning_feedback' => array_key_exists('notify_learning_feedback', $attrs)
                    ? (bool) $attrs['notify_learning_feedback']
                    : ($link->exists ? (bool) $link->notify_learning_feedback : true),
                'notify_tuition' => array_key_exists('notify_tuition', $attrs)
                    ? (bool) $attrs['notify_tuition']
                    : ($link->exists ? (bool) $link->notify_tuition : true),
                'source' => $source,
                'revoked_at' => null,
            ]);
            $link->save();

            if ($link->is_primary) {
                $this->mirrorPrimaryToLegacyStudent($student, $guardian);
            }

            return $link->fresh(['guardian']);
        });
    }

    /** @return list<StudentGuardian> */
    public function listForStudent(int $studentId): array
    {
        if (!self::dualWriteEnabled()) {
            return [];
        }

        return StudentGuardian::query()
            ->with('guardian')
            ->where('student_id', $studentId)
            ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function primaryContactPhone(Student $student): ?string
    {
        if (!self::enabled() || !self::dualWriteEnabled()) {
            return null;
        }

        $primary = StudentGuardian::query()
            ->with('guardian')
            ->where('student_id', (int) $student->getKey())
            ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
            ->where('is_primary', true)
            ->first();

        $guardian = $primary?->guardian;
        $phone = trim((string) ($guardian !== null ? ($guardian->phone ?? '') : ''));
        return $phone !== '' ? $phone : null;
    }

    private function findOrCreateGuardian(?string $name, ?string $phone, string $normalized, ?string $lineUserId): Guardian
    {
        $guardian = null;
        if ($lineUserId) {
            $guardian = Guardian::query()->where('line_user_id', $lineUserId)->lockForUpdate()->first();
        }
        if (!$guardian && $normalized !== '') {
            $guardian = Guardian::query()->where('phone_normalized', $normalized)->lockForUpdate()->first();
        }
        if (!$guardian) {
            $guardian = new Guardian();
        }

        if ($name !== null && $name !== '') {
            $guardian->display_name = $name;
        }
        if ($phone !== null && $phone !== '') {
            $guardian->phone = $phone;
            $guardian->phone_normalized = $normalized;
        }
        if ($lineUserId) {
            $guardian->line_user_id = $lineUserId;
        }
        $guardian->save();

        return $guardian;
    }

    private function mirrorPrimaryToLegacyStudent(Student $student, ?Guardian $guardian): void
    {
        if (!$guardian) {
            return;
        }
        // Keep legacy columns in sync while dual-writing so flag-off rollback stays correct.
        $student->setAttribute('parent_name', $guardian->display_name);
        if (trim((string) ($guardian->phone ?? '')) !== '') {
            $student->setAttribute('parent_phone', $guardian->phone);
        }
        $student->save();
    }
}
