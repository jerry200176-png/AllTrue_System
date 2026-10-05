<?php

namespace App\Services\Line;

use App\Models\SecurityAuditEvent;
use App\Models\StudentLineBinding;
use Illuminate\Support\Collection;

/**
 * "Push to a student's parents": who the parents are (verified, same-campus bindings) and the
 * delivery audit trail. How a message is actually sent (and what a failure means) stays with the
 * caller's $send closure, which should go through LinePush.
 */
class ParentLinePush
{
    /** @return Collection<int, StudentLineBinding> */
    public function bindings(int $studentId, int $campusId): Collection
    {
        return StudentLineBinding::query()->where('student_id', $studentId)
            ->whereNotNull('verified_at') // = scopeVerified()
            ->where('campus_id', $campusId)
            ->get();
    }

    /**
     * Send to each binding via $send(binding): bool, append one notification.delivery audit event each.
     *
     * @param  Collection<int, StudentLineBinding>  $bindings
     * @param  callable(StudentLineBinding): bool  $send
     * @return int number delivered
     */
    public function deliver(Collection $bindings, int $studentId, int $campusId, string $notificationType, callable $send): int
    {
        $sent = 0;
        foreach ($bindings as $binding) {
            $delivered = (bool) $send($binding);
            SecurityAuditEvent::append('notification.delivery', $delivered ? 'success' : 'failure', [
                'campus_id' => $campusId,
                'subject_type' => 'student',
                'subject_id' => $studentId,
                'binding_id' => $binding->getKey(),
            ], [
                'method' => 'line_push',
                'notification_type' => $notificationType,
                'delivery_status' => $delivered ? 'delivered' : 'failed',
                'binding_verified' => true,
            ]);
            if ($delivered) {
                $sent++;
            }
        }

        return $sent;
    }
}
