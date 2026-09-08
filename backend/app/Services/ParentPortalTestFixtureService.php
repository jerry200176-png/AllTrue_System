<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentGuardian;
use App\Services\ParentBinding\GuardianSyncService;
use App\Models\Scopes\OperationalTenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ParentPortalTestFixtureService
{
    public function ensureFixture(): array
    {
        if (!Schema::hasTable('guardians') || !Schema::hasTable('student_guardians')) {
            throw new \RuntimeException('guardian_tables_unavailable');
        }

        return DB::transaction(function (): array {
            // @phpstan-ignore-next-line staticMethod.notFound (Eloquent magic static builder)
            $campus = Campus::withoutGlobalScope(OperationalTenantScope::class)
                ->where('code', config('parent_portal_test.campus_code'))
                ->lockForUpdate()
                ->first();

            if ($campus && !(bool) $campus->is_test) {
                throw new \RuntimeException('fixture_code_belongs_to_operational_campus');
            }

            if (!$campus) {
                // @phpstan-ignore-next-line staticMethod.notFound (Eloquent magic static builder)
                $campus = Campus::withoutGlobalScope(OperationalTenantScope::class)->create([
                    'name' => config('parent_portal_test.campus_name'),
                    'code' => config('parent_portal_test.campus_code'),
                    'active' => false,
                    'Current' => 0,
                    'LineNotifyID' => '',
                    'Client_ID' => '',
                    'Client_Secret' => '',
                    'LIFFID' => '',
                    'LIFF_URL' => '',
                    'URL' => '',
                    'Token' => null,
                    'TelegramToken' => null,
                    'TelegramChatID' => null,
                    'TelegramURL' => '',
                    'TeachLIFFID' => '',
                    'TeachLIFF_URL' => '',
                    'is_test' => true,
                ]);
            }

            // @phpstan-ignore-next-line staticMethod.notFound (Eloquent magic static builder)
            $students = Student::withoutGlobalScope(OperationalTenantScope::class)
                ->where('CampusID', $campus->id)
                ->get();
            if ($students->count() > 1) {
                throw new \RuntimeException('fixture_campus_has_multiple_students');
            }

            $student = $students->first();
            if (!$student) {
                // @phpstan-ignore-next-line staticMethod.notFound (Eloquent magic static builder)
                $student = Student::withoutGlobalScope(OperationalTenantScope::class)->create([
                    'name' => config('parent_portal_test.student_name'),
                    'CampusID' => $campus->id,
                    'ClassID' => 7,
                    'SchoolName' => config('parent_portal_test.school_name'),
                    'Phone' => null,
                    'parent_name' => config('parent_portal_test.guardian_name'),
                    'parent_phone' => null,
                    'notes' => 'TEST / SYNTHETIC Parent Portal smoke fixture',
                    'status' => 'active',
                    'enable' => 1,
                    'MDT' => now(),
                    'Notify_Token' => '',
                ]);
            } elseif ((int) $student->CampusID !== (int) $campus->id) {
                throw new \RuntimeException('fixture_student_campus_mismatch');
            }

            if (StudentClass::query()->where('StudentID', $student->id)->exists()) {
                throw new \RuntimeException('fixture_has_operational_course_data');
            }

            // The synthetic guardian intentionally has no phone/LINE identity.
            // GuardianSyncService cannot use a blank normalized phone as an
            // idempotency key, so preserve the already-created fixture link
            // instead of creating a name-only guardian on every smoke run.
            $links = StudentGuardian::query()
                ->where('student_id', $student->id)
                ->get();
            if ($links->count() > 1) {
                throw new \RuntimeException('fixture_has_multiple_guardian_links');
            }

            $link = $links->first();
            if (!$link) {
                $link = app(GuardianSyncService::class)->syncPrimaryFromStudent($student);
            }
            if (!$link instanceof StudentGuardian) {
                throw new \RuntimeException('fixture_guardian_sync_failed');
            }
            if ($link->status !== StudentGuardian::STATUS_ACTIVE || !$link->is_primary) {
                throw new \RuntimeException('fixture_guardian_link_not_active');
            }

            $guardian = $link->guardian;
            if (!$guardian
                || trim((string) $guardian->phone) !== ''
                || trim((string) $guardian->phone_normalized) !== ''
                || trim((string) $guardian->line_user_id) !== '') {
                throw new \RuntimeException('fixture_guardian_has_external_contact');
            }

            // Keep a persistent smoke identity incapable of sending parent
            // feedback/tuition notifications even if future fixture reads add
            // records. No LINE binding or external contact is created.
            if ($link->notify_learning_feedback || $link->notify_tuition) {
                $link->forceFill([
                    'notify_learning_feedback' => false,
                    'notify_tuition' => false,
                ])->save();
            }

            return [
                'campus_id' => (int) $campus->id,
                'student_id' => (int) $student->id,
                'guardian_id' => (int) $link->guardian_id,
                'student_guardian_id' => (int) $link->id,
                'is_test' => (bool) $campus->is_test,
            ];
        });
    }

    public function createReusableSession(array $fixture): array
    {
        return DB::transaction(function () use ($fixture): array {
            // @phpstan-ignore-next-line staticMethod.notFound (Eloquent magic static builder)
            $student = Student::withoutGlobalScope(OperationalTenantScope::class)
                ->whereKey((int) $fixture['student_id'])
                ->where('CampusID', (int) $fixture['campus_id'])
                ->firstOrFail();

            // @phpstan-ignore-next-line staticMethod.notFound (Eloquent magic static builder)
            $campus = Campus::withoutGlobalScope(OperationalTenantScope::class)
                ->whereKey((int) $student->CampusID)
                ->where('is_test', true)
                ->firstOrFail();

            // This is the isolated fixture only. Revoke old smoke sessions so
            // repeated CI runs do not accumulate active ParentSession rows.
            ParentSession::query()->where('StudentID', $student->id)->delete();

            $token = Str::random(48);
            $session = ParentSession::query()->create([
                'StudentID' => $student->id,
                'TokenHash' => hash('sha256', $token),
                'ExpiresAt' => now()->addHours(12),
            ]);

            return [
                'campus_id' => (int) $campus->id,
                'student_id' => (int) $student->id,
                'parent_session_id' => (int) $session->id,
                'token' => $token,
                'expires_at' => $session->ExpiresAt?->toIso8601String(),
            ];
        });
    }
}
