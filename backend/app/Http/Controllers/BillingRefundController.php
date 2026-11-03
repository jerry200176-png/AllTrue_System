<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\BillingRefundService;
use Illuminate\Http\Request;

final class BillingRefundController extends Controller
{
    public function __construct(private BillingRefundService $service)
    {
    }

    public function preview(StudentClass $studentClass)
    {
        return $this->authorizeCourse($studentClass) ?? response()->json($this->service->preview($studentClass));
    }

    public function refund(Request $request, StudentClass $studentClass)
    {
        if ($error = $this->authorizeCourse($studentClass)) {
            return $error;
        }
        $d = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,transfer'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        return response()->json($this->service->refund($studentClass, (int) $d['amount'], $d['method'], $d['date'], trim($d['reason']), $request->attributes->get('auth_user')?->id));
    }

    private function authorizeCourse(StudentClass $course)
    {
        $role = request()->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && (empty($campusIds) || !Student::whereIn('CampusID', $campusIds)->where('id', $course->StudentID)->exists())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }
}
