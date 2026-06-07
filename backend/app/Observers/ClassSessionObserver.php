<?php

namespace App\Observers;

use App\Models\ClassSession;
use App\Models\ScheduleAuditLog;

class ClassSessionObserver
{
    /** @var array<int|string, array<string, mixed>> */
    private static array $oldSnapshots = [];

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
            return (int) optional($session->studentClass)->BranchID ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function created(ClassSession $session): void
    {
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
