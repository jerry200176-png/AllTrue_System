<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A write would relabel a lesson's teacher away from the author of its approved assessment (in-app #499 / #4901).
 * Rendered as HTTP 409 `approved_assessment_teacher_locked`; batch passes catch it, skip the lesson and report it.
 */
class ApprovedAssessmentTeacherLockedException extends RuntimeException
{
    public function __construct(public readonly int $classSessionId, public readonly int $authorTeacherId, public readonly int $attemptedTeacherId)
    {
        $name = (string) (DB::table('User')->where('id', $authorTeacherId)->value('Name') ?? $authorTeacherId);
        parent::__construct("這堂已有 {$name} 老師寫的評量（已核准），老師不能改；要改請先處理評量");
    }

    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return ['message' => $this->getMessage(), 'code' => 'approved_assessment_teacher_locked'];
    }
}
