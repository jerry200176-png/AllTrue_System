<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->where('type', 'T');
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if (!empty($campusIds)) {
            $query->whereIn('id', function ($sub) use ($campusIds) {
                $sub->select('UserID')->from('UserCampus')->whereIn('CampusID', $campusIds);
            });
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where('Name', 'like', "%{$q}%");
        }

        $campusByUser = DB::table('UserCampus')->pluck('CampusID', 'UserID');
        $teachers = $query->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->orderBy('Name')
            ->get(['id', 'Name'])
            ->map(fn ($user) => [
                'id' => (int) $user->id,
                'T_Name' => $user->Name,
                'CampusID' => isset($campusByUser[$user->id]) ? (int) $campusByUser[$user->id] : null,
            ]);

        return response()->json($teachers);
    }
}
