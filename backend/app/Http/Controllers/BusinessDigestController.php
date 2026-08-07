<?php

namespace App\Http\Controllers;

use App\Services\BusinessDigestService;
use Illuminate\Http\Request;

/**
 * Super-admin, read-only business-intelligence surface for #1147 Phase 1
 * (director cockpit). Thin HTTP wrapper — all computation stays in
 * BusinessDigestService per ADR-003 (no DB:: in controllers).
 */
class BusinessDigestController extends Controller
{
    public function index(Request $request, BusinessDigestService $service)
    {
        $campusId = $request->query('campus_id');

        return response()->json(
            $service->metrics($campusId !== null ? (int) $campusId : null)
        );
    }
}
