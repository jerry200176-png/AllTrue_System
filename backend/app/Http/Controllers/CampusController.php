<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CampusController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        $query = Campus::query()->select(['id', 'name', 'code']);

        // Filter by active flag when column exists
        if (Schema::hasColumn('Campus', 'active')) {
            $query->where('active', true);
        }

        // Super admin sees all active branches; others see only their assigned ones
        if ($role !== 'super_admin' && !empty($campusIds)) {
            $query->whereIn('id', $campusIds);
        }

        return response()->json($query->orderBy('id', 'asc')->get());
    }

    /**
     * GET /api/v1/branches (public, no auth required)
     * Returns all active campuses for director registration / branch selector.
     */
    public function listPublic()
    {
        try {
            $columns = ['id', 'name'];
            if (Schema::hasColumn('Campus', 'code')) {
                $columns[] = 'code';
            }

            $query = Campus::query()->select($columns);
            if (Schema::hasColumn('Campus', 'active')) {
                $query->where('active', true);
            }

            return response()->json($query->orderBy('id', 'asc')->get());
        } catch (\Throwable $e) {
            \Log::error('[CampusController::listPublic] ' . $e->getMessage());
            return response()->json([]);
        }
    }
}
