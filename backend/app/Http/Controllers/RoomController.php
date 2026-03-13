<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $campusId = $request->input('branch_id') ?: $request->input('campus_id');
        $query = Room::query();
        if ($campusId) {
            $query->where('campus_id', $campusId);
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

        $data = $request->only(['name', 'capacity', 'campus_id', 'memo', 'is_active']);
        if (!array_key_exists('is_active', $data) || $data['is_active'] === null) {
            $data['is_active'] = true;
        }
        $room = Room::create($data);
        return response()->json($room, 201);
    }

    public function update(Request $request, Room $room)
    {
        $request->validate([
            'name'      => 'sometimes|string|max:64',
            'capacity'  => 'sometimes|integer|min:1',
            'memo'      => 'nullable|string|max:512',
            'is_active' => 'nullable|boolean',
        ]);

        $room->update($request->only(['name', 'capacity', 'memo', 'is_active']));
        return response()->json($room);
    }

    public function destroy(Room $room)
    {
        $room->delete();
        return response()->json(['message' => 'deleted']);
    }
}
