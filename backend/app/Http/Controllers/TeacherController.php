<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $query = Teacher::query();
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if (!empty($campusIds)) {
            $query->whereIn('CampusID', $campusIds);
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where('T_Name', 'like', "%{$q}%");
        }

        $teachers = $query->where('Enable', 1)
            ->orderBy('T_Name')
            ->get(['id', 'T_Name', 'CampusID']);

        return response()->json($teachers);
    }
}
