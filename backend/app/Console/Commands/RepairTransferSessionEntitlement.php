<?php

namespace App\Console\Commands;

use App\Models\SessionEntitlementTransfer;
use App\Services\SessionEntitlementTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class RepairTransferSessionEntitlement extends Command
{
    protected $signature = 'repair:transfer-session-entitlement
        {--source-class= : Existing StudentClass ID that owns the lesson now}
        {--target-class= : New StudentClass ID that should own the lesson}
        {--session-id= : ClassSession ID to transfer}
        {--reference= : Decision reference}
        {--reason= : Human-readable repair reason}
        {--execute : Apply the transfer}
        {--force : Required with --execute in production}
        {--snapshot= : Snapshot output path}
        {--actor= : Audit actor}
        {--actor-user-id= : Audit user ID}
        {--rollback= : Roll back a transfer ID}
        {--verify= : Verify a transfer ID}';

    protected $description = 'Safely transfer one completed ClassSession between prepaid batches';

    public function handle(SessionEntitlementTransferService $service): int
    {
        if ($this->option('rollback') !== null) return $this->rollback($service, (int) $this->option('rollback'));
        if ($this->option('verify') !== null) return $this->verify($service, (int) $this->option('verify'));

        $source = (int) $this->option('source-class');
        $target = (int) $this->option('target-class');
        $session = (int) $this->option('session-id');
        if ($source <= 0 || $target <= 0 || $session <= 0) {
            $this->error('--source-class, --target-class and --session-id are required');
            return self::INVALID;
        }

        $plan = $service->preview($source, $target, $session);
        $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$plan['ok']) {
            $this->error('DRY_RUN_BLOCKED');
            return self::FAILURE;
        }
        if (!$this->option('execute')) {
            $this->info('Dry-run complete; no data changed.');
            return self::SUCCESS;
        }
        if (!$this->productionWriteAllowed()) return self::FAILURE;

        $reason = trim((string) ($this->option('reason') ?: '已確認請假補課被誤計入原購買批次'));
        $reference = trim((string) ($this->option('reference') ?: 'P0-SESSION-ENTITLEMENT-TRANSFER'));
        $actor = (string) ($this->option('actor') ?: 'artisan:repair:transfer-session-entitlement');
        $max = SessionEntitlementTransferService::AUDIT_STRING_MAX;
        foreach (['reason' => $reason, 'reference' => $reference, 'actor' => $actor] as $label => $value) {
            if (mb_strlen($value) > $max) {
                $this->error("{$label} exceeds {$max} characters (got " . mb_strlen($value) . ')');
                return self::FAILURE;
            }
        }

        $snapshotPath = trim((string) ($this->option('snapshot') ?: ''));
        if ($snapshotPath === '') {
            $this->error('Transfer requires --snapshot');
            return self::FAILURE;
        }
        try {
            $this->writeSnapshot($snapshotPath, $service, $source, $target, $session, $plan);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $result = $service->transfer(
            $source,
            $target,
            $session,
            $reason,
            $reference,
            $this->option('actor-user-id') ? (int) $this->option('actor-user-id') : null,
            $actor
        );
        $transferId = (int) $result['transfer']->getKey();
        $verification = $service->verify($transferId);
        if (!$verification['ok']) {
            $this->error('VERIFY_FAIL_AFTER_TRANSFER');
            $this->line(json_encode($verification, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::FAILURE;
        }

        $this->info('TRANSFER_APPLIED id=' . $transferId);
        $this->line(json_encode([
            'source' => ['id' => $result['source']->getKey(), 'used' => $result['source']->getAttribute('UsedSessions'), 'remaining' => $result['source']->getAttribute('RemainingSessions')],
            'target' => ['id' => $result['target']->getKey(), 'used' => $result['target']->getAttribute('UsedSessions'), 'remaining' => $result['target']->getAttribute('RemainingSessions')],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    private function rollback(SessionEntitlementTransferService $service, int $id): int
    {
        $found = SessionEntitlementTransfer::query()->find($id);
        if (!$found instanceof SessionEntitlementTransfer) {
            $this->error('transfer not found');
            return self::FAILURE;
        }
        if (!$this->option('execute')) {
            $this->line(json_encode(['transfer_id' => $id, 'rolled_back_at' => $found->getAttribute('rolled_back_at')], JSON_UNESCAPED_UNICODE));
            $this->info('Rollback dry-run; no data changed.');
            return self::SUCCESS;
        }
        if (!$this->productionWriteAllowed()) return self::FAILURE;
        if ($found->getAttribute('rolled_back_at') !== null) {
            $this->info('TRANSFER_ALREADY_ROLLED_BACK id=' . $id . '; no data changed.');
            return self::SUCCESS;
        }

        $service->rollback($id, $this->option('actor-user-id') ? (int) $this->option('actor-user-id') : null, (string) ($this->option('actor') ?: 'artisan:repair:transfer-session-entitlement:rollback'));
        $this->info('TRANSFER_ROLLED_BACK id=' . $id);
        return self::SUCCESS;
    }

    private function verify(SessionEntitlementTransferService $service, int $id): int
    {
        $verification = $service->verify($id);
        $this->line(json_encode($verification, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$verification['ok']) {
            $this->error('VERIFY_FAIL');
            return self::FAILURE;
        }
        $this->info('VERIFY_OK');
        return self::SUCCESS;
    }

    private function productionWriteAllowed(): bool
    {
        if (!app()->environment('production')) return true;
        if (!$this->option('force')) {
            $this->error('Production requires --force');
            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1');
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $plan */
    private function writeSnapshot(string $path, SessionEntitlementTransferService $service, int $source, int $target, int $session, array $plan): void
    {
        if (File::exists($path)) throw new RuntimeException("Snapshot already exists: {$path}");
        $directory = dirname($path);
        if (!File::isDirectory($directory)) throw new RuntimeException("Snapshot directory does not exist: {$directory}");

        File::put($path, json_encode([
            'command' => self::class,
            'captured_at' => now()->toIso8601String(),
            'plan' => $plan,
            'state' => $service->snapshotFor($source, $target, $session),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info('SNAPSHOT_WRITTEN path=' . $path);
    }
}
