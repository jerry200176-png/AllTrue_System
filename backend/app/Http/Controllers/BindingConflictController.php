<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditEvent;
use App\Services\ParentBinding\BindingConflictService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cross-campus LINE binding conflict management (Phase 2, P2-2).
 *
 * - GET  /api/v1/bindings/conflicts            → conflict list (campus-isolated)
 * - POST /api/v1/bindings/conflicts/{id}/resolve → keep/delete a specific binding
 *
 * Access: director + super_admin. Directors are campus-scoped (may only list
 * conflicts touching their campus and may only delete their own-campus binding);
 * super admins may keep one binding across campuses to settle a conflict.
 */
class BindingConflictController extends Controller
{
    public function __construct(private readonly BindingConflictService $conflicts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $campusIds = $this->scopedCampusIds($request);

        return response()->json(['conflicts' => $this->conflicts->findConflicts($campusIds)]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $role = (string) $request->attributes->get('auth_role');
        $validated = $this->validate($request, [
            'action'     => 'required|in:keep,delete',
            'binding_id' => 'required|integer|min:1',
        ]);
        $action = $validated['action'];
        $bindingId = (int) $validated['binding_id'];

        // Campus isolation (ADR-003 Rule 2): a director may never delete a
        // binding owned by another campus. `keep` settles a conflict by purging
        // cross-campus bindings, so it is reserved for super admins.
        if ($role === 'super_admin') {
            $scope = null;
        } else {
            $scope = $this->scopedCampusIds($request);
            if ($action !== 'delete') {
                throw ValidationException::withMessages(['action' => 'keep is only available to super admins']);
            }
        }

        try {
            $result = $action === 'keep'
                ? $this->conflicts->resolveKeep($id, $bindingId, $scope)
                : $this->conflicts->resolveDelete($id, $bindingId, $scope);
        } catch (\DomainException $e) {
            return response()->json(['message' => 'Forbidden'], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Binding not found for this student'], 404);
        }

        $this->logResolved($request, $id, $bindingId, $action, $result);

        return response()->json([
            'message' => $action === 'keep' ? '衝突已解決，已保留指定綁定並移除其餘跨校綁定' : '已解除指定綁定',
            'result'  => $result,
        ]);
    }

    /**
     * Resolve the campus scope for the operator.
     *
     * @return int[]|null  null = all campuses (super_admin only)
     */
    private function scopedCampusIds(Request $request): ?array
    {
        $role = (string) $request->attributes->get('auth_role');
        if ($role === 'super_admin') {
            return null;
        }

        return $request->attributes->get('auth_campus_ids', []);
    }

    /** @param array<string, mixed> $result */
    private function logResolved(Request $request, int $studentId, int $bindingId, string $action, array $result): void
    {
        try {
            SecurityAuditEvent::append('line.binding.conflict.resolved', 'success', [
                'campus_id'    => (int) ($result['campus_id'] ?? 0) ?: null,
                'subject_type' => 'student',
                'subject_id'   => $studentId,
                'binding_id'   => $bindingId,
                'actor_type'   => 'user',
                'actor_id'     => $request->attributes->get('auth_user_id'),
            ], [
                'method'      => 'director_api',
                'reason_code' => $action === 'keep' ? 'conflict_resolve_keep' : 'conflict_resolve_delete',
            ]);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('conflict.resolve.audit_failed', ['error' => $e->getMessage()]);
        }
    }
}
