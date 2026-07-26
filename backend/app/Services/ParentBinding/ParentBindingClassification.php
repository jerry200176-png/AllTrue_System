<?php

namespace App\Services\ParentBinding;

use App\Support\ParentBinding\ParentBindingCodes;

final class ParentBindingClassification
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $reasonCode = null,
        public readonly ?int $campusId = null,
        public readonly ?int $studentId = null,
        public readonly ?int $candidateCount = null,
        public readonly ?int $phoneMatchCount = null,
    ) {
    }

    public static function success(?int $campusId, ?int $studentId, ?int $candidates = null, ?int $matches = null): self
    {
        return new self(ParentBindingCodes::OUTCOME_SUCCESS, null, $campusId, $studentId, $candidates, $matches);
    }

    public static function failure(string $reason, ?int $campusId = null, ?int $studentId = null, ?int $candidates = null, ?int $matches = null): self
    {
        return new self(ParentBindingCodes::OUTCOME_FAILURE, $reason, $campusId, $studentId, $candidates, $matches);
    }

    public static function noop(string $reason, ?int $campusId = null, ?int $studentId = null, ?int $candidates = null, ?int $matches = null): self
    {
        return new self(ParentBindingCodes::OUTCOME_NOOP, $reason, $campusId, $studentId, $candidates, $matches);
    }
}
