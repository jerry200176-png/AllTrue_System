<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\ParentBinding\GuardianSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent backfill: mirror legacy Student.parent_* into primary guardians.
 *
 * Default is dry-run. Production writes require Founder GO plus
 * --apply --force and ALLOW_PROD_REPAIR=1 (same gate as other repair commands).
 * --verify reports missing primary / multi-primary / phone mismatch (read-only).
 * Does not enable PERF_MULTI_GUARDIAN and does not touch portal identity.
 */
class SyncGuardiansFromLegacyCommand extends Command
{
    protected $signature = 'guardians:sync-from-legacy
                            {--dry-run : Preview only (default when --apply/--verify omitted)}
                            {--apply : Write primary guardian dual-write rows}
                            {--verify : Read-only integrity report (missing/dup primary/mismatch)}
                            {--campus-id= : Limit to CampusID}
                            {--student-id= : Limit to one Student id}
                            {--limit=20000 : Max students to scan}
                            {--force : Required with --apply outside local/testing}';

    protected $description = 'Dry-run / apply / verify dual-write of legacy parent_* into primary guardians';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $verify = (bool) $this->option('verify');
        $modes = (int) $apply + (int) $verify + ((bool) $this->option('dry-run') ? 1 : 0);
        if ($modes > 1) {
            $this->error('Choose exactly one of --dry-run, --apply, or --verify.');
            return self::FAILURE;
        }
        if ($verify) {
            return $this->runVerify();
        }

        $dryRun = !$apply;

        if ($apply && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }
        if (!GuardianSyncService::dualWriteEnabled()) {
            $this->error('guardian tables unavailable; migrate first');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $query = $this->legacyContactStudentQuery()->orderBy('id')->limit($limit);

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

    private function runVerify(): int
    {
        if (!GuardianSyncService::dualWriteEnabled()) {
            $this->error('guardian tables unavailable; migrate first');
            return self::FAILURE;
        }

        $this->info('[VERIFY] guardians:sync-from-legacy');
        $limit = max(1, (int) $this->option('limit'));
        $missing = [];
        $mismatch = [];
        $scanned = 0;

        /** @var Student $student */
        foreach ($this->legacyContactStudentQuery()->orderBy('id')->limit($limit)->cursor() as $student) {
            $scanned++;
            $studentId = (int) $student->getKey();
            $parentPhone = trim((string) ($student->getAttribute('parent_phone') ?? ''));
            $legacyPhone = trim((string) ($student->getAttribute('Phone') ?? ''));
            $phone = $parentPhone !== '' ? $parentPhone : $legacyPhone;
            $name = trim((string) ($student->getAttribute('parent_name') ?? ''));
            $normalized = Guardian::normalizePhone($phone);

            if ($normalized === '' && $name === '') {
                continue;
            }

            $primaries = StudentGuardian::query()
                ->with('guardian')
                ->where('student_id', $studentId)
                ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
                ->where('is_primary', true)
                ->get();

            if ($primaries->count() === 0) {
                $missing[] = $studentId;
                continue;
            }

            if ($normalized === '') {
                continue;
            }

            $g = $primaries->first()->guardian;
            $gNorm = $g !== null ? (string) ($g->phone_normalized ?? '') : '';
            if ($gNorm !== $normalized) {
                $mismatch[] = $studentId;
            }
        }

        $multiPrimaryRows = StudentGuardian::query()
            ->select('student_id', DB::raw('COUNT(*) as c'))
            ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
            ->where('is_primary', true)
            ->groupBy('student_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $report = [
            'mode' => 'verify',
            'scanned' => $scanned,
            'missing_primary_count' => count($missing),
            'missing_primary_sample' => array_slice($missing, 0, 20),
            'multi_primary_count' => count($multiPrimaryRows),
            'multi_primary_sample' => array_slice($multiPrimaryRows, 0, 20),
            'phone_mismatch_count' => count($mismatch),
            'phone_mismatch_sample' => array_slice($mismatch, 0, 20),
            'ok' => count($missing) === 0 && count($multiPrimaryRows) === 0 && count($mismatch) === 0,
            'flag_multi_guardian' => GuardianSyncService::enabled(),
        ];
        $this->info(json_encode($report, JSON_UNESCAPED_UNICODE));
        Log::info('guardians.sync_from_legacy.verify', $report);

        if (!$report['ok']) {
            $this->error('VERIFY_FAILED');
            return self::FAILURE;
        }

        $this->info('VERIFY_OK');
        return self::SUCCESS;
    }

    private function legacyContactStudentQuery()
    {
        $campusId = $this->option('campus-id');
        $studentId = $this->option('student-id');

        return Student::query()
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
            });
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
