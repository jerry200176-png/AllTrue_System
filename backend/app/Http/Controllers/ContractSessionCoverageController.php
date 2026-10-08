<?php

namespace App\Http\Controllers;

use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyVerdict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every lesson date of one contract, each tagged with whether the money for
 * it is in (director billing panel, PRD v2 D13/D16/D17). Coverage reuses the
 * Contract Money Verdict (lessons()); no
 * second date algorithm.
 */
class ContractSessionCoverageController extends Controller
{
    public function __construct(private ContractMoneyVerdict $verdict)
    {
    }

    public function show(Request $request, StudentClass $studentClass): JsonResponse
    {
        $studentClass->loadMissing('student');
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if (!empty($campusIds) && !in_array((int) ($studentClass->student->CampusID ?? 0), $campusIds, true)) {
            abort(403);
        }

        $courseId = (int) $studentClass->getKey();
        $sessions = $this->verdict->lessons($studentClass);

        $purchased = $studentClass->getAttribute('ScheduleMode') === 'date' ? 0 : (int) ($studentClass->SessionCount ?? 0);

        return response()->json([
            'student_class_id' => $courseId,
            'subject' => $studentClass->displaySubjectName(),
            'schedule_mode' => (string) ($studentClass->ScheduleMode ?? ''),
            // Tutoring has no payment obligation; the card hides 登記收款 (directorRecord rejects it).
            'class_type' => (string) ($studentClass->ClassType ?? ''),
            'start_date' => $studentClass->StartDate ? substr((string) $studentClass->StartDate, 0, 10) : null,
            'end_date' => $studentClass->EndDate ? substr((string) $studentClass->EndDate, 0, 10) : null,
            'memo' => (string) ($studentClass->Memo ?? ''),
            'sessions' => $sessions,
            // Count mode: bought lessons that have no date yet (PRD v2 D17).
            'unscheduled_count' => max(0, $purchased - count($sessions)),
        ]);
    }
}
