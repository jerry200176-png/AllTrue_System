<?php

namespace App\Services\TrueFit;

use InvalidArgumentException;

/**
 * Misconception Diagnosis structured-data contract (Slice 3).
 */
final class TrueFitErrorDiagnosisContract
{
    public const SCHEMA_VERSION = 'truefit.diagnosis.v1';

    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'schema_version',
        'session_ref',
        'source_observation_id',
        'proposed_at',
        'primary_misconception',
        'supporting_signals',
        'ruled_out',
        'recommended_checks',
        'confidence',
        'teacher_decision',
        'teacher_notes',
    ];

    /** @var list<string> */
    public const CONFIDENCE_VALUES = ['low', 'medium', 'high'];

    /** @var list<string> */
    public const DECISION_VALUES = ['pending', 'accepted', 'edited', 'rejected'];

    /**
     * @param array<string, mixed> $diagnosis
     * @return array<string, mixed>
     */
    public function assertValid(array $diagnosis): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $diagnosis)) {
                throw new InvalidArgumentException("ErrorDiagnosis missing key: {$key}");
            }
        }

        if ((string) $diagnosis['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('schema_version must be ' . self::SCHEMA_VERSION);
        }

        if (!is_array($diagnosis['session_ref']) || $diagnosis['session_ref'] === []) {
            throw new InvalidArgumentException('session_ref must be a non-empty object');
        }

        if ($diagnosis['source_observation_id'] !== null && !is_int($diagnosis['source_observation_id']) && !ctype_digit((string) $diagnosis['source_observation_id'])) {
            throw new InvalidArgumentException('source_observation_id must be int|null');
        }

        if (!is_string($diagnosis['proposed_at']) || trim($diagnosis['proposed_at']) === '') {
            throw new InvalidArgumentException('proposed_at must be a non-empty string');
        }

        $primary = $diagnosis['primary_misconception'];
        if (!is_array($primary) || !isset($primary['label'], $primary['statement'], $primary['why_it_fits_observation'], $primary['linked_brief_objective'])) {
            throw new InvalidArgumentException('primary_misconception missing required fields');
        }

        foreach (['supporting_signals', 'ruled_out', 'recommended_checks'] as $listKey) {
            if (!is_array($diagnosis[$listKey])) {
                throw new InvalidArgumentException("{$listKey} must be an array");
            }
        }
        if ($diagnosis['recommended_checks'] === []) {
            throw new InvalidArgumentException('recommended_checks must be non-empty');
        }

        if (!in_array((string) $diagnosis['confidence'], self::CONFIDENCE_VALUES, true)) {
            throw new InvalidArgumentException('confidence must be low|medium|high');
        }
        if (!in_array((string) $diagnosis['teacher_decision'], self::DECISION_VALUES, true)) {
            throw new InvalidArgumentException('teacher_decision must be pending|accepted|edited|rejected');
        }
        if (!is_string($diagnosis['teacher_notes'])) {
            throw new InvalidArgumentException('teacher_notes must be a string');
        }

        return $diagnosis;
    }
}
