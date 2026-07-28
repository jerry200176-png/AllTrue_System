<?php

namespace App\Services\Scheduling;

/**
 * ADR-006 §8.5 stable reason codes (Phase 0 / Phase 1 shared).
 */
final class CommitmentReasonCodes
{
    public const RULE_VERSION = 'adr006-phase0-v1';

    public const OK_PLAN = 'OK_PLAN';
    public const OK_EXISTS = 'OK_EXISTS';
    public const OK_PREVIEW_UNCOVERED = 'OK_PREVIEW_UNCOVERED';

    public const INFO_FLEXIBLE_NO_COMMITMENT = 'INFO_FLEXIBLE_NO_COMMITMENT';

    public const LEGACY_INFERRED_CANDIDATE = 'LEGACY_INFERRED_CANDIDATE';

    public const BLOCK_COMMITMENT_INCOMPLETE = 'BLOCK_COMMITMENT_INCOMPLETE';
    public const BLOCK_COMMITMENT_CONFLICT = 'BLOCK_COMMITMENT_CONFLICT';
    public const BLOCK_POOL_SHORTAGE = 'BLOCK_POOL_SHORTAGE';

    public const SKIP_NOT_COUNT_MODE = 'SKIP_NOT_COUNT_MODE';
    public const SKIP_STOPPED = 'SKIP_STOPPED';
    public const SKIP_DORMANT = 'SKIP_DORMANT';
    public const SKIP_MISSING_TEACHER = 'SKIP_MISSING_TEACHER';
    public const SKIP_MISSING_SUBJECT = 'SKIP_MISSING_SUBJECT';
    public const SKIP_MISSING_BRANCH = 'SKIP_MISSING_BRANCH';
    public const SKIP_MISSING_SLOT = 'SKIP_MISSING_SLOT';
    public const SKIP_AMBIGUOUS_POOL = 'SKIP_AMBIGUOUS_POOL';
    public const SKIP_CROSS_SC_CONFLICT = 'SKIP_CROSS_SC_CONFLICT';
    public const SKIP_TEACHER_CONFLICT = 'SKIP_TEACHER_CONFLICT';
    public const SKIP_OUT_OF_HORIZON = 'SKIP_OUT_OF_HORIZON';
    public const SKIP_PAST_END_DATE = 'SKIP_PAST_END_DATE';
    public const SKIP_REMAINING_CAP = 'SKIP_REMAINING_CAP';

    public const FAIL_BRANCH_ISOLATION = 'FAIL_BRANCH_ISOLATION';

    public const CLASS_EXPLICIT = 'explicit_commitment';
    public const CLASS_LEGACY = 'legacy_inferred_candidate';
    public const CLASS_CONFLICT = 'commitment_conflict';
    public const CLASS_INCOMPLETE = 'commitment_incomplete';
    public const CLASS_FLEXIBLE = 'flexible_no_commitment';
    public const CLASS_SKIPPED = 'skipped';
}
