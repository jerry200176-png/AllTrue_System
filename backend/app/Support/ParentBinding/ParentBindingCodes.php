<?php

namespace App\Support\ParentBinding;

/** Stable PB-00 reason/outcome/channel/method string contract. */
final class ParentBindingCodes
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';
    public const OUTCOME_NOOP = 'noop';

    public const CHANNEL_LINE = 'line';
    public const CHANNEL_PORTAL = 'parent_portal';

    public const METHOD_NAME = 'name';
    public const METHOD_STUDENT_ID = 'student_id';
    public const METHOD_UNKNOWN = 'unknown';

    public const STUDENT_NOT_FOUND = 'STUDENT_NOT_FOUND';
    public const CONTACT_PHONE_MISSING = 'CONTACT_PHONE_MISSING';
    public const PHONE_MISMATCH = 'PHONE_MISMATCH';
    public const AMBIGUOUS_MATCH = 'AMBIGUOUS_MATCH';
    public const CAMPUS_MISMATCH = 'CAMPUS_MISMATCH';
    public const ALREADY_BOUND = 'ALREADY_BOUND';
    public const INVALID_INPUT = 'INVALID_INPUT';
    public const AUTHORIZATION_DENIED = 'AUTHORIZATION_DENIED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public static function pb00Reasons(): array
    {
        return [
            self::STUDENT_NOT_FOUND, self::CONTACT_PHONE_MISSING, self::PHONE_MISMATCH,
            self::AMBIGUOUS_MATCH, self::CAMPUS_MISMATCH, self::ALREADY_BOUND,
            self::INVALID_INPUT, self::AUTHORIZATION_DENIED, self::INTERNAL_ERROR,
        ];
    }
}
