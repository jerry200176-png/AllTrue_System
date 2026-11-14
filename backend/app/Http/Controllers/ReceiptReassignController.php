<?php

namespace App\Http\Controllers;

use App\Services\Billing\ReceiptReassignService;
use Illuminate\Http\Request;

/**
 * #5113 (in-app #522): "move a receipt to the correct bill".
 *   GET  payment-reports/{id}/reassign-targets?student_id=
 *   POST payment-reports/{id}/reassign   preview: true = read-only plan; otherwise apply (needs the preview's state_hash and a reason)
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
            'state_hash' => ['nullable', 'string', 'max:80'],
        ]);
        $superAdmin = $request->attributes->get('auth_role') === 'super_admin';
        $actorId = (int) ($request->attributes->get('auth_user_id') ?? $request->attributes->get('auth_user')?->id); // bearer auth sets only auth_user
        if (!$request->boolean('preview') && $actorId <= 0) {
            return response()->json(['message' => 'Forbidden'], 403); // a move always names who made it
        }
        $result = $request->boolean('preview')
            ? $this->service->preview($id, (int) $data['target_invoice_id'], $this->campusIds($request), $superAdmin, $data['reason'] ?? null)
            : $this->service->apply($id, (int) $data['target_invoice_id'], $this->campusIds($request), $superAdmin, $data['reason'] ?? null,
                $data['state_hash'] ?? null, $actorId);

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
