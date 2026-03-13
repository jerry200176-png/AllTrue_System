<?php

namespace App\Http\Controllers;

use App\Models\TempRfid;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TempRfidController extends Controller
{
    /**
     * GET /api/v1/temp-rfid?campus_id=1 或 ?branch_id=1
     * 回傳該分校最近 5 分鐘內的暫存 RFID
     */
    public function show(Request $request)
    {
        $campusId = $request->input('campus_id') ?? $request->input('branch_id');
        if (!$campusId) {
            return response()->json(['data' => null, 'error' => ['message' => '缺少 campus_id 或 branch_id']], 400);
        }
        $campusId = (int) $campusId;

        $row = TempRfid::where('CampusID', $campusId)->first();
        if (!$row) {
            return response()->json(['data' => null, 'error' => null]);
        }

        $createdAt = Carbon::parse($row->created_at);
        $expiresAt = $createdAt->copy()->addMinutes(TempRfid::VALID_MINUTES);
        if (now()->greaterThan($expiresAt)) {
            $row->delete();
            return response()->json(['data' => null, 'error' => null]);
        }

        return response()->json([
            'data' => [
                'rfid' => $row->RFID,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            'error' => null,
        ]);
    }

    /**
     * POST /api/v1/temp-rfid/consume
     * 取用後清除暫存（選用）
     */
    public function consume(Request $request)
    {
        $campusId = $request->input('campus_id') ?? $request->input('branch_id');
        if (!$campusId) {
            return response()->json(['data' => null, 'error' => ['message' => '缺少 campus_id 或 branch_id']], 400);
        }
        TempRfid::where('CampusID', (int) $campusId)->delete();
        return response()->json(['data' => ['success' => true], 'error' => null]);
    }
}
