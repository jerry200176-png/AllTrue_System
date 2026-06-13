<?php

namespace App\Http\Controllers;

use App\Models\PartTimeRateCard;
use App\Models\UserCampus;
use Illuminate\Http\Request;

/**
 * CRUD for part-time teacher rate cards (#767).
 *
 * All endpoints require director/super_admin role.
 * Directors can only manage rate cards for their own branch(es).
 */
class PartTimeRateCardController extends Controller
{
    private function allowedBranches(Request $request): ?array
    {
        $role     = $request->attributes->get('auth_role');
        $operator = $request->attributes->get('auth_user');

        if ($role === 'super_admin') {
            return null; // unrestricted
        }

        return UserCampus::where('UserID', $operator->id)
            ->where('Approved', true)
            ->pluck('CampusID')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * GET /api/v1/part-time-rate-cards
     * Query params: teacher_id, branch_id, class_size, session_type, active_on (date)
     */
    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowed = $this->allowedBranches($request);
        $validated = $request->validate([
            'teacher_id'   => 'nullable|integer',
            'branch_id'    => 'nullable|integer',
            'class_size'   => 'nullable|in:1v1,1v2,1v3_plus',
            'session_type' => 'nullable|in:subject,tutoring,trial',
            'active_on'    => 'nullable|date',
        ]);

        $query = PartTimeRateCard::query()->orderBy('branch_id')->orderBy('user_id')->orderBy('effective_from', 'desc');

        if ($allowed !== null) {
            $query->whereIn('branch_id', $allowed);
        }
        if (!empty($validated['branch_id'])) {
            if ($allowed !== null && !in_array((int) $validated['branch_id'], $allowed)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('branch_id', $validated['branch_id']);
        }
        if (!empty($validated['teacher_id'])) {
            $query->where('user_id', $validated['teacher_id']);
        }
        if (!empty($validated['class_size'])) {
            $query->where('class_size', $validated['class_size']);
        }
        if (!empty($validated['session_type'])) {
            $query->where('session_type', $validated['session_type']);
        }
        if (!empty($validated['active_on'])) {
            $date = $validated['active_on'];
            $query->where('effective_from', '<=', $date)
                  ->where(function ($q) use ($date) {
                      $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
                  });
        }

        return response()->json($query->get());
    }

    /**
     * POST /api/v1/part-time-rate-cards
     */
    public function store(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'user_id'        => 'required|integer|exists:User,id',
            'branch_id'      => 'required|integer',
            'class_size'     => 'required|in:1v1,1v2,1v3_plus',
            'session_type'   => 'required|in:subject,tutoring,trial',
            'rate_per_30min' => 'required|numeric|min:0|max:99999.99',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        $allowed = $this->allowedBranches($request);
        if ($allowed !== null && !in_array((int) $data['branch_id'], $allowed)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $card = PartTimeRateCard::create($data);
        return response()->json($card, 201);
    }

    /**
     * PUT /api/v1/part-time-rate-cards/{id}
     */
    public function update(Request $request, int $id)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $card = PartTimeRateCard::findOrFail($id);

        $allowed = $this->allowedBranches($request);
        if ($allowed !== null && !in_array((int) $card->branch_id, $allowed)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'rate_per_30min' => 'sometimes|numeric|min:0|max:99999.99',
            'effective_from' => 'sometimes|date',
            'effective_until' => 'nullable|date',
            'class_size'     => 'sometimes|in:1v1,1v2,1v3_plus',
            'session_type'   => 'sometimes|in:subject,tutoring,trial',
        ]);

        $card->update($data);
        return response()->json($card);
    }

    /**
     * DELETE /api/v1/part-time-rate-cards/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $role = $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'admin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $card = PartTimeRateCard::findOrFail($id);

        $allowed = $this->allowedBranches($request);
        if ($allowed !== null && !in_array((int) $card->branch_id, $allowed)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $card->delete();
        return response()->json(['message' => '費率卡已刪除', 'id' => $id]);
    }
}
