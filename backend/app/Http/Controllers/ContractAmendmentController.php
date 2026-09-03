<?php

namespace App\Http\Controllers;

use App\Models\StudentClass;
use App\Models\Student;
use App\Services\ContractAmendmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ContractAmendmentController extends Controller
{
    public function __construct(private ContractAmendmentService $service)
    {
    }

    public function preview(Request $request, StudentClass $studentClass)
    {
        if ($error = $this->authorizeCourse($studentClass)) {
            return $error;
        }
        $data = $request->validate(['new_session_count' => ['required', 'integer', 'min:1']]);
        try {
            return response()->json($this->service->preview($studentClass, (int) $data['new_session_count']));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: '無法預覽合約調整',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function execute(Request $request, StudentClass $studentClass)
    {
        if ($error = $this->authorizeCourse($studentClass)) {
            return $error;
        }
        $data = $request->validate([
            'new_session_count' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->attributes->get('auth_user');
        try {
            return response()->json($this->service->execute(
                $studentClass,
                (int) $data['new_session_count'],
                (int) ($actor->id ?? 0),
                (string) $data['reason']
            ));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: '合約調整失敗',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    private function authorizeCourse(StudentClass $course)
    {
        $role = request()->attributes->get('auth_role');
        if ($role === 'teacher' && (int) $course->getAttribute('TeacherID') !== (int) request()->attributes->get('auth_teacher_id')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $campusIds = $role === 'super_admin' ? [] : request()->attributes->get('auth_campus_ids', []);
        if ($campusIds !== [] && !Student::query()->whereIn('CampusID', $campusIds)->where('id', $course->getAttribute('StudentID'))->exists()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }
}
