<?php

namespace App\Observers;

use App\Models\ClassSession;
use App\Models\ScheduleAuditLog;
use Illuminate\Support\Facades\Request;

class ClassSessionObserver
{
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
        $user = Request::attributes()->get('auth_user');
        return $user ? (int) $user->id : null;
    }

    private function branchId(ClassSession $session): ?int
    {
        try {
            // StudentClass → Student → CampusID
            $sc = $session->studentClass()->with('student')->first();
            $campusId = $sc?->student?->CampusID;
            return $campusId ? (int) $campusId : null;
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
        // Snapshot original values before the UPDATE query runs.
        $session->_auditOldSnapshot = array_intersect_key(
            $session->getOriginal(),
            array_flip([
                'id', 'StudentClassID', 'SubjectID',
                'SessionDate', 'StartTime', 'EndTime',
                'Status', 'Note', 'IsContractException', 'session_charge',
            ])
        );
    }

    public function updated(ClassSession $session): void
    {
        ScheduleAuditLog::create([
            'session_id'  => $session->id,
            'action_type' => 'update',
            'description' => "更新課堂 #{$session->id}（{$session->SessionDate}）",
            'operator_id' => $this->operatorId(),
            'branch_id'   => $this->branchId($session),
            'old_data'    => $session->_auditOldSnapshot ?? null,
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
