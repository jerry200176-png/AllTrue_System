<?php

namespace App\Http\Controllers;

use App\Models\StudentClass;
use App\Services\Billing\SubjectCreditPreview;
use Illuminate\Http\Request;

/** In-app #535: no transfer endpoint until the separately tested atomic writer is delivered. */
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
}
