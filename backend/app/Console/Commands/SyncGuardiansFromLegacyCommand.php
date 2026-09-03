<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\ParentBinding\GuardianSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent backfill: mirror legacy Student.parent_* into primary guardians.
 *
 * Default is dry-run. Production writes require Founder GO plus
 * --apply --force and ALLOW_PROD_REPAIR=1 (same gate as other repair commands).
 * Does not enable PERF_MULTI_GUARDIAN and does not touch portal identity.
 */
class SyncGuardiansFromLegacyCommand extends Command
{
    protected $signature = 'guardians:sync-from-legacy
                            {--dry-run : Preview only (default when --apply omitted)}
                            {--apply : Write primary guardian dual-write rows}
                            {--campus-id= : Limit to CampusID}
                            {--student-id= : Limit to one Student id}
                            {--limit=500 : Max students to scan}
                            {--force : Required with --apply outside local/testing}';

    protected $description = 'Dry-run / apply dual-write of legacy parent_* into primary guardians';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = !$apply;

        if ($apply && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');
            return self::FAILURE;
        }
        if ($apply && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }
        if (!GuardianSyncService::dualWriteEnabled()) {
            $this->error('guardian tables unavailable; migrate first');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $campusId = $this->option('campus-id');
        $studentId = $this->option('student-id');

        $query = Student::query()
            ->when($campusId !== null && $campusId !== '', fn ($q) => $q->where('CampusID', (int) $campusId))
            ->when($studentId !== null && $studentId !== '', fn ($q) => $q->whereKey((int) $studentId))
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('parent_phone')->where('parent_phone', '!=', '');
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('parent_name')->where('parent_name', '!=', '');
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('Phone')->where('Phone', '!=', '');
                });
            })
            ->orderBy('id')
            ->limit($limit);

        $scanned = 0;
        $wouldWrite = 0;
        $alreadyOk = 0;
        $written = 0;
        $skippedEmpty = 0;

        $this->info($dryRun ? '[DRY-RUN] guardians:sync-from-legacy' : '[APPLY] guardians:sync-from-legacy');

        $sync = app(GuardianSyncService::class);

        /** @var Student $student */
        foreach ($query->cursor() as $student) {
            $scanned++;
            $parentPhone = trim((string) ($student->getAttribute('parent_phone') ?? ''));
            $legacyPhone = trim((string) ($student->getAttribute('Phone') ?? ''));
            $phone = $parentPhone !== '' ? $parentPhone : $legacyPhone;
            $name = trim((string) ($student->getAttribute('parent_name') ?? ''));
            $normalized = Guardian::normalizePhone($phone);

            if ($normalized === '' && $name === '') {
                $skippedEmpty++;
                continue;
            }

            $needs = $this->needsSync((int) $student->getKey(), $normalized, $name);
            if (!$needs) {
                $alreadyOk++;
                continue;
            }

            $wouldWrite++;
            $this->line(sprintf(
                '  student_id=%d campus=%s phone=%s name=%s',
                (int) $student->getKey(),
                (string) ($student->CampusID ?? ''),
                $phone !== '' ? $phone : '-',
                $name !== '' ? $name : '-'
            ));

            if ($dryRun) {
                continue;
            }

            $link = $sync->syncPrimaryFromStudent($student);
            if ($link) {
                $written++;
            }
        }

        $summary = [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'scanned' => $scanned,
            'already_ok' => $alreadyOk,
            'would_write' => $wouldWrite,
            'written' => $written,
            'skipped_empty' => $skippedEmpty,
            'flag_multi_guardian' => GuardianSyncService::enabled(),
        ];
        $this->info(json_encode($summary, JSON_UNESCAPED_UNICODE));
        Log::info('guardians.sync_from_legacy', $summary);

        if ($dryRun) {
            $this->comment('Dry-run complete. Production --apply requires Founder GO + --force + ALLOW_PROD_REPAIR=1.');
        }

        return self::SUCCESS;
    }

    private function needsSync(int $studentId, string $normalized, string $name): bool
    {
        /** @var StudentGuardian|null $primary */
        $primary = StudentGuardian::query()
            ->with('guardian')
            ->where('student_id', $studentId)
            ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
            ->where('is_primary', true)
            ->first();

        if (!$primary || !$primary->guardian) {
            return true;
        }

        $g = $primary->guardian;
        $gNorm = (string) ($g->phone_normalized ?? '');
        $gName = trim((string) ($g->display_name ?? ''));

        if ($normalized !== '' && $gNorm !== $normalized) {
            return true;
        }
        if ($normalized === '' && $name !== '' && $gName !== $name) {
            return true;
        }

        return false;
    }

    private function assertProductionAllowed(): bool
    {
        $env = (string) config('app.env', 'production');
        if (in_array($env, ['local', 'testing'], true)) {
            return true;
        }
        if (!(bool) $this->option('force')) {
            $this->error('--force required with --apply outside local/testing');
            return false;
        }
        if (getenv('ALLOW_PROD_REPAIR') !== '1' && ($_ENV['ALLOW_PROD_REPAIR'] ?? null) !== '1') {
            $this->error('ALLOW_PROD_REPAIR=1 required for production apply');
            return false;
        }

        return true;
    }
}
