<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentIdentityGroup;
use App\Models\StudentIdentityMember;
use App\Services\StudentIdentityService;
use Illuminate\Http\Request;

class StudentIdentityController extends Controller
{
    public function __construct(private StudentIdentityService $identities)
    {
    }

    public function index(Request $request)
    {
        $groups = StudentIdentityGroup::query()
            ->where('status', 'active')
            ->with(['members.student', 'access'])
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($group) => $this->canManageGroup($request, $group))
            ->values()
            ->map(fn ($group) => $this->presentGroup($group));

        $query = trim((string) $request->query('q', ''));
        $students = collect();
        if ($query !== '') {
            $students = Student::query()
                ->where('name', 'like', "%{$query}%")
                ->get()
                ->filter(fn ($student) => $this->canManageCampus($request, (int) $student->getAttribute('CampusID')))
                ->map(function ($student) {
                    $campusId = (int) $student->getAttribute('CampusID');
                    $studentId = (int) $student->getAttribute('id');
                    $campus = Campus::query()->find($campusId);
                    $member = StudentIdentityMember::query()->where('student_id', $studentId)->first();
                    return [
                        'student_id' => $studentId,
                        'name' => (string) $student->getAttribute('name'),
                        'campus_id' => $campusId,
                        'campus_name' => $campus?->getAttribute('name'),
                        'identity_group_id' => $member?->getAttribute('identity_group_id'),
                        'identity_status' => $member?->getAttribute('status'),
                    ];
                })->values();
        }

        return response()->json(['groups' => $groups, 'students' => $students]);
    }

    public function link(Request $request)
    {
        $data = $request->validate([
            'first_student_id' => 'required|integer|min:1',
            'second_student_id' => 'required|integer|min:1|different:first_student_id',
        ]);

        $group = $this->identities->linkStudents(
            (int) $data['first_student_id'],
            (int) $data['second_student_id'],
            $this->actorId($request),
            (string) $request->attributes->get('auth_role'),
            $this->campusIds($request)
        );

        return response()->json(['group' => $this->presentGroup($group->load(['members.student', 'access']))], 201);
    }

    public function unlink(Request $request, int $studentId)
    {
        $member = StudentIdentityMember::query()->where('student_id', $studentId)->firstOrFail();
        /** @var StudentIdentityGroup $group */
        $group = StudentIdentityGroup::query()->findOrFail((int) $member->getAttribute('identity_group_id'));
        if (!$this->canManageGroup($request, $group)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $member = $this->identities->unlinkStudent($studentId, $this->actorId($request), (string) ($data['reason'] ?? ''));
        return response()->json(['member' => $member]);
    }

    public function access(Request $request, int $groupId)
    {
        /** @var StudentIdentityGroup $group */
        $group = StudentIdentityGroup::query()->with('members')->findOrFail($groupId);
        if (!$this->canManageGroup($request, $group)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $data = $request->validate(['mode' => 'required|in:off,readonly,actions']);
        $access = $this->identities->setMode($groupId, $data['mode'], $this->actorId($request));
        return response()->json(['identity_group_id' => $groupId, 'mode' => $access->mode]);
    }

    public function audit(Request $request, int $groupId)
    {
        /** @var StudentIdentityGroup $group */
        $group = StudentIdentityGroup::query()->with('members')->findOrFail($groupId);
        if (!$this->canManageGroup($request, $group)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return response()->json(['identity_group_id' => $groupId, 'audit' => $this->identities->auditHistory($groupId)]);
    }

    private function presentGroup(StudentIdentityGroup $group): array
    {
        return [
            'id' => (int) $group->id,
            'display_name' => $group->display_name,
            'mode' => $group->access?->getAttribute('mode') ?: StudentIdentityService::MODE_OFF,
            'members' => $group->members->map(function ($member) {
                $campus = Campus::query()->find((int) $member->getAttribute('campus_id'));
                return [
                    'student_id' => (int) $member->getAttribute('student_id'),
                    'name' => (string) ($member->student?->getAttribute('name') ?? ''),
                    'campus_id' => (int) $member->getAttribute('campus_id'),
                    'campus_name' => $campus?->getAttribute('name'),
                    'status' => $member->getAttribute('status'),
                ];
            })->values()->all(),
        ];
    }

    private function canManageGroup(Request $request, StudentIdentityGroup $group): bool
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return true;
        }
        $allowed = $this->campusIds($request);
        return $group->members->every(fn ($member) => in_array((int) $member->getAttribute('campus_id'), $allowed, true));
    }

    private function canManageCampus(Request $request, int $campusId): bool
    {
        return $request->attributes->get('auth_role') === 'super_admin'
            || in_array($campusId, $this->campusIds($request), true);
    }

    private function campusIds(Request $request): array
    {
        return array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->attributes->get('auth_user');
        return $user?->id ? (int) $user->id : null;
    }
}
