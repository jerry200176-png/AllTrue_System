<?php

namespace App\Services\TrueFit;

use InvalidArgumentException;

/**
 * Teacher Brief structured-data contract (Slice 1).
 * Canonical state is JSON fields — Markdown is render-only, never SSOT.
 */
final class TrueFitTeacherBriefContract
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const REQUIRED_KEYS = [
        'learning_objectives',
        'prior_knowledge',
        'hook',
        'analogy_or_representation',
        'prediction_questions',
        'expected_misconceptions',
        'hint_ladders',
        'teaching_moves_tied_to_material',
        'exit_ticket_plan',
    ];

    /**
     * @param array<string, mixed> $brief
     * @return array<string, mixed>
     */
    public function assertValid(array $brief): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $brief)) {
                throw new InvalidArgumentException("TeacherBrief missing key: {$key}");
            }
        }

        if (!is_array($brief['learning_objectives']) || $brief['learning_objectives'] === []) {
            throw new InvalidArgumentException('learning_objectives must be a non-empty array');
        }
        if (!is_string($brief['prior_knowledge']) || trim($brief['prior_knowledge']) === '') {
            throw new InvalidArgumentException('prior_knowledge must be a non-empty string');
        }
        if (!is_string($brief['hook']) || trim($brief['hook']) === '') {
            throw new InvalidArgumentException('hook must be a non-empty string');
        }
        if (!is_string($brief['analogy_or_representation'])) {
            throw new InvalidArgumentException('analogy_or_representation must be a string');
        }
        foreach (['prediction_questions', 'expected_misconceptions', 'hint_ladders', 'teaching_moves_tied_to_material'] as $listKey) {
            if (!is_array($brief[$listKey]) || $brief[$listKey] === []) {
                throw new InvalidArgumentException("{$listKey} must be a non-empty array");
            }
        }
        if (!is_array($brief['exit_ticket_plan']) || $brief['exit_ticket_plan'] === []) {
            throw new InvalidArgumentException('exit_ticket_plan must be a non-empty array');
        }

        return $brief;
    }
}
