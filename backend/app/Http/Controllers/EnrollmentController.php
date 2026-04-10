<?php

namespace App\Http\Controllers;

use App\Services\EnrollmentService;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function store(Request $request, EnrollmentService $enrollmentService)
    {
        $data = $request->validate([
            'student_id' => 'nullable|integer|exists:Student,id',
            'student' => 'nullable|array',
            'student.name' => 'required_without:student_id|string|max:64',
            'student.grade' => 'nullable|string|max:8',
            'student.phone' => 'nullable|string|max:32',
            'student.school' => 'nullable|string|max:128',
            'student.parent_name' => 'nullable|string|max:64',
            'student.parent_phone' => 'nullable|string|max:32',
            'student.notes' => 'nullable|string|max:500',
            'student.status' => 'nullable|in:active,graduated,paused,transferred',
            'student.rfid' => 'nullable|string|max:64',
            'teacher_id' => 'required|integer|exists:User,id',
            'subject' => 'required|string|max:64',
            'class_type' => 'required|in:one_on_one,one_on_two,one_on_three,tutoring',
            'total_classes' => 'nullable|integer|min:1|max:500',
            'confirmed_dates' => 'required|array|max:500',
            'confirmed_dates.*' => 'required|date',
            'future_dates' => 'present|array|max:500',
            'future_dates.*' => 'required|date',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:1|max:7',
            'start_time' => 'required_without:day_time_slots|date_format:H:i',
            'day_time_slots' => 'nullable|array|max:7',
            'day_time_slots.*.day' => 'required_with:day_time_slots|integer|min:1|max:7',
            'day_time_slots.*.start_time' => 'required_with:day_time_slots|date_format:H:i',
            'day_time_slots.*.duration_minutes' => 'nullable|integer|min:30|max:480',
            'duration_minutes' => 'required|integer|min:30|max:480',
            'rate_unit' => 'nullable|in:session,hour',
            'price_per_session' => 'required|numeric|min:0',
            'payment_type' => 'required|in:session,monthly',
            'settlement_day' => 'nullable|integer|min:1|max:31',
            'monthly_sessions' => 'nullable|integer|min:1|max:500',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'memo' => 'nullable|string|max:512',
            'branch_id' => 'required|integer|min:1',
            'mode' => 'nullable|in:create,backfill,enrollment',
        ]);

        return $enrollmentService->store($request, $data);
    }
}
