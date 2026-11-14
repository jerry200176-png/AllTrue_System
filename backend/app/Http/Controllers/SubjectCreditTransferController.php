<?php

namespace App\Http\Controllers;

use App\Models\{Student, StudentClass};
use App\Services\Billing\SubjectCreditMovement;
use App\Services\Billing\SubjectCreditPreview;
use Illuminate\Http\Request;

/** In-app #535: existing director/super-admin boundary; services recheck scope under locks. */
final class SubjectCreditTransferController extends Controller
{
    public function preview(Request $request, StudentClass $studentClass, SubjectCreditPreview $preview)
    {
        $data = $request->validate([
            'target_course_id' => ['required', 'integer', 'min:1'],
            'sessions' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        // Explicit super-admin exception; all other actors, including empty scope, are bounded.
        $campusIds = $request->attributes->get('auth_role') === 'super_admin'
            ? null : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        return response()->json($preview->run($studentClass, $data, $campusIds));
    }

    public function apply(Request $request, StudentClass $studentClass, SubjectCreditMovement $movement)
    {
        $campusIds = $this->authorizedCampuses($request, $studentClass);
        $data = $request->validate([
            'target_course_id' => ['required', 'integer', 'min:1'],
            'sessions' => ['required', 'integer', 'min:1', 'max:10000'],
            'reason' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            'state_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $r = $movement->apply($studentClass, $data, (int) ($request->attributes->get('auth_user')->id ?? 0), $campusIds);

        return response()->json($r['body'], $r['status']);
    }

    public function reverse(Request $request, StudentClass $studentClass, int $transferId, SubjectCreditMovement $movement)
    {
        $campusIds = $this->authorizedCampuses($request, $studentClass);
        $data = $request->validate(['reason' => ['required', 'string', 'max:100']]);

        return response()->json($movement->reverse($studentClass, $transferId, $data['reason'], (int) ($request->attributes->get('auth_user')->id ?? 0), $campusIds));
    }

    private function authorizedCampuses(Request $request, StudentClass $course): ?array
    {
        $ids = $request->attributes->get('auth_role') === 'super_admin'
            ? null : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if ($ids !== null && !Student::where('id', $course->StudentID)->whereIn('CampusID', $ids)->exists()) {
            abort(403);
        }
        return $ids;
    }
}
