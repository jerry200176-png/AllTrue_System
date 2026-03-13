<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * GET /api/v1/alerts/tuition
     * Returns courses with low remaining sessions or unpaid status.
     */
    public function tuition(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $campusIds = [$bid];
        }

        $studentIds = empty($campusIds) ? null : Student::whereIn('CampusID', $campusIds)->pluck('id');

        $query = StudentClass::where('Stop', 0)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('ScheduleMode', 'count')
                          ->where(function ($r) {
                              $r->where('RemainingSessions', '<=', 2)
                                ->orWhere('Paid', 0);
                          });
                });
            });

        if ($studentIds !== null) {
            $query->whereIn('StudentID', $studentIds);
        }

        $classes = $query->with('student')->get()->map(function ($c) {
            return [
                'id'                 => $c->ID,
                'student_id'         => (int) $c->StudentID,
                'student_name'       => $c->student->name ?? 'Unknown',
                'campus_id'          => (int) ($c->student->CampusID ?? 0),
                'subject'            => $c->Subject,
                'schedule_mode'      => $c->ScheduleMode,
                'remaining_sessions' => (int) ($c->RemainingSessions ?? 0),
                'sessions_purchased' => (int) ($c->SessionCount ?? 0),
                'paid'               => (bool) $c->Paid,
            ];
        });

        return response()->json($classes);
    }
}
