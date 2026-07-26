<?php

namespace App\Services\ParentBinding;

use App\Enums\ParentBindingOutcome;
use App\Enums\ParentBindingReasonCode;

/** Immutable classifier result for one parent bind/login attempt. */
final class ParentBindingClassification
{
    public function __construct(
        public readonly ParentBindingOutcome $outcome,
        public readonly ?ParentBindingReasonCode $reasonCode = null,
        public readonly ?int $campusId = null,
        public readonly ?int $studentId = null,
        public readonly ?int $candidateCount = null,
        public readonly ?int $phoneMatchCount = null,
    ) {
    }

    public static function success(?int $campusId, ?int $studentId, ?int $candidates = null, ?int $matches = null): self
    {
        return new self(ParentBindingOutcome::Success, null, $campusId, $studentId, $candidates, $matches);
    }

    public static function failure(ParentBindingReasonCode $reason, ?int $campusId = null, ?int $studentId = null, ?int $candidates = null, ?int $matches = null): self
    {
        return new self(ParentBindingOutcome::Failure, $reason, $campusId, $studentId, $candidates, $matches);
    }

    public static function noop(ParentBindingReasonCode $reason, ?int $campusId = null, ?int $studentId = null, ?int $candidates = null, ?int $matches = null): self
    {
        return new self(ParentBindingOutcome::Noop, $reason, $campusId, $studentId, $candidates, $matches);
    }
}
