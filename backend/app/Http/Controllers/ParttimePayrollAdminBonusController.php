<?php

namespace App\Http\Controllers;

use App\Models\ParttimePayrollAdminBonus;
use App\Models\PayrollMonthStatus;
use App\Models\SecurityAuditEvent;
use App\Support\SelfApprovalGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** #4971 (in-app #508): one manual 行政加給 per part-time teacher per month. */
class ParttimePayrollAdminBonusController extends Controller
{
    /** PUT finance/parttime-payroll/admin-bonus — create or edit. */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'teacher_id' => 'required|integer',
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => 'required|integer|min:0|max:100000',
            'reason' => 'required|string|max:100',
        ]);
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            return response()->json(['error' => '請填寫加給原因'], 422);
        }
        if ($err = $this->guard($request, $data)) {
            return $err;
        }

        $uid = $this->userId($request);
        $row = null;
        $locked = DB::transaction(function () use ($data, $uid, &$row) {
            if ($this->monthLocked($data)) {
                return true;
            }
            $row = ParttimePayrollAdminBonus::where('campus_id', $data['branch_id'])
                ->where('teacher_id', $data['teacher_id'])->where('month', $data['month'])->lockForUpdate()->first();
            $old = $row?->amount;
            if ($row) {
                $row->update(['amount' => $data['amount'], 'reason' => $data['reason'], 'updated_by' => $uid]);
            } else {
                $row = ParttimePayrollAdminBonus::create([
                    'campus_id' => $data['branch_id'], 'teacher_id' => $data['teacher_id'], 'month' => $data['month'],
                    'amount' => $data['amount'], 'reason' => $data['reason'], 'created_by' => $uid, 'updated_by' => $uid,
                ]);
            }
            $this->audit($old === null ? 'created' : 'updated', $data, $uid, $old);

            return false;
        });
        if ($locked) {
            return $this->lockedResponse();
        }

        return response()->json(['ok' => true, 'admin_bonus' => $row->amount, 'admin_bonus_reason' => $row->reason]);
    }

    /** DELETE finance/parttime-payroll/admin-bonus */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'teacher_id' => 'required|integer',
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        if ($err = $this->guard($request, $data)) {
            return $err;
        }
        $locked = DB::transaction(function () use ($data, $request) {
            if ($this->monthLocked($data)) {
                return true;
            }
            $row = ParttimePayrollAdminBonus::where('campus_id', $data['branch_id'])
                ->where('teacher_id', $data['teacher_id'])->where('month', $data['month'])->lockForUpdate()->first();
            if ($row) {
                $row->delete();
                $this->audit('deleted', $data + ['reason' => $row->reason], $this->userId($request), $row->amount);
            }

            return false;
        });
        if ($locked) {
            return $this->lockedResponse();
        }

        return response()->json(['ok' => true]);
    }

    /** Campus scope + self-pay + part-time teacher. Returns an error response or null. */
    private function guard(Request $request, array $data): ?JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && !in_array((int) $data['branch_id'], array_map('intval', $campusIds), true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if ($blocked = SelfApprovalGuard::reject($request, (int) $data['teacher_id'])) {
            return $blocked;
        }
        $teacher = DB::table('User')->where('id', $data['teacher_id'])->first();
        $inCampus = DB::table('UserCampus')->where('UserID', $data['teacher_id'])->where('CampusID', $data['branch_id'])->exists();
        if (!$teacher || ($teacher->employment_type ?? 'full_time') !== 'part_time' || !$inCampus) {
            return response()->json(['error' => '只能替本分校的兼職老師登錄行政加給'], 422);
        }
        return null;
    }

    /** Take the month-status row lock (same row parttimePayrollLock() locks) and re-check; call inside a transaction. */
    private function monthLocked(array $data): bool
    {
        $row = PayrollMonthStatus::firstOrCreate(
            ['branch_id' => $data['branch_id'], 'month' => $data['month']],
            ['status' => 'draft']
        );
        $status = PayrollMonthStatus::whereKey($row->id)->lockForUpdate()->value('status');

        return in_array($status, ['locked', 'finalized'], true);
    }

    private function lockedResponse(): JsonResponse
    {
        return response()->json(['error' => '本月兼職薪資已鎖定，無法異動行政加給；如需修改請先請超級管理員重新開啟'], 422);
    }

    private function userId(Request $request): ?int
    {
        $u = $request->attributes->get('auth_user');

        return $u ? (int) $u->id : null;
    }

    private function audit(string $action, array $data, ?int $uid, ?int $old): void
    {
        SecurityAuditEvent::append('payroll.parttime_admin_bonus.' . $action, 'success', [
            'campus_id' => (int) $data['branch_id'],
            'actor_type' => 'user', 'actor_id' => $uid,
            'subject_type' => 'teacher', 'subject_id' => (int) $data['teacher_id'],
        ], [
            'amount' => (int) ($data['amount'] ?? $old ?? 0),
            'old_charge' => $old ?? 0,
            'reason_hash' => substr(hash('sha256', (string) ($data['reason'] ?? '')), 0, 16),
            'source' => $data['month'],
        ], true);
    }
}
