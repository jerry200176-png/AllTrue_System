<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Keeps PII-free, per-run evidence for scheduled production commands.
 *
 * Raw command output stays on the Pi under storage/logs. The JSONL ledger only
 * contains a fixed job key, configured local schedule, completion timestamp,
 * outcome and output filename so it is safe for the health workflow to report.
 */
final class SchedulerEvidence
{
    public const TIMEZONE = 'Asia/Taipei';

    /** @var array<string,array{command:string,time:string}> */
    private const JOBS = [
        'teacher-signin-close-orphans' => ['command' => 'teacher-signin:close-orphans', 'time' => '00:05'],
        'reconcile-nightly' => ['command' => 'reconcile:nightly', 'time' => '02:00'],
        'student-signin-close-orphans' => ['command' => 'student-signin:close-orphans', 'time' => '02:30'],
        'rfid-prune-pending' => ['command' => 'rfid:prune-pending', 'time' => '03:00'],
        'learning-records-drift-check' => ['command' => 'learning-records:drift-check --fix', 'time' => '03:20'],
        'sessions-audit-stranded' => ['command' => 'sessions:audit-stranded --json', 'time' => '03:40'],
        'sessions-generate-forward' => ['command' => 'sessions:generate-forward --execute --scheduled --horizon-weeks=4', 'time' => '03:45'],
        'learning-records-backfill-missing' => ['command' => 'learning-records:backfill-missing', 'time' => '03:50'],
        'bugs-verify-reproductions' => ['command' => 'bugs:verify-reproductions --json', 'time' => '04:00'],
        'ops-business-digest' => ['command' => 'ops:business-digest', 'time' => '04:10'],
    ];

    /** @return array<string,array{command:string,time:string}> */
    public static function jobs(): array
    {
        return self::JOBS;
    }

    public static function ensureDirectories(): void
    {
        foreach ([self::ledgerDirectory(), self::outputDirectory()] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create scheduler evidence directory: {$directory}");
            }
        }
    }

    public static function outputPath(string $job, ?CarbonInterface $at = null): string
    {
        self::assertKnownJob($job);

        return self::outputDirectory() . '/' . self::localTime($at)->format('Y-m-d') . "-{$job}.log";
    }

    public static function ledgerPath(?CarbonInterface $at = null): string
    {
        return self::ledgerDirectory() . '/' . self::localTime($at)->format('Y-m-d') . '.jsonl';
    }

    public static function recordCompletion(string $job, string $status, ?CarbonInterface $at = null): void
    {
        self::assertKnownJob($job);
        if (!in_array($status, ['success', 'failure'], true)) {
            throw new \InvalidArgumentException("Unsupported scheduler evidence status: {$status}");
        }

        self::ensureDirectories();
        $local = self::localTime($at);
        $entry = [
            'schema_version' => 1,
            'job' => $job,
            'command' => self::JOBS[$job]['command'],
            'expected_schedule' => self::JOBS[$job]['time'],
            'timezone' => self::TIMEZONE,
            'completed_at' => $local->toIso8601String(),
            'status' => $status,
            'output_file' => basename(self::outputPath($job, $local)),
        ];

        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents(self::ledgerPath($local), $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to write scheduler evidence for {$job}");
        }
    }

    /** @return array<string,mixed> */
    public static function summarize(string $date): array
    {
        $at = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
        $entries = self::readLedger($at);
        $jobs = [];
        $healthy = true;

        foreach (self::JOBS as $key => $definition) {
            $records = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['job'] ?? null) === $key));
            $outputPath = self::outputPath($key, $at);
            $result = [
                'expected_schedule' => $definition['time'],
                'executions' => count($records),
                'latest_execution' => $records === [] ? null : end($records)['completed_at'],
                'status' => 'verified',
                'observed_result' => null,
            ];

            if (count($records) === 0) {
                $result['status'] = 'missing';
            } elseif (count($records) > 1) {
                $result['status'] = 'duplicate';
            } elseif (($records[0]['status'] ?? null) !== 'success') {
                $result['status'] = 'failed';
            } elseif (!is_file($outputPath) || filesize($outputPath) === 0) {
                $result['status'] = 'missing_output';
            } else {
                $parsed = self::summarizeOutput($key, (string) file_get_contents($outputPath));
                if ($parsed === null) {
                    $result['status'] = 'unparseable_output';
                } else {
                    $result['observed_result'] = $parsed;
                }
            }

            if ($result['status'] !== 'verified') {
                $healthy = false;
            }
            $jobs[$key] = $result;
        }

        return [
            'date' => $at->toDateString(),
            'timezone' => self::TIMEZONE,
            'healthy' => $healthy,
            'jobs' => $jobs,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function readLedger(CarbonInterface $at): array
    {
        $path = self::ledgerPath($at);
        if (!is_file($path)) {
            return [];
        }

        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                throw new RuntimeException("Malformed scheduler evidence ledger: {$path}");
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @return array<string,mixed>|null */
    private static function summarizeOutput(string $job, string $output): ?array
    {
        if ($job === 'sessions-generate-forward' && preg_match(
            '/courses_planned=(\d+) courses_skipped=(\d+) slots_planned=(\d+) sessions_created=(\d+)/',
            $output,
            $matches
        )) {
            return [
                'courses_planned' => (int) $matches[1],
                'courses_skipped' => (int) $matches[2],
                'slots_planned' => (int) $matches[3],
                'sessions_created' => (int) $matches[4],
                'sha256' => hash('sha256', $output),
                'bytes' => strlen($output),
            ];
        }

        if ($job === 'ops-business-digest') {
            $metrics = [];
            foreach ([
                'revenue_at_risk_sessions',
                'revenue_at_risk_amount',
                'unpaid_active_courses',
                'retention_risk_students',
                'dq_attended_no_LR',
                'dq_cross_sc_dup',
                'dq_remaining_divergent',
                'coverage_next_7d',
            ] as $metric) {
                if (!preg_match('/^\|\s*' . preg_quote($metric, '/') . '\s*\|\s*(-?\d+(?:\.\d+)?)\s*\|/m', $output, $matches)) {
                    return null;
                }
                $metrics[$metric] = str_contains($matches[1], '.') ? (float) $matches[1] : (int) $matches[1];
            }

            return $metrics + [
                'sha256' => hash('sha256', $output),
                'bytes' => strlen($output),
            ];
        }

        if ($job === 'reconcile-nightly' && preg_match('/Checked:\s*(\d+) courses \| Mismatches:\s*(\d+)/', $output, $matches)) {
            return ['checked_courses' => (int) $matches[1], 'mismatch_count' => (int) $matches[2]];
        }

        $countPatterns = [
            'student-signin-close-orphans' => '/Closed\s+(\d+) orphan StudentSignIn record/',
            'teacher-signin-close-orphans' => '/Closed\s+(\d+) orphan TeacherSingIn record/',
            'rfid-prune-pending' => '/Deleted\s+(\d+) pending swipes/',
            'learning-records-backfill-missing' => '/total created:\s*(\d+)/',
        ];
        if (isset($countPatterns[$job]) && preg_match($countPatterns[$job], $output, $matches)) {
            return ['affected_rows' => (int) $matches[1]];
        }

        if ($job === 'learning-records-drift-check' && preg_match('/Drift counts:\s*(\{.+\})/', $output, $matches)) {
            $counts = json_decode($matches[1], true);
            return is_array($counts) ? ['remaining_counts' => $counts] : null;
        }

        if ($job === 'sessions-audit-stranded') {
            $decoded = json_decode(trim($output), true);
            if (is_array($decoded) && isset($decoded['stranded_courses'], $decoded['stranded_sessions'])) {
                return [
                    'as_of' => $decoded['as_of'] ?? null,
                    'stranded_courses' => (int) $decoded['stranded_courses'],
                    'stranded_sessions' => (int) $decoded['stranded_sessions'],
                ];
            }
        }

        if ($job === 'bugs-verify-reproductions') {
            $decoded = json_decode(trim($output), true);
            if (!is_array($decoded) || !isset($decoded['regressed'], $decoded['conditions']) || !is_array($decoded['conditions'])) {
                return null;
            }
            return [
                'regressed' => (int) $decoded['regressed'],
                'conditions' => array_map(static fn (array $condition): array => [
                    'key' => $condition['key'] ?? 'unknown',
                    'count' => (int) ($condition['count'] ?? 0),
                    'state' => $condition['state'] ?? 'unknown',
                ], $decoded['conditions']),
            ];
        }

        return null;
    }

    private static function ledgerDirectory(): string
    {
        return storage_path('logs/scheduler-evidence');
    }

    private static function outputDirectory(): string
    {
        return storage_path('logs/scheduler-output');
    }

    private static function localTime(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone(self::TIMEZONE);
    }

    private static function assertKnownJob(string $job): void
    {
        if (!isset(self::JOBS[$job])) {
            throw new \InvalidArgumentException("Unknown scheduler evidence job: {$job}");
        }
    }
}
