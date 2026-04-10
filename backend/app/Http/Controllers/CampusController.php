<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use Illuminate\Http\Request;

class CampusController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $request->attributes->get('auth_campus_ids', []);

        $query = Campus::query()->select(['id', 'name']);
        $query->whereIn('name', self::BRANCH_NAMES);

        // Super admin sees all configured branches; others see only their assigned ones
        if ($role !== 'super_admin' && !empty($campusIds)) {
            $query->whereIn('id', $campusIds);
        }

        $rows = $query->orderBy('id', 'asc')->get();
        $order = array_flip(self::BRANCH_NAMES);
        $rows = $rows->sortBy(fn ($r) => $order[$r->name] ?? 99)->values();

        return response()->json($rows);
    }

    /**
     * GET /api/v1/branches (public, no auth required)
     * Returns all campuses for director registration / branch selector.
     */
    /** 分校清單（依顯示順序） */
    private const BRANCH_NAMES = [
        '興隆分校',
        '新店分校',
        '大安分校',
        '木柵分校',
        '東湖分校',
        '大直分校',
        '汐止分校',
        '內湖分校',
    ];

    public function listPublic()
    {
        try {
            $columns = ['id', 'name'];
            if (\Illuminate\Support\Facades\Schema::hasColumn('Campus', 'code')) {
                $columns[] = 'code';
            }
            $rows = Campus::query()
                ->select($columns)
                ->whereIn('name', self::BRANCH_NAMES)
                ->get();
            $order = array_flip(self::BRANCH_NAMES);
            $rows = $rows->sortBy(fn ($r) => $order[$r->name] ?? 99)->values();

            return response()->json($rows);
        } catch (\Throwable $e) {
            \Log::error('[CampusController::listPublic] ' . $e->getMessage());
            // 後備：回傳預設八所分校
            return response()->json([
                ['id' => 1, 'name' => '興隆分校', 'code' => 'xinglong'],
                ['id' => 2, 'name' => '新店分校', 'code' => 'xindian'],
                ['id' => 3, 'name' => '大安分校', 'code' => 'daan'],
                ['id' => 4, 'name' => '木柵分校', 'code' => 'muzha'],
                ['id' => 5, 'name' => '東湖分校', 'code' => 'donghu'],
                ['id' => 6, 'name' => '大直分校', 'code' => 'dazhi'],
                ['id' => 7, 'name' => '汐止分校', 'code' => 'xizhi'],
                ['id' => 8, 'name' => '內湖分校', 'code' => 'neihu'],
            ]);
        }
    }
}
