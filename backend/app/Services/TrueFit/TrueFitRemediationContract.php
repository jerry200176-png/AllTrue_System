<?php

namespace App\Services\TrueFit;

use InvalidArgumentException;

final class TrueFitRemediationContract
{
    public const SCHEMA_VERSION = 'truefit.remediation.v1';

    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'schema_version', 'session_ref', 'source_diagnosis_id', 'planned_at',
        'target_misconception_label', 'practice_moves', 'material_anchors',
        'success_criteria', 'follow_up_window', 'teacher_decision', 'teacher_notes',
    ];

    /** @var list<string> */
    public const DECISION_VALUES = ['pending', 'accepted', 'edited', 'rejected'];

    /** @var list<string> */
    public const WINDOW_VALUES = ['same_session', 'next_session', 'within_7_days'];

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function assertValid(array $plan): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $plan)) {
                throw new InvalidArgumentException("Remediation missing key: {$key}");
            }
        }
        if ((string) $plan['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('schema_version must be ' . self::SCHEMA_VERSION);
        }
        if (!is_array($plan['session_ref']) || $plan['session_ref'] === []) {
            throw new InvalidArgumentException('session_ref must be a non-empty object');
        }
        if ($plan['source_diagnosis_id'] !== null && !is_int($plan['source_diagnosis_id']) && !ctype_digit((string) $plan['source_diagnosis_id'])) {
            throw new InvalidArgumentException('source_diagnosis_id must be int|null');
        }
        if (!is_string($plan['planned_at']) || trim($plan['planned_at']) === '') {
            throw new InvalidArgumentException('planned_at must be a non-empty string');
        }
        if (!is_string($plan['target_misconception_label']) || trim($plan['target_misconception_label']) === '') {
            throw new InvalidArgumentException('target_misconception_label must be a non-empty string');
        }
        foreach (['practice_moves', 'material_anchors', 'success_criteria'] as $listKey) {
            if (!is_array($plan[$listKey]) || $plan[$listKey] === []) {
                throw new InvalidArgumentException("{$listKey} must be a non-empty array");
            }
        }
        if (!in_array((string) $plan['follow_up_window'], self::WINDOW_VALUES, true)) {
            throw new InvalidArgumentException('follow_up_window invalid');
        }
        if (!in_array((string) $plan['teacher_decision'], self::DECISION_VALUES, true)) {
            throw new InvalidArgumentException('teacher_decision invalid');
        }
        if (!is_string($plan['teacher_notes'])) {
            throw new InvalidArgumentException('teacher_notes must be a string');
        }
        return $plan;
    }
}
