<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A write would relabel a lesson's teacher away from the author of its teacher-filled assessment (in-app #499 / #4901).
 * A 409 `approved_assessment_teacher_locked`; batch passes catch it, skip the lesson and report it.
 */
class ApprovedAssessmentTeacherLockedException extends ConflictHttpException
{
    public function __construct(public readonly int $classSessionId, public readonly int $authorTeacherId, string $authorName)
    {
        parent::__construct("這堂已有 {$authorName} 老師寫的評量，老師不能改；要改請先作廢評量");
    }

    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return ['message' => $this->getMessage(), 'code' => 'approved_assessment_teacher_locked'];
    }
}
