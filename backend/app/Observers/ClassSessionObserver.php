<?php

namespace App\Observers;

use App\Models\ClassSession;
use App\Models\ScheduleAuditLog;

class ClassSessionObserver
{
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
