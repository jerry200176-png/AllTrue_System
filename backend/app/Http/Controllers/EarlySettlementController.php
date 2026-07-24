<?php

namespace App\Http\Controllers;

use App\Models\StudentClass;
use App\Services\EarlySettlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EarlySettlementController extends Controller
{
    public function __construct(private EarlySettlementService $service)
    {
    }

    public function preview(Request $request, StudentClass $studentClass)
    {
        try {
            return response()->json($this->service->preview($studentClass));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: '不可提前結清',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function settle(Request $request, StudentClass $studentClass)
    {
        $data = $request->validate([
            'amount' => 'nullable|integer|min:0',
            'adjustment_reason' => 'nullable|string|max:500',
        ]);

        $actor = $request->attributes->get('auth_user');

        try {
            $result = $this->service->settle($studentClass, [
                'amount' => array_key_exists('amount', $data) ? $data['amount'] : null,
                'adjustment_reason' => $data['adjustment_reason'] ?? null,
                'actor_user_id' => (int) ($actor->id ?? 0),
            ]);

            return response()->json($result);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: '提前結清失敗',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
