<?php

namespace App\Http\Controllers;

use App\Services\ActionInboxService;
use Illuminate\Http\Request;

class ActionInboxController extends Controller
{
    public function index(Request $request, ActionInboxService $service)
    {
        $request->validate([
            'branch_id' => 'nullable|integer',
            'lane' => 'nullable|in:ops,case',
        ]);

        [$campusIds, $forbidden] = $this->resolveCampusScope($request);
        if ($forbidden) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $lane = $request->input('lane');
        $result = $service->list($campusIds, $userId, $lane ? (string) $lane : null);

        return response()->json($result);
    }

    public function count(Request $request, ActionInboxService $service)
    {
        $request->validate([
            'branch_id' => 'nullable|integer',
        ]);

        [$campusIds, $forbidden] = $this->resolveCampusScope($request);
        if ($forbidden) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($service->count($campusIds, $userId));
    }

    /**
     * @return array{0: array<int>, 1: bool} [campusIds, forbidden]
     */
    private function resolveCampusScope(Request $request): array
    {
        $role = $request->attributes->get('auth_role');
        $isSuperAdmin = $role === 'super_admin';
        $campusIds = $isSuperAdmin
            ? []
            : array_map('intval', $request->attributes->get('auth_campus_ids', []));

        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId > 0) {
            if (!$isSuperAdmin && !in_array($branchId, $campusIds, true)) {
                return [[], true];
            }
            $campusIds = [$branchId];
        }

        return [$campusIds, false];
    }

    private function resolveAuthUserId(Request $request): ?int
    {
        $authUser = $request->attributes->get('auth_user');
        if ($authUser && isset($authUser->id)) {
            return (int) $authUser->id;
        }

        return null;
    }
}
