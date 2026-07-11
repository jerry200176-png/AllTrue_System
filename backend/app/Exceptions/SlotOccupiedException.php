<?php

namespace App\Exceptions;

use App\Models\ClassSession;
use RuntimeException;

/**
 * Thrown when an operation would move/create a ClassSession onto a slot that is
 * already held by another non-cancelled session for the same course, which the
 * active-only unique index `uq_class_session_slot` (#957 D1) rejects at the DB
 * layer with a raw 1062. Application code raises this instead so the director
 * receives an actionable 422 rather than an opaque 500.
 *
 * The exception handler renders this as HTTP 422 with `code: slot_occupied` for
 * JSON requests (single source of truth for the response shape).
 */
class SlotOccupiedException extends RuntimeException
{
    public function __construct(
        public readonly int $courseId,
        public readonly string $sessionDate,
        public readonly string $startTime,
        public readonly ?int $conflictSessionId = null,
        public readonly ?string $conflictStatus = null,
        string $message = '該時段已有課程，無法調課至此時段（請先取消原時段的課或改選其他時段）'
    ) {
        parent::__construct($message);
    }

    public static function fromConflict(int $courseId, string $sessionDate, string $startTime, ClassSession $conflict): self
    {
        // getAttribute (not ->Status) keeps PHPStan happy: Status is a dynamic
        // Eloquent column, not a declared property.
        $status = $conflict->getAttribute('Status');

        return new self(
            courseId: $courseId,
            sessionDate: $sessionDate,
            startTime: $startTime,
            conflictSessionId: (int) $conflict->getKey(),
            conflictStatus: $status !== null ? (string) $status : null,
        );
    }

    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => 'slot_occupied',
            'conflict_session_id' => $this->conflictSessionId,
            'conflict_status' => $this->conflictStatus,
            'session_date' => $this->sessionDate,
            'start_time' => substr($this->startTime, 0, 5),
        ];
    }
}
