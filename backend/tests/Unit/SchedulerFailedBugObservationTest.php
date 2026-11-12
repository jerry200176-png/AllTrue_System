<?php

namespace Tests\Unit;

use App\Support\SchedulerEvidence;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** #5115: failed work stays failed while safe diagnostic counts remain available. */
class SchedulerFailedBugObservationTest extends TestCase
{
    private const JOB = 'bugs-verify-reproductions';
    private const PRIVATE_TEXT = 'SYNTHETIC_PRIVATE_TEXT_MUST_NOT_APPEAR';
    private const CODES = SchedulerEvidence::BUG_CONDITION_CODES; // the one list the command and the reader share

    private CarbonImmutable $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = CarbonImmutable::parse('2041-06-04 12:00:00', SchedulerEvidence::TIMEZONE);
        $this->cleanArtifacts();
        SchedulerEvidence::ensureDirectories();
    }

    protected function tearDown(): void
    {
        $this->cleanArtifacts();
        parent::tearDown();
    }

    public function test_failed_check_exposes_only_known_counts_and_keeps_raw_output_and_failure(): void
    {
        $raw = json_encode(self::payload(), JSON_THROW_ON_ERROR);
        $path = SchedulerEvidence::outputPath(self::JOB, $this->date);
        file_put_contents($path, $raw);
        SchedulerEvidence::recordCompletion(self::JOB, 'failed', $this->date);

        $summary = SchedulerEvidence::summarize($this->date->toDateString(), $this->date);
        $job = $summary['jobs'][self::JOB];
        $this->assertSame('failed', $job['status']);
        $this->assertSame('failed', $job['run_status']);
        $this->assertSame('failed', $job['legacy_status']);
        $this->assertFalse($summary['execution_healthy']);
        $this->assertSame(1, $summary['critical_jobs']['failed']);
        $this->assertFalse($job['zero_work']);
        $this->assertNull($job['processed_count']);
        $this->assertNotNull($job['observed_result']);
        $this->assertSame(1, $job['observed_result']['regressed']);
        $this->assertCount(count(self::CODES), $job['observed_result']['conditions']);
        $this->assertSame(['key' => self::CODES[0], 'count' => 2, 'state' => 'REGRESSED'], $job['observed_result']['conditions'][0]);
        $this->assertEqualsCanonicalizing(
            ['REGRESSED', 'FIXED-OK', 'OPEN', 'OPEN-CLEARED', 'BASELINE-PENDING', 'WITHIN-BASELINE', 'INCOMPLETE'],
            array_unique(array_column($job['observed_result']['conditions'], 'state'))
        );
        foreach ($job['observed_result']['conditions'] as $condition) {
            $this->assertSame(['key', 'count', 'state'], array_keys($condition));
        }
        $this->assertStringNotContainsString(self::PRIVATE_TEXT, json_encode($summary));
        $this->assertSame($raw, file_get_contents($path));
    }

    public function test_zero_regressions_in_failed_output_do_not_turn_a_failed_run_green(): void
    {
        $payload = self::payload();
        $payload['regressed'] = 0;
        $payload['conditions'][0]['count'] = 0;
        $payload['conditions'][0]['state'] = 'FIXED-OK';
        $job = $this->observe(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertNotNull($job['observed_result']);
        $this->assertSame(0, $job['observed_result']['regressed']);
        $this->assertSame('failed', $job['run_status']);
        $this->assertFalse($job['zero_work']);
    }

    public function test_complete_output_at_the_byte_limit_is_still_available(): void
    {
        $raw = json_encode(self::payload(), JSON_THROW_ON_ERROR);
        $job = $this->observe(str_pad($raw, 65536, ' '));
        $this->assertNotNull($job['observed_result']);
        $this->assertSame(1, $job['observed_result']['regressed']);
        $this->assertSame('failed', $job['run_status']);
    }

    #[DataProvider('invalidOutputs')]
    public function test_unavailable_or_unsafe_output_stays_private_and_failed(?string $raw): void
    {
        $job = $this->observe($raw);
        $this->assertSame('failed', $job['status']);
        $this->assertNull($job['observed_result']);
        $this->assertFalse($job['zero_work']);
        $this->assertStringNotContainsString(self::PRIVATE_TEXT, json_encode($job));
    }

    public function test_output_symlink_is_not_followed(): void
    {
        $private = tempnam(sys_get_temp_dir(), 'scheduler-private-');
        try {
            file_put_contents($private, json_encode(self::payload(), JSON_THROW_ON_ERROR));
            symlink($private, SchedulerEvidence::outputPath(self::JOB, $this->date));
            $job = $this->observe(null);
            $this->assertNull($job['observed_result']);
            $this->assertSame('failed', $job['run_status']);
        } finally {
            unlink($private);
        }
    }

    public function test_other_failed_jobs_do_not_gain_an_output_reader(): void
    {
        $other = 'rfid-prune-pending';
        file_put_contents(SchedulerEvidence::outputPath($other, $this->date), json_encode(self::payload(), JSON_THROW_ON_ERROR));
        SchedulerEvidence::recordCompletion($other, 'failed', $this->date);
        $summary = SchedulerEvidence::summarize($this->date->toDateString(), $this->date);
        $this->assertNull($summary['jobs'][$other]['observed_result']);
        $this->assertSame('failed', $summary['jobs'][$other]['run_status']);
    }

    public static function invalidOutputs(): array
    {
        $valid = self::payload();
        $variants = [];
        foreach (['unknown-key', 'unknown-state', 'string-count', 'negative-count', 'float-count', 'boolean-count', 'duplicate', 'incomplete', 'inconsistent-regressed'] as $case) {
            $p = $valid;
            match ($case) {
                'unknown-key' => $p['conditions'][0]['key'] = self::PRIVATE_TEXT,
                'unknown-state' => $p['conditions'][0]['state'] = self::PRIVATE_TEXT,
                'string-count' => $p['conditions'][0]['count'] = '2',
                'negative-count' => $p['conditions'][0]['count'] = -1,
                'float-count' => $p['conditions'][0]['count'] = 1.5,
                'boolean-count' => $p['conditions'][0]['count'] = true,
                'duplicate' => $p['conditions'][9] = $p['conditions'][0],
                'incomplete' => array_pop($p['conditions']),
                'inconsistent-regressed' => $p['regressed'] = 0,
            };
            $variants[$case] = [json_encode($p, JSON_THROW_ON_ERROR)];
        }
        $variants['missing'] = [null];
        $variants['empty'] = [''];
        $variants['malformed'] = ['SQL failure: ' . self::PRIVATE_TEXT];
        $variants['oversized'] = [str_repeat(' ', 65536) . json_encode($valid, JSON_THROW_ON_ERROR)];
        $variants['wrong-shape'] = ['{"regressed":1,"conditions":[null]}'];
        $variants['string-regressed'] = [str_replace('"regressed":1', '"regressed":"1"', json_encode($valid, JSON_THROW_ON_ERROR))];
        foreach (['negative-regressed' => -1, 'too-many-regressed' => 11, 'boolean-regressed' => true, 'float-regressed' => 1.5, 'overflow-regressed' => PHP_INT_MAX + 1] as $name => $value) {
            $p = $valid; $p['regressed'] = $value;
            $variants[$name] = [json_encode($p, JSON_THROW_ON_ERROR)];
        }
        foreach (['key', 'count', 'state'] as $field) {
            $p = $valid; unset($p['conditions'][0][$field]);
            $variants['missing-' . $field] = [json_encode($p, JSON_THROW_ON_ERROR)];
        }
        $p = $valid; $p['conditions'][0]['count'] = PHP_INT_MAX + 1;
        $variants['overflow-count'] = [json_encode($p, JSON_THROW_ON_ERROR)];
        $p = $valid; $p['conditions'] = (object) $p['conditions'];
        $variants['numeric-object-not-list'] = [json_encode($p, JSON_THROW_ON_ERROR)];
        $p = $valid; $nested = self::PRIVATE_TEXT;
        for ($i = 0; $i < 40; $i++) $nested = ['nested' => $nested];
        $p['private'] = $nested;
        $variants['excessive-depth'] = [json_encode($p, JSON_THROW_ON_ERROR)];
        return $variants;
    }

    private function observe(?string $raw): array
    {
        if ($raw !== null) file_put_contents(SchedulerEvidence::outputPath(self::JOB, $this->date), $raw);
        SchedulerEvidence::recordCompletion(self::JOB, 'failed', $this->date);
        return SchedulerEvidence::summarize($this->date->toDateString(), $this->date)['jobs'][self::JOB];
    }

    private static function payload(): array
    {
        return ['regressed' => 1, 'private' => self::PRIVATE_TEXT, 'conditions' => array_map(
            static fn (string $key, int $i): array => ['key' => $key, 'count' => $i === 0 ? 2 : (in_array($i, [2, 7, 8], true) ? 1 : 0),
                'state' => match ($i) { 0 => 'REGRESSED', 2 => 'OPEN', 3, 4, 5 => 'OPEN-CLEARED',
                    7 => 'BASELINE-PENDING', 8 => 'WITHIN-BASELINE', 10 => 'INCOMPLETE', default => 'FIXED-OK' },
                'label' => self::PRIVATE_TEXT, 'student_id' => self::PRIVATE_TEXT],
            self::CODES, array_keys(self::CODES)
        )];
    }

    private function cleanArtifacts(): void
    {
        if (!isset($this->date)) return;
        @unlink(SchedulerEvidence::ledgerPath($this->date));
        foreach ([self::JOB, 'rfid-prune-pending'] as $job) @unlink(SchedulerEvidence::outputPath($job, $this->date));
    }
}
