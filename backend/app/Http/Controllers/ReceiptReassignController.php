<?php

namespace App\Http\Controllers;

use App\Services\Billing\ReceiptReassignService;
use Illuminate\Http\Request;

/**
 * #5113 (in-app #522), design PR 3a: read-only "move a receipt to the correct bill".
 *   GET  payment-reports/{id}/reassign-targets?student_id=
 *   POST payment-reports/{id}/reassign   (preview only; applying is PR 3b)
 * Route middleware limits both to director / super_admin; the service checks the campus before any state.
 */
final class ReceiptReassignController extends Controller
{
    public function __construct(private ReceiptReassignService $service)
    {
    }

    public function targets(Request $request, int $id)
    {
        $studentId = $request->query('student_id');
        if ($studentId !== null && (!ctype_digit((string) $studentId) || (int) $studentId < 1)) {
            return response()->json(['message' => '學生代號格式不正確', 'code' => 'reassign_bad_student'], 422);
        }
        $result = $this->service->targets($id, $studentId === null ? null : (int) $studentId, $this->campusIds($request));

        return response()->json($result['body'], $result['status']);
    }

    public function reassign(Request $request, int $id)
    {
        $data = $request->validate([
            'target_invoice_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
            'preview' => ['nullable', 'boolean'],
        ]);
        $result = $this->service->preview($id, (int) $data['target_invoice_id'], $this->campusIds($request),
            $request->attributes->get('auth_role') === 'super_admin', $data['reason'] ?? null);
        // PR 3a is read-only: a request to apply gets the same checks, then a named "not available yet".
        if ($result['status'] === 200 && !$request->boolean('preview')) {
            return response()->json(['message' => '移到正確帳單的功能還沒開放送出，目前只能先預覽。', 'code' => 'reassign_apply_unavailable'], 422);
        }

        return response()->json($result['body'], $result['status']);
    }

    /** @return list<int>|null null = super_admin */
    private function campusIds(Request $request): ?array
    {
        return $request->attributes->get('auth_role') === 'super_admin'
            ? null
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
    }
}
