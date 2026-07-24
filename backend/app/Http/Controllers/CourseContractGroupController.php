<?php

namespace App\Http\Controllers;

use App\Models\CourseContractGroup;
use App\Services\CourseContinuityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Course Continuity API (#1382) — director group view / link / unlink.
 */
class CourseContractGroupController extends Controller
{
    public function index(Request $request, CourseContinuityService $service)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'campus_id' => 'nullable|integer|min:1',
        ]);

        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $campusId = isset($data['campus_id'])
            ? (int) $data['campus_id']
            : (isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        $groups = $service->listForStudent($scope, (int) $data['student_id'], $campusId);

        return response()->json([
            'data' => $groups->map(fn (CourseContractGroup $g) => $this->serializeGroup($g))->values(),
        ]);
    }

    public function store(Request $request, CourseContinuityService $service)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|min:1',
            'campus_id' => 'required|integer|min:1',
            'subject_id' => 'nullable|integer|min:1',
            'label' => 'nullable|string|max:128',
            'members' => 'required|array|min:1',
            'members.*.student_class_id' => 'required|integer|min:1',
            'members.*.relation_type' => 'required|in:original,renewal,replacement,trial,parallel',
            'members.*.effective_from' => 'nullable|date',
            'members.*.decision_reason' => 'nullable|string|max:255',
        ]);

        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        try {
            $group = $service->createGroup($data, $this->authUserId($request), $scope);
        } catch (ValidationException $e) {
            return response()->json(['message' => '無法建立課程群組', 'errors' => $e->errors()], 422);
        }

        return response()->json(['data' => $this->serializeGroup($group)], 201);
    }

    public function addMember(Request $request, CourseContinuityService $service, int $id)
    {
        $data = $request->validate([
            'student_class_id' => 'required|integer|min:1',
            'relation_type' => 'required|in:original,renewal,replacement,trial,parallel',
            'effective_from' => 'nullable|date',
            'decision_reason' => 'nullable|string|max:255',
        ]);

        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $group = CourseContractGroup::query()->find($id);
        if (!$group) {
            return response()->json(['message' => '群組不存在'], 404);
        }

        try {
            $member = $service->addMember($group, $data, $this->authUserId($request), $scope);
        } catch (ValidationException $e) {
            return response()->json(['message' => '無法加入成員', 'errors' => $e->errors()], 422);
        }

        return response()->json(['data' => $this->serializeMember($member)], 201);
    }

    public function unlinkMember(Request $request, CourseContinuityService $service, int $id, int $memberId)
    {
        $scope = $this->resolveCampusScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $group = CourseContractGroup::query()->find($id);
        if (!$group) {
            return response()->json(['message' => '群組不存在'], 404);
        }

        try {
            $member = $service->unlinkMember($group, $memberId, $scope);
        } catch (ValidationException $e) {
            return response()->json(['message' => '無法解除關聯', 'errors' => $e->errors()], 422);
        }

        return response()->json(['data' => $this->serializeMember($member)]);
    }

    private function serializeGroup(CourseContractGroup $group): array
    {
        $members = $group->relationLoaded('activeMembers')
            ? $group->activeMembers
            : $group->activeMembers()->orderBy('sequence')->orderBy('id')->get();

        return [
            'id' => (int) $group->id,
            'student_id' => (int) $group->student_id,
            'campus_id' => (int) $group->campus_id,
            'subject_id' => $group->subject_id !== null ? (int) $group->subject_id : null,
            'label' => $group->label,
            'members' => $members->map(fn ($m) => $this->serializeMember($m))->values(),
        ];
    }

    private function serializeMember(\App\Models\CourseContractGroupMember $member): array
    {
        return [
            'id' => (int) $member->id,
            'group_id' => (int) $member->group_id,
            'student_class_id' => (int) $member->student_class_id,
            'relation_type' => (string) $member->relation_type,
            'effective_from' => optional($member->effective_from)->toDateString(),
            'sequence' => (int) $member->sequence,
            'decision_reason' => $member->decision_reason,
            'unlinked_at' => optional($member->unlinked_at)->toIso8601String(),
        ];
    }

    /**
     * @return array{mode: string, campus_ids: array<int>}|JsonResponse
     */
    private function resolveCampusScope(Request $request)
    {
        $isSuper = $request->attributes->get('auth_role') === 'super_admin';
        $auth = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])),
            fn ($id) => $id > 0
        )));

        $requested = (int) ($request->input('branch_id') ?: $request->input('campus_id') ?: 0);
        if ($requested > 0) {
            if (!$isSuper && !in_array($requested, $auth, true)) {
                return response()->json(['message' => '校區不在授權範圍'], 403);
            }

            return ['mode' => 'single', 'campus_ids' => [$requested]];
        }

        if ($isSuper && $request->boolean('all_campuses')) {
            return ['mode' => 'all', 'campus_ids' => []];
        }

        if ($auth === []) {
            return response()->json(['message' => '缺少校區授權'], 403);
        }

        return ['mode' => 'list', 'campus_ids' => $auth];
    }

    private function authUserId(Request $request): ?int
    {
        $u = $request->attributes->get('auth_user');

        return ($u && isset($u->id)) ? (int) $u->id : null;
    }
}
