<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\ParentBinding\GuardianSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff multi-guardian CRUD. Gated by multi_guardian_enabled (dual-read/UX flag).
 * Dual-write tables may already exist while flag is off; mutating API stays dark.
 */
class StudentGuardianController extends Controller
{
    public function index(Request $request, Student $student)
    {
        if ($deny = $this->authorizeStudent($request, $student)) {
            return $deny;
        }
        if (!GuardianSyncService::enabled()) {
            return response()->json(['message' => 'Multi-guardian is not enabled', 'guardians' => []], 404);
        }

        $rows = app(GuardianSyncService::class)->listForStudent((int) $student->getKey());

        return response()->json([
            'guardians' => array_map([$this, 'transform'], $rows),
        ]);
    }

    public function store(Request $request, Student $student)
    {
        if ($deny = $this->authorizeStudent($request, $student)) {
            return $deny;
        }
        if (!GuardianSyncService::enabled() || !GuardianSyncService::dualWriteEnabled()) {
            return response()->json(['message' => 'Multi-guardian is not enabled'], 404);
        }

        $data = $request->validate([
            'display_name' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:20',
            'line_user_id' => ['nullable', 'string', 'max:64', 'regex:/^U[0-9a-fA-F]{32}$/'],
            'role' => ['nullable', Rule::in([
                StudentGuardian::ROLE_FATHER,
                StudentGuardian::ROLE_MOTHER,
                StudentGuardian::ROLE_GUARDIAN,
                StudentGuardian::ROLE_OTHER,
            ])],
            'is_primary' => 'nullable|boolean',
            'notify_learning_feedback' => 'nullable|boolean',
            'notify_tuition' => 'nullable|boolean',
        ]);

        if (empty(trim((string) ($data['phone'] ?? ''))) && empty(trim((string) ($data['line_user_id'] ?? '')))) {
            return response()->json(['message' => 'phone or line_user_id is required'], 422);
        }

        try {
            $link = app(GuardianSyncService::class)->upsertRelationship($student, $data, StudentGuardian::SOURCE_STAFF);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->transform($link), 201);
    }

    public function update(Request $request, Student $student, int $studentGuardianId)
    {
        if ($deny = $this->authorizeStudent($request, $student)) {
            return $deny;
        }
        if (!GuardianSyncService::enabled() || !GuardianSyncService::dualWriteEnabled()) {
            return response()->json(['message' => 'Multi-guardian is not enabled'], 404);
        }

        $link = StudentGuardian::query()
            ->where('id', $studentGuardianId)
            ->where('student_id', (int) $student->getKey())
            ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
            ->first();
        if (!$link) {
            return response()->json(['message' => 'Guardian link not found'], 404);
        }

        $data = $request->validate([
            'display_name' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:20',
            'line_user_id' => ['nullable', 'string', 'max:64', 'regex:/^U[0-9a-fA-F]{32}$/'],
            'role' => ['nullable', Rule::in([
                StudentGuardian::ROLE_FATHER,
                StudentGuardian::ROLE_MOTHER,
                StudentGuardian::ROLE_GUARDIAN,
                StudentGuardian::ROLE_OTHER,
            ])],
            'is_primary' => 'nullable|boolean',
            'notify_learning_feedback' => 'nullable|boolean',
            'notify_tuition' => 'nullable|boolean',
        ]);

        $guardian = $link->guardian;
        $payload = array_merge([
            'display_name' => $guardian !== null ? $guardian->display_name : null,
            'phone' => $guardian !== null ? $guardian->phone : null,
            'line_user_id' => $guardian !== null ? $guardian->line_user_id : null,
            'role' => $link->role,
            'is_primary' => $link->is_primary,
            'notify_learning_feedback' => $link->notify_learning_feedback,
            'notify_tuition' => $link->notify_tuition,
        ], array_filter($data, fn ($v) => $v !== null));

        $updated = app(GuardianSyncService::class)->updateRelationship($student, $link, $payload, StudentGuardian::SOURCE_STAFF);

        return response()->json($this->transform($updated));
    }

    public function destroy(Request $request, Student $student, int $studentGuardianId)
    {
        if ($deny = $this->authorizeStudent($request, $student)) {
            return $deny;
        }
        if (!GuardianSyncService::enabled() || !GuardianSyncService::dualWriteEnabled()) {
            return response()->json(['message' => 'Multi-guardian is not enabled'], 404);
        }

        $link = StudentGuardian::query()
            ->where('id', $studentGuardianId)
            ->where('student_id', (int) $student->getKey())
            ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
            ->first();
        if (!$link) {
            return response()->json(['message' => 'Guardian link not found'], 404);
        }

        app(GuardianSyncService::class)->revoke($link);

        return response()->json(['message' => '已解除監護人關係']);
    }

    private function authorizeStudent(Request $request, Student $student)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if (!empty($campusIds) && !in_array((int) $student->CampusID, $campusIds, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function transform(StudentGuardian $link): array
    {
        $g = $link->guardian;
        $lineUserId = $g !== null ? (string) ($g->line_user_id ?? '') : '';

        return [
            'id' => (int) $link->getKey(),
            'student_id' => (int) $link->student_id,
            'guardian_id' => (int) $link->guardian_id,
            'campus_id' => $link->campus_id !== null ? (int) $link->campus_id : null,
            'role' => (string) $link->role,
            'is_primary' => (bool) $link->is_primary,
            'status' => (string) $link->status,
            'notify_learning_feedback' => (bool) $link->notify_learning_feedback,
            'notify_tuition' => (bool) $link->notify_tuition,
            'source' => (string) $link->source,
            'display_name' => $g !== null ? $g->display_name : null,
            'phone' => $g !== null ? $g->phone : null,
            'line_user_id_masked' => $this->maskLineUserId($lineUserId),
        ];
    }

    private function maskLineUserId(string $uid): ?string
    {
        if ($uid === '') {
            return null;
        }
        if (strlen($uid) <= 12) {
            return $uid;
        }

        return substr($uid, 0, 8) . '…' . substr($uid, -4);
    }
}
