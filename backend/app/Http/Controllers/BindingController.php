<?php

namespace App\Http\Controllers;

use App\Services\ParentBinding\BindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * W31 P1-2: Parent Binding management API.
 *
 * GET    /api/bindings              — list bindings
 * GET    /api/bindings/{id}         — single binding
 * POST   /api/bindings              — create binding
 * DELETE /api/bindings/{id}         — delete binding
 * GET    /api/bindings/conflicts    — cross-campus conflicts (P2-2)
 * GET    /api/bindings/metrics      — health metrics (P3-1)
 */
class BindingController extends Controller
{
    public function __construct(private readonly BindingService $bindingService) {}

    // ── P1-2: CRUD ─────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campus_id'   => ['sometimes', 'integer', 'min:1'],
            'student_id'  => ['sometimes', 'integer', 'min:1'],
            'line_user_id' => ['sometimes', 'string', 'max:64'],
            'limit'       => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $allowedCampusIds = $this->allowedCampusIds($request);
        if ($allowedCampusIds !== null
            && isset($validated['campus_id'])
            && !in_array((int) $validated['campus_id'], $allowedCampusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $bindings = $this->bindingService->listBindings(
            campusId: $validated['campus_id'] ?? null,
            studentId: $validated['student_id'] ?? null,
            lineUserId: $validated['line_user_id'] ?? null,
            limit: (int) ($validated['limit'] ?? 100),
            allowedCampusIds: $allowedCampusIds,
        );

        return response()->json(['bindings' => $bindings, 'count' => $bindings->count()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $binding = $this->bindingService->findBinding($id);
        if (!$binding) {
            return response()->json(['error' => 'Binding not found'], 404);
        }

        if (!$this->campusIsAllowed($request, (int) $binding->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['binding' => $binding]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id'    => ['required', 'integer', 'min:1'],
            'line_user_id'  => ['required', 'string', 'max:64'],
            'campus_id'     => ['required', 'integer', 'min:1'],
            'correlation_id' => ['sometimes', 'string', 'uuid'],
        ]);

        if (!$this->campusIsAllowed($request, (int) $validated['campus_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $this->bindingService->createBinding(
            studentId: (int) $validated['student_id'],
            lineUserId: $validated['line_user_id'],
            campusId: (int) $validated['campus_id'],
            correlationId: $validated['correlation_id'] ?? null,
        );

        $status = match ($result['status']) {
            'success' => 201,
            'noop'    => 200,
            default   => 422,
        };

        return response()->json($result, $status);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $binding = $this->bindingService->findBinding($id);
        if (!$binding) {
            return response()->json(['error' => 'Binding not found'], 404);
        }
        if (!$this->campusIsAllowed($request, (int) $binding->campus_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $deleted = $this->bindingService->deleteBinding($id);
        if (!$deleted) {
            return response()->json(['error' => 'Binding not found'], 404);
        }

        return response()->json(['status' => 'deleted', 'binding_id' => $id]);
    }

    // ── P2-2: Conflict listing ─────────────────────────────

    public function conflicts(): JsonResponse
    {
        $conflicts = $this->bindingService->findCrossCampusConflicts();

        // Group by line_user_id for structured output
        $grouped = $conflicts->groupBy('line_user_id')->map(function ($group) {
            $campuses = $group->pluck('campus_name')->unique()->values();
            return [
                'line_user_id' => $group->first()->line_user_id,
                'binding_ids' => $group->pluck('id')->values(),
                'campus_ids' => $group->pluck('campus_id')->unique()->values(),
                'campus_names' => $campuses,
                'student_names' => $group->pluck('student_name')->values(),
                'severity' => $campuses->count() > 2 ? 'high' : 'medium',
                'resolvable' => $group->contains(fn ($b) => $b->access_mode !== null),
            ];
        })->values();

        return response()->json([
            'conflicts' => $grouped,
            'conflict_count' => $grouped->count(),
            'total_binding_conflicts' => $conflicts->count(),
        ]);
    }

    // ── P3-1: Monitoring metrics ───────────────────────────

    public function metrics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campus_id' => ['sometimes', 'integer', 'min:1'],
            'days'      => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $allowedCampusIds = $this->allowedCampusIds($request);
        if ($allowedCampusIds !== null
            && isset($validated['campus_id'])
            && !in_array((int) $validated['campus_id'], $allowedCampusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $metrics = $this->bindingService->metrics(
            campusId: isset($validated['campus_id']) ? (int) $validated['campus_id'] : null,
            days: (int) ($validated['days'] ?? 7),
            allowedCampusIds: $allowedCampusIds,
        );

        return response()->json($metrics);
    }

    /** @return int[]|null null means super_admin may access all campuses. */
    private function allowedCampusIds(Request $request): ?array
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return null;
        }

        return collect($request->attributes->get('auth_campus_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function campusIsAllowed(Request $request, int $campusId): bool
    {
        $allowedCampusIds = $this->allowedCampusIds($request);

        return $allowedCampusIds === null || in_array($campusId, $allowedCampusIds, true);
    }
}
