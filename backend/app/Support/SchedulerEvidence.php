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
 *
 * Execution health is separate from reconciliation residuals (see summarize()).
 */
final class SchedulerEvidence
{
    public const TIMEZONE = 'Asia/Taipei';
    public const POP_EXECUTOR_JOB = 'pop-execute-approved';

    /** Execution outcomes that count as "ran successfully" for SLI. */
    public const EXECUTION_OK_STATUSES = [
        'succeeded',
        'succeeded_with_zero_work',
        'skipped_by_policy',
    ];

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
        'learning-records-void-stale-leave' => ['command' => 'learning-records:void-stale-leave', 'time' => '03:55'],
        'bugs-verify-reproductions' => ['command' => 'bugs:verify-reproductions --json', 'time' => '04:00'],
        'ops-business-digest' => ['command' => 'ops:business-digest', 'time' => '04:10'],
        'bindings-cleanup-orphans' => ['command' => 'bindings:cleanup-orphans', 'time' => '04:30'],
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

    public static function executorOutputPath(): string
    {
        return self::outputDirectory() . '/' . self::POP_EXECUTOR_JOB . '.log';
    }

    public static function recordExecutorCompletion(string $status, ?CarbonInterface $at = null): void
    {
        if (!in_array($status, ['succeeded', 'failed'], true)) {
            throw new \InvalidArgumentException("Unsupported POP executor evidence status: {$status}");
        }
        self::ensureDirectories();
        $local = self::localTime($at);
        $entry = [
            'schema_version' => 2,
            'job_key' => self::POP_EXECUTOR_JOB,
            'finished_at' => $local->toIso8601String(),
            'status' => $status,
            'exit_code' => $status === 'succeeded' ? 0 : 1,
            'output_file' => basename(self::executorOutputPath()),
            'evidence_timestamp' => $local->toIso8601String(),
        ];
        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents(self::ledgerDirectory() . '/pop-executor.jsonl', $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write POP executor evidence');
        }
    }

    public static function ledgerPath(?CarbonInterface $at = null): string
    {
        return self::ledgerDirectory() . '/' . self::localTime($at)->format('Y-m-d') . '.jsonl';
    }

    public static function recordCompletion(string $job, string $status, ?CarbonInterface $at = null): void
    {
        self::assertKnownJob($job);
        // Accept legacy success/failure plus Phase 1 vocabulary.
        $normalized = match ($status) {
            'success', 'succeeded' => 'succeeded',
            'failure', 'failed' => 'failed',
            default => $status,
        };
        if (!in_array($normalized, ['succeeded', 'failed', 'partial', 'timed_out', 'skipped_by_policy'], true)) {
            throw new \InvalidArgumentException("Unsupported scheduler evidence status: {$status}");
        }

        self::ensureDirectories();
        $local = self::localTime($at);
        $runId = sprintf('%s-%s-%s', $job, $local->format('Ymd-His'), bin2hex(random_bytes(4)));
        $entry = [
            'schema_version' => 2,
            'job_key' => $job,
            'job' => $job, // backward compatible
            'run_id' => $runId,
            'command' => self::JOBS[$job]['command'],
            'expected_schedule' => self::JOBS[$job]['time'],
            'scheduled_for' => $local->toDateString() . 'T' . self::JOBS[$job]['time'] . ':00+08:00',
            'timezone' => self::TIMEZONE,
            'started_at' => null,
            'finished_at' => $local->toIso8601String(),
            'completed_at' => $local->toIso8601String(),
            'status' => $normalized,
            'exit_code' => $normalized === 'succeeded' || $normalized === 'skipped_by_policy' ? 0 : 1,
            'output_file' => basename(self::outputPath($job, $local)),
            'evidence_timestamp' => $local->toIso8601String(),
            'application_sha' => null,
            'migration_version' => null,
            'error_class' => $normalized === 'failed' ? 'command_failure' : null,
            'safe_error_summary' => null,
        ];

        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents(self::ledgerPath($local), $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to write scheduler evidence for {$job}");
        }
    }

    /** @return array<string,mixed> */
    public static function summarize(string $date, ?CarbonInterface $now = null): array
    {
        $at = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
        $nowLocal = self::localTime($now);
        $entries = self::readLedger($at);
        $jobs = [];
        $executionHealthy = true;
        $counts = [
            'expected' => count(self::JOBS),
            'executed' => 0,
            'succeeded' => 0,
            'succeeded_with_zero_work' => 0,
            'partial' => 0,
            'failed' => 0,
            'stale' => 0,
            'no_run' => 0,
            'unknown' => 0,
        ];

        foreach (self::JOBS as $key => $definition) {
            $records = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => ($entry['job_key'] ?? $entry['job'] ?? null) === $key
            ));
            $outputPath = self::outputPath($key, $at);
            $expectedAt = $at->setTimeFromTimeString($definition['time'] . ':00');
            $pastDue = $nowLocal->greaterThan($expectedAt->addMinutes(30));
            $latestRecord = $records === [] ? null : $records[array_key_last($records)];
            $candidateTimestamp = is_array($latestRecord)
                ? ($latestRecord['completed_at'] ?? $latestRecord['finished_at'] ?? null)
                : null;
            $evidenceTimestamp = is_string($candidateTimestamp) ? $candidateTimestamp : null;

            $result = [
                'job_key' => $key,
                'expected_schedule' => $definition['time'],
                'executions' => count($records),
                'latest_execution' => $evidenceTimestamp,
                'evidence_timestamp' => $evidenceTimestamp,
                'evidence_age_seconds' => self::evidenceAgeSeconds($evidenceTimestamp, $nowLocal),
                'status' => 'succeeded',
                'run_status' => 'succeeded',
                'observed_result' => null,
                'processed_count' => null,
                'zero_work' => false,
            ];

            if (count($records) === 0) {
                $result['status'] = $pastDue ? 'no_run' : 'scheduled';
                $result['run_status'] = $result['status'];
                if ($pastDue) {
                    $counts['no_run']++;
                    $executionHealthy = false;
                }
            } elseif (count($records) > 1) {
                $result['status'] = 'partial';
                $result['run_status'] = 'partial';
                $counts['executed']++;
                $counts['partial']++;
                $executionHealthy = false;
            } else {
                $counts['executed']++;
                $ledgerStatus = $records[0]['status'] ?? 'unknown';
                if (in_array($ledgerStatus, ['failure', 'failed'], true)) {
                    $result['status'] = 'failed';
                    $result['run_status'] = 'failed';
                    $counts['failed']++;
                    $executionHealthy = false;
                } elseif ($ledgerStatus === 'partial') {
                    $result['status'] = 'partial';
                    $result['run_status'] = 'partial';
                    $counts['partial']++;
                    $executionHealthy = false;
                } elseif ($ledgerStatus === 'timed_out') {
                    $result['status'] = 'timed_out';
                    $result['run_status'] = 'timed_out';
                    $counts['failed']++;
                    $executionHealthy = false;
                } elseif ($ledgerStatus === 'skipped_by_policy') {
                    $result['status'] = 'skipped_by_policy';
                    $result['run_status'] = 'skipped_by_policy';
                    $counts['succeeded']++;
                } elseif (!is_file($outputPath) || filesize($outputPath) === 0) {
                    $result['status'] = 'unknown';
                    $result['run_status'] = 'unknown';
                    $counts['unknown']++;
                    $executionHealthy = false;
                } else {
                    $parsed = self::summarizeOutput($key, (string) file_get_contents($outputPath));
                    if ($parsed === null) {
                        $result['status'] = 'unknown';
                        $result['run_status'] = 'unknown';
                        $counts['unknown']++;
                        $executionHealthy = false;
                    } else {
                        $result['observed_result'] = $parsed;
                        $zero = self::isZeroWork($key, $parsed);
                        $result['zero_work'] = $zero;
                        $result['processed_count'] = self::extractProcessedCount($key, $parsed);
                        if ($zero) {
                            $result['status'] = 'succeeded_with_zero_work';
                            $result['run_status'] = 'succeeded_with_zero_work';
                            $counts['succeeded_with_zero_work']++;
                        } else {
                            $result['status'] = 'succeeded';
                            $result['run_status'] = 'succeeded';
                            $counts['succeeded']++;
                        }
                    }
                }
            }

            // Legacy alias used by older tests/docs
            if ($result['status'] === 'succeeded' || $result['status'] === 'succeeded_with_zero_work') {
                $result['legacy_status'] = 'verified';
            } elseif ($result['status'] === 'no_run') {
                $result['legacy_status'] = 'missing';
            } else {
                $result['legacy_status'] = $result['status'];
            }

            $jobs[$key] = $result;
        }

        $sli = [
            'execution_sli' => [
                'numerator' => $counts['executed'],
                'denominator' => $counts['expected'],
                'ratio' => round($counts['executed'] / max(1, $counts['expected']), 4),
            ],
            'success_sli' => [
                'numerator' => $counts['succeeded'] + $counts['succeeded_with_zero_work'],
                'denominator' => max($counts['executed'], 1),
                'ratio' => $counts['executed'] > 0
                    ? round(($counts['succeeded'] + $counts['succeeded_with_zero_work']) / $counts['executed'], 4)
                    : null,
            ],
            'evidence_freshness_seconds' => self::evidenceFreshnessSeconds($at, $nowLocal),
        ];

        return [
            'date' => $at->toDateString(),
            'timezone' => self::TIMEZONE,
            'observed_at' => $nowLocal->toIso8601String(),
            'execution_healthy' => $executionHealthy,
            // Phase 1: healthy means execution truth only — residuals are separate.
            'healthy' => $executionHealthy,
            'critical_jobs' => $counts,
            'sli' => $sli,
            'jobs' => $jobs,
        ];
    }

    /** @param array<string,mixed> $parsed */
    private static function isZeroWork(string $job, array $parsed): bool
    {
        $count = self::extractProcessedCount($job, $parsed);
        return $count === 0;
    }

    /** @param array<string,mixed> $parsed */
    private static function extractProcessedCount(string $job, array $parsed): ?int
    {
        if (isset($parsed['affected_rows'])) {
            return (int) $parsed['affected_rows'];
        }
        if (isset($parsed['sessions_created'])) {
            return (int) $parsed['sessions_created'];
        }
        if (isset($parsed['mismatch_count'])) {
            return (int) $parsed['mismatch_count'];
        }
        if (isset($parsed['stranded_sessions'])) {
            return (int) $parsed['stranded_sessions'];
        }
        if (isset($parsed['regressed'])) {
            return (int) $parsed['regressed'];
        }
        if ($job === 'ops-business-digest' && isset($parsed['revenue_at_risk_sessions'])) {
            return (int) $parsed['revenue_at_risk_sessions'];
        }
        if ($job === 'learning-records-drift-check' && isset($parsed['remaining_counts']) && is_array($parsed['remaining_counts'])) {
            return (int) array_sum($parsed['remaining_counts']);
        }

        return null;
    }

    private static function evidenceFreshnessSeconds(CarbonImmutable $date, CarbonImmutable $nowLocal): ?int
    {
        $path = self::ledgerPath($date);
        if (!is_file($path)) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false) {
            return null;
        }

        return max(0, $nowLocal->getTimestamp() - $mtime);
    }

    private static function evidenceAgeSeconds(?string $evidenceTimestamp, CarbonImmutable $nowLocal): ?int
    {
        if ($evidenceTimestamp === null) {
            return null;
        }

        try {
            return max(0, $nowLocal->getTimestamp() - CarbonImmutable::parse(
                $evidenceTimestamp,
                self::TIMEZONE
            )->setTimezone(self::TIMEZONE)->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
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
            $causeCounts = [];
            if (preg_match('/^Causes:\s*(\{.*\}|\[\])\s*$/m', $output, $causeMatches)) {
                $decoded = json_decode($causeMatches[1], true);
                if (!is_array($decoded)) {
                    return null;
                }
                foreach ($decoded as $category => $count) {
                    if (!is_string($category) || !is_numeric($count)) {
                        return null;
                    }
                    $causeCounts[$category] = (int) $count;
                }
            }

            return [
                'checked_courses' => (int) $matches[1],
                'mismatch_count' => (int) $matches[2],
                'cause_counts' => $causeCounts,
            ];
        }

        $countPatterns = [
            'student-signin-close-orphans' => '/Closed\s+(\d+) orphan StudentSignIn record/',
            'teacher-signin-close-orphans' => '/Closed\s+(\d+) orphan TeacherSingIn record/',
            'rfid-prune-pending' => '/Deleted\s+(\d+) pending swipes/',
            'learning-records-backfill-missing' => '/total created:\s*(\d+)/',
            'learning-records-void-stale-leave' => '/total voided:\s*(\d+)/',
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

        if ($job === 'bindings-cleanup-orphans' && preg_match(
            '/orphan_bindings_deleted=(\d+) anomaly_students=(\d+)/',
            $output,
            $matches
        )) {
            return [
                'orphan_bindings_deleted' => (int) $matches[1],
                'anomaly_students' => (int) $matches[2],
            ];
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
