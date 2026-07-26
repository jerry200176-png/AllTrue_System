<?php

namespace App\Enums;

/**
 * Internal parent-binding failure / noop reason codes (ADR Accepted).
 * Machine contract only — never parse Chinese copy for state.
 *
 * @see docs/architecture/PARENT_IDENTITY_TARGET_ARCHITECTURE.md
 * @see docs/product/parent-binding-implementation-issues/PB-00-observability.md
 */
enum ParentBindingReasonCode: string
{
    case StudentNotFound = 'STUDENT_NOT_FOUND';
    case ContactPhoneMissing = 'CONTACT_PHONE_MISSING';
    case PhoneMismatch = 'PHONE_MISMATCH';
    case AmbiguousMatch = 'AMBIGUOUS_MATCH';
    case CampusMismatch = 'CAMPUS_MISMATCH';
    case AlreadyBound = 'ALREADY_BOUND';
    case InvalidInput = 'INVALID_INPUT';
    case AuthorizationDenied = 'AUTHORIZATION_DENIED';
    case InternalError = 'INTERNAL_ERROR';

    /** Codes in scope for PB-00 legacy name/id+phone paths. */
    public static function pb00Legacy(): array
    {
        return [
            self::StudentNotFound,
            self::ContactPhoneMissing,
            self::PhoneMismatch,
            self::AmbiguousMatch,
            self::CampusMismatch,
            self::AlreadyBound,
            self::InvalidInput,
            self::AuthorizationDenied,
            self::InternalError,
        ];
    }
}
