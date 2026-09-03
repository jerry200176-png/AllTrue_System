<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentLineBinding;
use App\Services\ParentBinding\GuardianSyncService;
use App\Services\ParentBinding\ParentGuardianAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cutover readiness: orphan SLB / revoked+verified SLB / phone ambiguity.
 * Default read-only. --repair-slb links missing SLB→guardian rows and unverifies
 * SLB sitting on revoked links (Founder-gated with --force + ALLOW_PROD_REPAIR).
 * Does not drop parent_phone or SLB tables.
 */
class GuardiansCutoverAuditCommand extends Command
{
    protected $signature = 'guardians:cutover-audit
                            {--repair-slb : Link verified SLB orphans into student_guardians}
                            {--limit=20000 : Max verified SLB rows to scan}
                            {--force : Required with --repair-slb outside local/testing}';

    protected $description = 'Read-only Multi-Guardian cutover audit (+ optional SLB orphan repair)';

    public function handle(): int
    {
        if (!GuardianSyncService::dualWriteEnabled()) {
            $this->error('guardian tables unavailable; migrate first');
            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $repair = (bool) $this->option('repair-slb');
        $access = app(ParentGuardianAccessService::class);

        $report = $access->cutoverAudit($limit);
        $this->info(json_encode($report, JSON_UNESCAPED_UNICODE));
        Log::info('guardians.cutover_audit', $report);

        if ($report['blocking']['slb_orphans'] ?? false) {
            // continue to optional repair
        }

        if (!$repair) {
            if (!($report['ok'] ?? false)) {
                $this->error('CUTOVER_AUDIT_FAILED');
                return self::FAILURE;
            }
            if (($report['phone_shared_guardian_count'] ?? 0) > 0) {
                $this->warn('NOTE: shared phone_normalized across guardians (inspect sample; not auto-blocking).');
            }
            $this->info('CUTOVER_AUDIT_OK');
            return self::SUCCESS;
        }

        if (!$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $sync = app(GuardianSyncService::class);
        $linked = 0;
        $failed = 0;
        $unverified = 0;

        $bindings = StudentLineBinding::query()
            ->whereNotNull('verified_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($bindings as $binding) {
            $line = trim((string) $binding->line_user_id);
            $studentId = (int) $binding->student_id;
            if ($line === '') {
                continue;
            }

            $guardian = Guardian::query()->where('line_user_id', $line)->first();
            if ($guardian) {
                $link = StudentGuardian::query()
                    ->where('guardian_id', (int) $guardian->getKey())
                    ->where('student_id', $studentId)
                    ->first();
                if ($link && $link->status === StudentGuardian::STATUS_REVOKED) {
                    $link->loadMissing('guardian');
                    $unverified += $access->unverifyLineBindingForLink($link);
                    continue;
                }
                if ($link) {
                    continue;
                }
            }

            $student = Student::query()->whereKey($studentId)->first();
            if (!$student) {
                $failed++;
                continue;
            }

            $result = $sync->linkFromLineBinding($student, $line, (int) $binding->getKey());
            if ($result) {
                $linked++;
            } else {
                $failed++;
            }
        }

        $after = $access->cutoverAudit($limit);
        $summary = [
            'mode' => 'repair-slb',
            'linked' => $linked,
            'failed' => $failed,
            'unverified_revoked_slb' => $unverified,
            'after' => $after,
        ];
        $this->info(json_encode($summary, JSON_UNESCAPED_UNICODE));
        Log::info('guardians.cutover_repair_slb', $summary);

        if (!($after['ok'] ?? false)) {
            $this->error('CUTOVER_REPAIR_INCOMPLETE');
            return self::FAILURE;
        }
        $this->info('CUTOVER_REPAIR_OK');
        return self::SUCCESS;
    }

    private function assertProductionAllowed(): bool
    {
        $env = (string) app()->environment();
        if (in_array($env, ['local', 'testing'], true)) {
            return true;
        }
        if (!(bool) $this->option('force')) {
            $this->error('--force required outside local/testing');
            return false;
        }
        if ((string) env('ALLOW_PROD_REPAIR', '') !== '1') {
            $this->error('ALLOW_PROD_REPAIR=1 required for production repair');
            return false;
        }
        return true;
    }
}
