<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\Student;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = Schedule::query();

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('branch_id', $bid);
        } elseif (!empty($campusIds)) {
            $query->whereIn('branch_id', $campusIds);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->input('teacher_id'));
        }

        if ($request->filled('student_course_id')) {
            $query->where('student_course_id', $request->input('student_course_id'));
        }

        if ($request->filled('status')) {
            $statusInput = $request->input('status');
            if (str_contains($statusInput, ',')) {
                $statuses = array_map('trim', explode(',', $statusInput));
                $query->whereIn('status', $statuses);
            } else {
                $query->where('status', $statusInput);
            }
        }

        // day_of_week filter (used by DirectorDashboard recurring schedules)
        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->input('day_of_week'));
        }

        // schedule_date__is=null — match rows with NULL schedule_date
        if ($request->input('schedule_date__is') === 'null') {
            $query->whereNull('schedule_date');
        } elseif ($request->filled('schedule_date')) {
            $query->where('schedule_date', $request->input('schedule_date'));
        }

        // __limit shortcut
        if ($request->filled('__limit')) {
            $query->limit((int) $request->input('__limit'));
        }

        $perPage = $request->input('per_page');
        if ($perPage === 'all' || (int) $perPage >= 1000) {
            return response()->json($query->orderBy('schedule_date', 'asc')->get());
        }

        return response()->json($query->orderBy('schedule_date', 'asc')->paginate(min((int) ($perPage ?? 200), 1000)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id'           => 'required|integer',
            'teacher_id'           => 'nullable|integer',
            'subject'              => 'nullable|string|max:32',
            'day_of_week'          => 'required|integer|min:0|max:7',
            'start_time'           => 'required|string|max:8',
            'end_time'             => 'required|string|max:8',
            'duration_hours'       => 'nullable|numeric',
            'class_type'           => 'nullable|string|max:32',
            'status'               => 'nullable|string|max:32',
            'type'                 => 'nullable|string|max:16',
            'deduction'            => 'nullable|integer',
            'branch_id'            => 'required|integer',
            'schedule_date'        => 'nullable|date',
            'student_course_id'    => 'nullable|integer',
            'original_schedule_id' => 'nullable|integer',
        ]);

        $schedule = Schedule::create($data);
        return response()->json($schedule, 201);
    }

    public function update(Request $request, Schedule $schedule)
    {
        $data = $request->validate([
            'status'        => 'nullable|string|max:32',
            'type'          => 'nullable|string|max:16',
            'schedule_date' => 'nullable|date',
            'start_time'    => 'nullable|string|max:8',
            'end_time'      => 'nullable|string|max:8',
            'teacher_id'    => 'nullable|integer',
        ]);

        $schedule->fill(array_filter($data, fn ($v) => $v !== null));
        $schedule->save();

        return response()->json($schedule);
    }

    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
