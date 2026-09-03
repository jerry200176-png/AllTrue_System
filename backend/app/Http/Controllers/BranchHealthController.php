<?php

namespace App\Http\Controllers;

use App\Services\BranchHealthService;
use Illuminate\Http\Request;

/** Read-only HQ Branch Health V1. Role middleware is declared in api.php. */
class BranchHealthController extends Controller
{
    public function index(Request $request, BranchHealthService $health)
    {
        if ((string) $request->attributes->get('auth_role', '') !== 'super_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rawBranchId = $request->query('branch_id');
        $branchId = $rawBranchId === null ? null : (int) $rawBranchId;
        if ($rawBranchId !== null && $branchId <= 0) {
            return response()->json(['message' => 'branch_id must be a positive integer'], 422);
        }

        $payload = $health->board($branchId);
        if ($branchId !== null && count($payload['data']) === 0) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        return response()->json($payload);
    }
}
