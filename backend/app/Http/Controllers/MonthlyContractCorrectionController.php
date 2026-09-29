<?php

namespace App\Http\Controllers;

use App\Models\StudentClass;
use App\Services\MonthlyContractCorrectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Review only. A browser cannot execute a paid historical repair. */
final class MonthlyContractCorrectionController extends Controller
{
    public function preview(Request $request, StudentClass $studentClass, MonthlyContractCorrectionService $service)
    {
        $campusId = (int) $studentClass->student?->CampusID;
        $role = (string) $request->attributes->get('auth_role');
        if (!in_array($role, ['director', 'super_admin'], true) || $campusId <= 0
            || ($role !== 'super_admin' && !in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        try {
            $plan = $service->preview($studentClass, $request->all());
            unset($plan['snapshot']);
            $plan['execution'] = 'founder_approved_pop_only';
            return response()->json($plan);
        } catch (ValidationException $error) {
            return response()->json(['message' => collect($error->errors())->flatten()->first(), 'errors' => $error->errors()], 422);
        }
    }
}
