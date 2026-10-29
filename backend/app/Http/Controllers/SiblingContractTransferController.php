<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\SiblingContractTransferService;
use Illuminate\Http\Request;

/**
 * In-app #412 / #4153: POST /student-classes/{studentClass}/sibling-transfer (`preview: true` computes the plan only).
 * Route middleware limits it to director / super_admin; this adds the campus check on the source contract's student.
 */
final class SiblingContractTransferController extends Controller
{
    public function __construct(private SiblingContractTransferService $service)
    {
    }

    public function store(Request $request, StudentClass $studentClass)
    {
        $campusIds = $request->attributes->get('auth_role') === 'super_admin' ? null : (array) $request->attributes->get('auth_campus_ids', []);
        if ($campusIds !== null && !Student::query()->whereIn('CampusID', $campusIds)->where('id', $studentClass->StudentID)->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $data = $request->validate([
            'target_student_id' => ['required', 'integer', 'min:1'],
            'sessions' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            'acknowledge_no_family_link' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
        ]);
        $actor = $request->attributes->get('auth_user');
        $result = $this->service->run($studentClass, $data, (int) ($actor->id ?? 0), $request->boolean('preview'));

        return response()->json($result['body'], $result['status']);
    }
}
