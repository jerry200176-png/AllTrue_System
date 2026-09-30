<?php

namespace App\Support;

use App\Models\SecurityAuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Founder decision 5 (#2908): the actor may not approve/change pay or eligibility of themselves (no super_admin exemption). */
final class SelfApprovalGuard
{
    public static function reject(Request $request, mixed $subjectTeacherId): ?JsonResponse
    {
        $id = $request->attributes->get('auth_user_id') ?? $request->attributes->get('auth_user')?->id;
        $actor = $id !== null ? (int) $id : null;
        if ($actor === null || $subjectTeacherId === null || (int) $subjectTeacherId !== $actor) {
            return null;
        }
        SecurityAuditEvent::append('approval.self_blocked', 'denied', [
            'actor_type' => 'user', 'actor_id' => $actor,
            'subject_type' => 'teacher', 'subject_id' => $subjectTeacherId,
        ]);

        return response()->json(['message' => '不能核准自己的薪資／資格／堂數更正', 'code' => 'self_approval_forbidden'], 422);
    }
}
