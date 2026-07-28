<?php

namespace App\Observers;

use App\Models\ClassSession;
use App\Models\ScheduleAuditLog;
use App\Services\ContractScheduleMatcher;

class ClassSessionObserver
{
    public function __construct(private ContractScheduleMatcher $contractScheduleMatcher)
    {
    }

    /** @var array<int|string, array<string, mixed>> */
    private static array $oldSnapshots = [];

    /**
     * Notes that mark a session as system-generated (lazy calendar projection
     * or backfill), not an explicit human schedule action. These are created in
     * bulk on hot read paths, so auditing them is both noise and an N+1 source.
     *
     * @var string[]
     */
    private const SYSTEM_NOTE_MARKERS = [
        'projected-monthly-materialized',
        'auto-created by backfill',
        'auto-projected by backfill',
        'backfill-from-schedules',
    ];

    private function isSystemGenerated(ClassSession $session): bool
    {
        $note = (string) ($session->Note ?? '');
        if ($note === '') {
            return false;
        }
        foreach (self::SYSTEM_NOTE_MARKERS as $marker) {
            if (str_contains($note, $marker)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private function sessionSnapshot(ClassSession $session): array
    {
        return $session->only([
            'id', 'StudentClassID', 'SubjectID',
            'SessionDate', 'StartTime', 'EndTime',
            'Status', 'Note', 'IsContractException', 'session_charge',
        ]);
    }

    private function operatorId(): ?int
    {
        $user = request()->attributes->get('auth_user');
        return $user ? (int) $user->id : null;
    }

    private function branchId(ClassSession $session): ?int
    {
        try {
            // Campus/branch lives on Student.CampusID; reach it through
            // ClassSession -> StudentClass -> Student. StudentClass itself has
            // no branch column, so deriving from it directly always yields null
            // and would hide every log from branch-scoped directors.
            $student = optional($session->studentClass)->student;
            return (int) optional($student)->CampusID ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * R84 (F1/#556 structural fix): recompute IsContractException on every save
     * that moves a session's date/time, regardless of which controller/service
     * initiated it. Previously this depended on each write path remembering to
     * call a matcher explicitly — the atomic reschedule path forgot to, and the
     * bug came back the same way in at least two other places (substitute-undo
     * time restore, contract reflow) that were only "safe" because their current
     * caller happened to pre-filter. Centralizing it here removes that
     * per-call-site memory requirement entirely.
     *
     * Skipped when the caller explicitly assigned IsContractException in the
     * same write (e.g. ExceptionWorkflowController's makeup-slot confirmation
     * forces true regardless of contract match) — explicit intent wins.
     */
    public function saving(ClassSession $session): void
    {
        if ($session->isDirty('IsContractException')) {
            return;
        }
        if (!$session->isDirty(['SessionDate', 'StartTime', 'EndTime'])) {
            return;
        }
        $this->contractScheduleMatcher->applyExceptionFlag($session);
    }

    public function created(ClassSession $session): void
    {
        if ($this->isSystemGenerated($session)) {
            return;
        }

        ScheduleAuditLog::create([
            'session_id'  => $session->id,
            'action_type' => 'create',
            'description' => "建立課堂 #{$session->id}（{$session->SessionDate}）",
            'operator_id' => $this->operatorId(),
            'branch_id'   => $this->branchId($session),
            'old_data'    => null,
            'new_data'    => $this->sessionSnapshot($session),
        ]);
    }

    public function updating(ClassSession $session): void
    {
        $key = (string) $session->getKey();
        self::$oldSnapshots[$key] = $this->sessionSnapshot(
            (new ClassSession())->fill($session->getOriginal())
        );
    }

    public function updated(ClassSession $session): void
    {
        $key = (string) $session->getKey();
        $old = self::$oldSnapshots[$key] ?? null;
        unset(self::$oldSnapshots[$key]);

        ScheduleAuditLog::create([
            'session_id'  => $session->id,
            'action_type' => 'update',
            'description' => "更新課堂 #{$session->id}（{$session->SessionDate}）",
            'operator_id' => $this->operatorId(),
            'branch_id'   => $this->branchId($session),
            'old_data'    => $old,
            'new_data'    => $this->sessionSnapshot($session),
        ]);
    }

    public function deleted(ClassSession $session): void
    {
        ScheduleAuditLog::create([
            'session_id'  => $session->id,
            'action_type' => 'delete',
            'description' => "刪除課堂 #{$session->id}（{$session->SessionDate}）",
            'operator_id' => $this->operatorId(),
            'branch_id'   => $this->branchId($session),
            'old_data'    => $this->sessionSnapshot($session),
            'new_data'    => null,
        ]);
    }
}
