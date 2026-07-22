<?php

namespace App\Http\Controllers;

use App\Services\ActionInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionInboxController extends Controller
{
    private const NO_STORE = 'private, no-store';

    public function index(Request $request, ActionInboxService $service)
    {
        $request->validate([
            'branch_id' => 'nullable|integer|min:1',
            'lane' => 'nullable|in:ops,case',
            'case_filter' => 'nullable|in:unresolved,all,overdue,due_soon,candidate_ready,waiting,open',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.ActionInboxService::MAX_PER_PAGE,
            'ops_page' => 'nullable|integer|min:1',
            'ops_per_page' => 'nullable|integer|min:1|max:'.ActionInboxService::OPS_MAX_PER_PAGE,
        ]);

        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $this->withNoStore($scope);
        }

        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return $this->withNoStore(response()->json(['message' => 'Unauthorized'], 401));
        }

        $lane = $request->input('lane');
        $result = $service->list(
            $scope,
            $userId,
            $lane ? (string) $lane : null,
            (int) $request->input('page', 1),
            (int) $request->input('per_page', ActionInboxService::DEFAULT_PER_PAGE),
            $request->input('case_filter') ? (string) $request->input('case_filter') : null,
            (int) $request->input('ops_page', 1),
            (int) $request->input('ops_per_page', ActionInboxService::OPS_DEFAULT_PER_PAGE)
        );

        return $this->withNoStore(response()->json($result));
    }

    public function count(Request $request, ActionInboxService $service)
    {
        $request->validate([
            'branch_id' => 'nullable|integer|min:1',
        ]);

        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $this->withNoStore($scope);
        }

        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return $this->withNoStore(response()->json(['message' => 'Unauthorized'], 401));
        }

        return $this->withNoStore(response()->json($service->count($scope, $userId)));
    }

    /**
     * Deep-link: load one case DTO by workflow id under the same campus scope as list/count.
     */
    public function showCase(Request $request, ActionInboxService $service, int $id)
    {
        $request->validate([
            'branch_id' => 'nullable|integer|min:1',
        ]);

        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $this->withNoStore($scope);
        }

        $userId = $this->resolveAuthUserId($request);
        if (!$userId) {
            return $this->withNoStore(response()->json(['message' => 'Unauthorized'], 401));
        }

        $item = $service->getCase($scope, $id);
        if ($item === null) {
            return $this->withNoStore(response()->json([
                'message' => 'Case not found or not in authorized scope',
                'error_code' => 'case_not_found',
            ], 404));
        }

        return $this->withNoStore(response()->json(['data' => $item]));
    }

    /**
     * Fail-closed campus scope.
     *
     * - super_admin without branch_id → mode=all
     * - super_admin with branch_id → mode=ids [branch]
     * - non-super with zero auth campuses → 403
     * - non-super without branch_id → mode=ids auth_campus_ids
     * - non-super with unauthorized branch_id → 403
     *
     * Empty campus_ids NEVER means "all" except mode=all for super_admin.
     *
     * @return array{mode: string, campus_ids: array<int>}|JsonResponse
     */
    private function resolveCampusScope(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $isSuperAdmin = $role === 'super_admin';
        $authCampusIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])),
            fn ($id) => $id > 0
        )));

        $branchRaw = $request->input('branch_id');
        $hasBranch = $branchRaw !== null && $branchRaw !== '';
        $branchId = $hasBranch ? (int) $branchRaw : 0;

        if ($isSuperAdmin) {
            if ($hasBranch) {
                if ($branchId <= 0) {
                    return response()->json(['message' => 'Invalid branch_id'], 422);
                }

                return ['mode' => 'ids', 'campus_ids' => [$branchId]];
            }

            return ['mode' => 'all', 'campus_ids' => []];
        }

        if ($authCampusIds === []) {
            return response()->json([
                'message' => 'Forbidden: no authorized campuses',
                'error_code' => 'no_authorized_campuses',
            ], 403);
        }

        if ($hasBranch) {
            if ($branchId <= 0) {
                return response()->json(['message' => 'Invalid branch_id'], 422);
            }
            if (!in_array($branchId, $authCampusIds, true)) {
                return response()->json([
                    'message' => 'Forbidden: unauthorized campus',
                    'error_code' => 'unauthorized_campus',
                ], 403);
            }

            return ['mode' => 'ids', 'campus_ids' => [$branchId]];
        }

        return ['mode' => 'ids', 'campus_ids' => $authCampusIds];
    }

    private function resolveAuthUserId(Request $request): ?int
    {
        $authUser = $request->attributes->get('auth_user');
        if ($authUser && isset($authUser->id)) {
            return (int) $authUser->id;
        }

        return null;
    }

    private function withNoStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', self::NO_STORE);

        return $response;
    }
}
