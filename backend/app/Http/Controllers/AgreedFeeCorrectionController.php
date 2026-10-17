<?php

namespace App\Http\Controllers;

use App\Models\StudentClass;
use App\Services\Billing\AgreedFeeCorrection;
use Illuminate\Http\Request;

final class AgreedFeeCorrectionController extends Controller
{
    public function preview(Request $request, StudentClass $studentClass, AgreedFeeCorrection $service)
    {
        return $this->handle($request, $studentClass, $service, false);
    }
    public function apply(Request $request, StudentClass $studentClass, AgreedFeeCorrection $service)
    {
        return $this->handle($request, $studentClass, $service, true);
    }
    private function handle(Request $request, StudentClass $course, AgreedFeeCorrection $service, bool $apply)
    {
        abort_unless(config('billing.agreed_fee_correction_enabled') === true, 404);
        $role = (string) $request->attributes->get('auth_role');
        $actorId = (int) $request->attributes->get('auth_user')?->id;
        abort_unless($actorId > 0, 403);
        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $campusId = (int) $course->student?->CampusID;
        abort_unless(in_array($role, ['director', 'super_admin'], true) && $campusId > 0
            && ($role === 'super_admin' || in_array($campusId, $campusIds, true)), 403);
        $request->merge(['reason' => trim((string) $request->input('reason'))]);
        $data = $request->validate([
            'scope' => 'required|in:billed,unbilled',
            'original_invoice_id' => 'nullable|required_if:scope,billed|prohibited_if:scope,unbilled|integer|min:1',
            'new_charge' => 'required|integer|min:1|max:99999999', 'reason' => 'required|string|min:3|max:80|regex:/\S/u',
            'idempotency_key' => 'required|uuid', 'preview_token' => ($apply ? 'required' : 'nullable').'|string|max:2048',
        ]);
        return response()->json($service->run((int) $course->ID, $data, ['id' => $actorId,
            'role' => $role, 'campus_ids' => $campusIds, 'student_id' => (int) $course->StudentID], $apply));
    }
}
