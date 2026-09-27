<?php

namespace App\Services\TrueFit;

use InvalidArgumentException;

/**
 * Teacher Observation structured-data contract (Slice 2).
 * Canonical state is JSON fields — free-text notes are not SSOT alone.
 */
final class TrueFitTeacherObservationContract
{
    public const SCHEMA_VERSION = 'truefit.observation.v1';

    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'schema_version',
        'session_ref',
        'observed_at',
        'objectives_touched',
        'student_moves',
        'struggle_signals',
        'strength_signals',
        'misconception_hypotheses',
        'evidence_notes',
        'confidence',
    ];

    /** @var list<string> */
    public const CONFIDENCE_VALUES = ['low', 'medium', 'high'];

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>
     */
    public function assertValid(array $observation): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $observation)) {
                throw new InvalidArgumentException("TeacherObservation missing key: {$key}");
            }
        }

        if ((string) $observation['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('schema_version must be ' . self::SCHEMA_VERSION);
        }

        if (!is_array($observation['session_ref']) || $observation['session_ref'] === []) {
            throw new InvalidArgumentException('session_ref must be a non-empty object');
        }

        if (!is_string($observation['observed_at']) || trim($observation['observed_at']) === '') {
            throw new InvalidArgumentException('observed_at must be a non-empty string');
        }

        foreach (['objectives_touched', 'student_moves', 'struggle_signals', 'strength_signals', 'misconception_hypotheses'] as $listKey) {
            if (!is_array($observation[$listKey])) {
                throw new InvalidArgumentException("{$listKey} must be an array");
            }
        }

        if (!is_string($observation['evidence_notes'])) {
            throw new InvalidArgumentException('evidence_notes must be a string');
        }

        $confidence = (string) $observation['confidence'];
        if (!in_array($confidence, self::CONFIDENCE_VALUES, true)) {
            throw new InvalidArgumentException('confidence must be low|medium|high');
        }

        foreach ($observation['struggle_signals'] as $idx => $row) {
            if (!is_array($row) || !isset($row['label'], $row['signal'], $row['linked_objective'])) {
                throw new InvalidArgumentException("struggle_signals[{$idx}] must include label, signal, linked_objective");
            }
        }

        foreach ($observation['misconception_hypotheses'] as $idx => $row) {
            if (!is_array($row) || !isset($row['label'], $row['what_student_seemed_to_believe'], $row['what_to_check_next'])) {
                throw new InvalidArgumentException("misconception_hypotheses[{$idx}] must include required fields");
            }
        }

        return $observation;
    }
}
