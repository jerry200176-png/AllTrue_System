<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $query = Room::query();

        // SEC R-10 (#979): constrain non-super_admin to their own campuses so
        // /rooms can never leak or list another campus's rooms.
        $scoped = $this->scopedCampusIds($request);
        if ($scoped !== null) {
            $query->whereIn('campus_id', $scoped ?: [-1]);
        }

        $requested = $request->input('branch_id') ?: $request->input('campus_id');
        if ($requested) {
            $query->where('campus_id', (int) $requested);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:64',
            'capacity'  => 'required|integer|min:1',
            'campus_id' => 'required|integer',
            'memo'      => 'nullable|string|max:512',
            'is_active' => 'nullable|boolean',
        ]);

        // SEC R-10 (#979): cannot create a room in a campus you do not own.
        $this->assertCampusAllowed($request, (int) $request->input('campus_id'));

        $data = $request->only(['name', 'capacity', 'campus_id', 'memo', 'is_active']);
        if (!array_key_exists('is_active', $data) || $data['is_active'] === null) {
            $data['is_active'] = true;
        }
        $room = Room::create($data);
        return response()->json($room, 201);
    }

    public function update(Request $request, Room $room)
    {
        // SEC R-10 (#979): cannot mutate a room outside your campus scope.
        $this->assertCampusAllowed($request, (int) $room->campus_id);

        $request->validate([
            'name'      => 'sometimes|string|max:64',
            'capacity'  => 'sometimes|integer|min:1',
            'memo'      => 'nullable|string|max:512',
            'is_active' => 'nullable|boolean',
        ]);

        $room->update($request->only(['name', 'capacity', 'memo', 'is_active']));
        return response()->json($room);
    }

    public function destroy(Request $request, Room $room)
    {
        // SEC R-10 (#979): cannot delete a room outside your campus scope.
        $this->assertCampusAllowed($request, (int) $room->campus_id);

        $room->delete();
        return response()->json(['message' => 'deleted']);
    }

    /**
     * Campus ids the current request is scoped to, or null for super_admin
     * (no restriction). Mirrors the pattern in AlertController::tuition.
     *
     * @return array<int>|null
     */
    private function scopedCampusIds(Request $request): ?array
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return null;
        }
        return array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
    }

    private function assertCampusAllowed(Request $request, int $campusId): void
    {
        $scoped = $this->scopedCampusIds($request);
        if ($scoped === null) {
            return; // super_admin
        }
        if (!in_array($campusId, $scoped, true)) {
            abort(403, 'Forbidden: campus not in your scope');
        }
    }
}
