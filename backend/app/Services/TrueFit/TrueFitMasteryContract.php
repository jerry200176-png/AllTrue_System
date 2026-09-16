<?php

namespace App\Services\TrueFit;

use InvalidArgumentException;

final class TrueFitMasteryContract
{
    public const SCHEMA_VERSION = 'truefit.mastery.v1';

    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'schema_version', 'session_ref', 'source_remediation_id', 'checked_at',
        'target_misconception_label', 'retrieval_prompt', 'student_response_summary',
        'outcome', 'evidence_notes', 'next_review_window', 'teacher_decision',
    ];

    /** @var list<string> */
    public const DECISION_VALUES = ['pending', 'accepted', 'edited', 'rejected'];

    /** @var list<string> */
    public const OUTCOME_VALUES = ['mastered', 'partial', 'not_yet', 'not_checked'];

    /** @var list<string> */
    public const WINDOW_VALUES = ['none', 'within_7_days', 'within_30_days'];

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public function assertValid(array $evidence): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $evidence)) {
                throw new InvalidArgumentException("Mastery missing key: {$key}");
            }
        }
        if ((string) $evidence['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('schema_version must be ' . self::SCHEMA_VERSION);
        }
        if (!is_array($evidence['session_ref']) || $evidence['session_ref'] === []) {
            throw new InvalidArgumentException('session_ref must be a non-empty object');
        }
        if ($evidence['source_remediation_id'] !== null && !is_int($evidence['source_remediation_id']) && !ctype_digit((string) $evidence['source_remediation_id'])) {
            throw new InvalidArgumentException('source_remediation_id must be int|null');
        }
        if (!is_string($evidence['checked_at']) || trim($evidence['checked_at']) === '') {
            throw new InvalidArgumentException('checked_at must be a non-empty string');
        }
        foreach (['target_misconception_label', 'retrieval_prompt', 'student_response_summary', 'evidence_notes'] as $strKey) {
            if (!is_string($evidence[$strKey])) {
                throw new InvalidArgumentException("{$strKey} must be a string");
            }
        }
        if (trim((string) $evidence['target_misconception_label']) === '') {
            throw new InvalidArgumentException('target_misconception_label must be a non-empty string');
        }
        if (trim((string) $evidence['retrieval_prompt']) === '') {
            throw new InvalidArgumentException('retrieval_prompt must be a non-empty string');
        }
        if (!in_array((string) $evidence['outcome'], self::OUTCOME_VALUES, true)) {
            throw new InvalidArgumentException('outcome invalid');
        }
        if (!in_array((string) $evidence['next_review_window'], self::WINDOW_VALUES, true)) {
            throw new InvalidArgumentException('next_review_window invalid');
        }
        if (!in_array((string) $evidence['teacher_decision'], self::DECISION_VALUES, true)) {
            throw new InvalidArgumentException('teacher_decision invalid');
        }

        return $evidence;
    }
}
