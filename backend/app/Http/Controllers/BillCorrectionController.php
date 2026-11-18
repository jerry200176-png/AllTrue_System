<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillCorrection;
use Illuminate\Http\Request;

/**
 * POST invoices/{invoice}/correction/preview|apply (step 2 更正帳單). Director / super_admin only; the service checks the bill's
 * campus before any state and answers 404 unless the campus is in billing.bill_correction_campus_ids.
 */
final class BillCorrectionController extends Controller
{
    public function preview(Request $request, int $invoice, BillCorrection $service)
    {
        return $this->handle($request, $invoice, $service, false);
    }

    public function apply(Request $request, int $invoice, BillCorrection $service)
    {
        return $this->handle($request, $invoice, $service, true);
    }

    private function handle(Request $request, int $invoice, BillCorrection $service, bool $apply)
    {
        $role = (string) $request->attributes->get('auth_role');
        $actorId = (int) ($request->attributes->get('auth_user_id') ?? $request->attributes->get('auth_user')?->id);
        abort_unless($actorId > 0 && in_array($role, ['director', 'super_admin'], true), 403);
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'))]);
        }
        $data = $request->validate([
            'new_total' => 'nullable|integer|min:1|max:999999',
            'reason' => 'required|string|min:2|max:80|regex:/\S/u',
            'state_hash' => ($apply ? 'required' : 'nullable').'|string|size:64',
        ]);
        $actor = ['id' => $actorId, 'role' => $role, 'campus_ids' => array_map('intval', (array) $request->attributes->get('auth_campus_ids', []))];
        $result = $apply ? $service->apply($invoice, $data, $actor) : $service->preview($invoice, $data, $actor);

        return response()->json($result['body'], $result['status']);
    }
}
