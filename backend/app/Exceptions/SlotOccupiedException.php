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
        public readonly ?string $conflictSource = null,
        public readonly ?int $conflictCourseId = null,
        public readonly ?int $conflictScheduleId = null,
        string $message = '該時段已有課程，無法調課至此時段（請先取消原時段的課或改選其他時段）',
        public readonly string $responseCode = 'slot_occupied'
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

    public static function fromStudentConflict(
        int $courseId,
        string $sessionDate,
        string $startTime,
        ?int $conflictSessionId = null,
        ?string $conflictStatus = null,
        ?int $conflictCourseId = null,
    ): self {
        return new self(
            courseId: $courseId,
            sessionDate: $sessionDate,
            startTime: $startTime,
            conflictSessionId: $conflictSessionId,
            conflictStatus: $conflictStatus,
            conflictSource: 'class_session',
            conflictCourseId: $conflictCourseId,
            message: '學生在此時段已有其他課程，無法建立重疊課程（請改選其他日期／時間）',
            responseCode: 'student_slot_conflict',
        );
    }

    public static function fromStudentScheduleConflict(
        int $courseId,
        string $sessionDate,
        string $startTime,
        object $conflict,
    ): self {
        $status = $conflict->status ?? null;
        $scheduleId = (int) ($conflict->id ?? 0);
        $conflictCourseId = (int) ($conflict->student_course_id ?? 0);

        return new self(
            courseId: $courseId,
            sessionDate: $sessionDate,
            startTime: $startTime,
            conflictStatus: $status !== null ? (string) $status : null,
            conflictSource: 'schedule',
            conflictCourseId: $conflictCourseId > 0 ? $conflictCourseId : null,
            conflictScheduleId: $scheduleId > 0 ? $scheduleId : null,
            message: '學生在此時段已有其他預排課程，無法建立重疊課程（請改選其他日期／時間）',
            responseCode: 'student_slot_conflict',
        );
    }

    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->responseCode,
            'conflict_session_id' => $this->conflictSessionId,
            'conflict_status' => $this->conflictStatus,
            'conflict_source' => $this->conflictSource,
            'conflict_course_id' => $this->conflictCourseId,
            'conflict_schedule_id' => $this->conflictScheduleId,
            'session_date' => $this->sessionDate,
            'start_time' => substr($this->startTime, 0, 5),
        ];
    }
}
