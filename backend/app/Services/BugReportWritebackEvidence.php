<?php

namespace App\Services;

use App\Models\BugReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Opaque freshness evidence for an already permitted report read; grants no access. */
final class BugReportWritebackEvidence
{
    /** @return array{captured_at: string, proof: string}|null */
    public static function capture(BugReport $bug): ?array
    {
        if (!is_string(config('app.key')) || config('app.key') === '') {
            return null;
        }
        $capturedAt = now()->toISOString();

        return ['captured_at' => $capturedAt, 'proof' => self::proof($bug, $capturedAt)];
    }

    /** Current parent and child locking reads stay protected through the caller's commit. */
    public static function verify(BugReport $bug, string $capturedAt, string $proof): bool
    {
        return self::currentMatch($bug, $capturedAt, $proof, true) !== null;
    }

    /**
     * Re-read the unchanged signed review snapshot without modifying records.
     * @return array{captured_at:string, proof:string}|null
     */
    public static function refreshUnchanged(BugReport $bug, string $capturedAt, string $proof): ?array
    {
        $current = self::currentMatch($bug, $capturedAt, $proof, false);
        if (!$current) {
            return null;
        }
        $freshAt = now()->toISOString();

        return ['captured_at' => $freshAt, 'proof' => self::proof($current, $freshAt, true)];
    }

    private static function currentMatch(BugReport $bug, string $capturedAt, string $proof, bool $requireFresh): ?BugReport
    {
        if ($bug->getConnection()->transactionLevel() < 1
            || !is_string(config('app.key')) || config('app.key') === ''
            || !preg_match('/^[0-9a-f]{64}$/D', $proof)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $capturedAt)) {
            return null;
        }
        try {
            $at = Carbon::createFromFormat('Y-m-d\TH:i:s.u\Z', $capturedAt, 'UTC');
            if (!$at || $at->toISOString() !== $capturedAt) {
                return null;
            }
            $clock = now();
            if ($at->greaterThan($clock) || ($requireFresh && $at->lessThan($clock->copy()->subMinutes(15)))) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $current = BugReport::on($bug->getConnectionName())->whereKey($bug->getKey())->lockForUpdate()->first();

        return $current instanceof BugReport && hash_equals(self::proof($current, $capturedAt, true), $proof) ? $current : null;
    }

    private static function proof(BugReport $bug, string $capturedAt, bool $lockChildren = false): string
    {
        $report = $bug->getRawOriginal();
        ksort($report);
        $children = [];
        foreach (['comments', 'attachments', 'statusLogs', 'evidence'] as $relation) {
            // Locking reads see current committed children, not an earlier REPEATABLE READ snapshot.
            // MySQL next-key locks on the indexed parent range also protect concurrent inserts.
            $query = $bug->{$relation}()->orderBy('id');
            if ($lockChildren) {
                $query->lockForUpdate();
            }
            $children[$relation] = $query->get()->map(static function (Model $row): array {
                $attributes = $row->getRawOriginal();
                ksort($attributes);

                return $attributes;
            })->all();
        }
        $payload = json_encode(['alltrue-bug-writeback-v1', (int) $bug->id, $capturedAt, $report, $children], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
