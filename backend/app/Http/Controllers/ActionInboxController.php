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
            return $this->noStore($scope);
        }
        $userId = $this->authUserId($request);
        if (!$userId) {
            return $this->noStore(response()->json(['message' => 'Unauthorized'], 401));
        }
        $lane = $request->input('lane');
        return $this->noStore(response()->json($service->list(
            $scope,
            $userId,
            $lane ? (string) $lane : null,
            (int) $request->input('page', 1),
            (int) $request->input('per_page', ActionInboxService::DEFAULT_PER_PAGE),
            $request->input('case_filter') ? (string) $request->input('case_filter') : null,
            (int) $request->input('ops_page', 1),
            (int) $request->input('ops_per_page', ActionInboxService::OPS_DEFAULT_PER_PAGE)
        )));
    }
    public function count(Request $request, ActionInboxService $service)
    {
        $request->validate(['branch_id' => 'nullable|integer|min:1']);
        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $this->noStore($scope);
        }
        $userId = $this->authUserId($request);
        if (!$userId) {
            return $this->noStore(response()->json(['message' => 'Unauthorized'], 401));
        }
        return $this->noStore(response()->json($service->count($scope, $userId)));
    }
    public function showCase(Request $request, ActionInboxService $service, int $id)
    {
        $request->validate(['branch_id' => 'nullable|integer|min:1']);
        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $this->noStore($scope);
        }
        if (!$this->authUserId($request)) {
            return $this->noStore(response()->json(['message' => 'Unauthorized'], 401));
        }
        $item = $service->getCase($scope, $id);
        if ($item === null) {
            return $this->noStore(response()->json([
                'message' => 'Case not found or not in authorized scope',
                'error_code' => 'case_not_found',
            ], 404));
        }
        return $this->noStore(response()->json(['data' => $item]));
    }
    /**
     * Fail-closed: empty campus_ids never means "all" except super_admin mode=all.
     *
     * @return array{mode: string, campus_ids: array<int>}|JsonResponse
     */
    private function resolveCampusScope(Request $request)
    {
        $isSuper = $request->attributes->get('auth_role') === 'super_admin';
        $auth = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])),
            fn ($id) => $id > 0
        )));
        $raw = $request->input('branch_id');
        $hasBranch = $raw !== null && $raw !== '';
        $branchId = $hasBranch ? (int) $raw : 0;
        if ($isSuper) {
            if (!$hasBranch) {
                return ['mode' => 'all', 'campus_ids' => []];
            }
            if ($branchId <= 0) {
                return response()->json(['message' => 'Invalid branch_id'], 422);
            }
            return ['mode' => 'ids', 'campus_ids' => [$branchId]];
        }
        if ($auth === []) {
            return response()->json([
                'message' => 'Forbidden: no authorized campuses',
                'error_code' => 'no_authorized_campuses',
            ], 403);
        }
        if ($hasBranch) {
            if ($branchId <= 0) {
                return response()->json(['message' => 'Invalid branch_id'], 422);
            }
            if (!in_array($branchId, $auth, true)) {
                return response()->json([
                    'message' => 'Forbidden: unauthorized campus',
                    'error_code' => 'unauthorized_campus',
                ], 403);
            }
            return ['mode' => 'ids', 'campus_ids' => [$branchId]];
        }
        return ['mode' => 'ids', 'campus_ids' => $auth];
    }
    private function authUserId(Request $request): ?int
    {
        $u = $request->attributes->get('auth_user');
        return ($u && isset($u->id)) ? (int) $u->id : null;
    }
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', self::NO_STORE);
        return $response;
    }
}
