<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\TeacherScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileController extends Controller
{
    /**
     * GET /api/v1/profiles
     * Returns teachers/directors visible to the authenticated user's campus.
     */
    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);

        $query = User::query()->whereIn('type', ['T', 'D', 'S']);

        // Filter by single id (used by App.vue fetchProfile)
        if ($request->filled('id')) {
            $query->where('id', (int) $request->input('id'));
        }

        // Filter by campus: show users linked via UserCampus or (for teachers) teacher_branches
        if (!empty($campusIds) && !$request->filled('id')) {
            $query->where(function ($q) use ($campusIds, $request) {
                $q->whereIn('id', function ($sub) use ($campusIds) {
                    $sub->select('UserID')
                        ->from('UserCampus')
                        ->whereIn('CampusID', $campusIds);
                });
                // Fallback: teachers may only have teacher_branches (e.g. legacy data)
                if (Schema::hasTable('teacher_branches')) {
                    $q->orWhere(function ($q2) use ($campusIds) {
                        $q2->where('User.type', 'T')
                            ->whereIn('id', function ($sub) use ($campusIds) {
                                $sub->select('teacher_id')
                                    ->from('teacher_branches')
                                    ->whereIn('branch_id', $campusIds);
                            });
                    });
                }
            });
        }

        // Optional: filter to teachers that have this branch (main or cross-branch)
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            $query->where(function ($q) use ($branchId) {
                $q->whereExists(function ($sub) use ($branchId) {
                    $sub->select(DB::raw(1))
                        ->from('UserCampus')
                        ->whereColumn('UserCampus.UserID', 'User.id')
                        ->where('UserCampus.CampusID', $branchId);
                });
                // Fallback: teacher_branches so teachers without UserCampus still show
                if (Schema::hasTable('teacher_branches')) {
                    $q->orWhereExists(function ($sub) use ($branchId) {
                        $sub->select(DB::raw(1))
                            ->from('teacher_branches')
                            ->whereColumn('teacher_branches.teacher_id', 'User.id')
                            ->where('teacher_branches.branch_id', $branchId);
                    });
                }
            });
        }

        if ($request->filled('username__ilike')) {
            $query->where('Name', 'like', '%' . $request->input('username__ilike') . '%');
        }

        // Search by name or phone (q)
        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($qry) use ($q) {
                $qry->where('User.Name', 'like', '%' . $q . '%')
                    ->orWhere('User.phone', 'like', '%' . $q . '%');
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('User.status', $request->input('status'));
        }

        // Filter by teachable subject (teacher_subjects)
        if ($request->filled('subject_id') && Schema::hasTable('teacher_subjects')) {
            $query->whereExists(function ($sub) use ($request) {
                $sub->select(DB::raw(1))
                    ->from('teacher_subjects')
                    ->whereColumn('teacher_subjects.teacher_id', 'User.id')
                    ->where('teacher_subjects.subject_id', (int) $request->input('subject_id'));
            });
        }

        // Filter by role (teacher/director/super_admin) — maps to type column
        if ($request->filled('role')) {
            $roleMap = ['teacher' => 'T', 'director' => 'D', 'super_admin' => 'S'];
            $typeFilter = $roleMap[$request->input('role')] ?? null;
            if ($typeFilter) {
                $query->where('type', $typeFilter);
            }
        }

        $isSingle = $request->boolean('single');
        $perPage  = $request->input('per_page');

        // Pre-load branch assignments for all users
        $allCampusRows = DB::table('UserCampus')->get()->groupBy('UserID');

        $buildTeacherExtras = function ($userCollection) {
            $userIds = $userCollection->pluck('id')->all();
            $teacherIds = $userCollection->where('type', 'T')->pluck('id')->all();
            $extras = [];
            foreach ($userIds as $uid) {
                $extras[$uid] = ['phone' => '', 'line_id' => '', 'rfid' => '', 'subject_ids' => [], 'subject_names' => [], 'subject_level_scopes' => []];
            }
            if (empty($teacherIds)) {
                return $extras;
            }
            $tsRows = Schema::hasTable('teacher_subjects')
                ? DB::table('teacher_subjects')->whereIn('teacher_id', $teacherIds)->get()->groupBy('teacher_id')
                : collect();
            $allSubjectIds = $tsRows->flatten(1)->pluck('subject_id')->unique()->all();
            $subjectNames = empty($allSubjectIds)
                ? []
                : DB::table('Subject')->whereIn('id', $allSubjectIds)->pluck('Subject_Name', 'id')->all();
            $ucByTeacher = collect();
            if (Schema::hasColumn('UserCampus', 'RFID')) {
                $ucByTeacher = DB::table('UserCampus')->whereIn('UserID', $teacherIds)->get()->groupBy('UserID');
            }
            $userRows = DB::table('User')->whereIn('id', $teacherIds)->get()->keyBy('id');
            $hasUserLineId = Schema::hasColumn('User', 'LineID');
            foreach ($teacherIds as $tid) {
                $u = $userRows->get($tid);
                $extras[$tid]['phone'] = $u->phone ?? '';
                $extras[$tid]['line_id'] = $hasUserLineId ? ($u->LineID ?? '') : '';
                $byBranch = [];
                foreach ($ucByTeacher->get($tid, collect()) as $ucRow) {
                    $r = isset($ucRow->RFID) ? trim((string) $ucRow->RFID) : '';
                    if ($r !== '') {
                        $byBranch[(string) (int) $ucRow->CampusID] = $r;
                    }
                }
                $extras[$tid]['rfid_by_branch'] = $byBranch;
                $displayRfid = '';
                if (!empty($byBranch)) {
                    $displayRfid = (string) reset($byBranch);
                }
                $extras[$tid]['rfid'] = $displayRfid;
                $ids = $tsRows->get($tid, collect())->pluck('subject_id')->all();
                $extras[$tid]['subject_ids'] = array_map('intval', $ids);
                $extras[$tid]['subject_names'] = array_values(array_map(fn($id) => $subjectNames[$id] ?? (string) $id, $ids));
            }
            $scopeMap = TeacherScopeService::getScopesForTeachers($teacherIds);
            foreach ($teacherIds as $tid) {
                $extras[$tid]['subject_level_scopes'] = $scopeMap[$tid] ?? [];
            }
            return $extras;
        };

        // .single() — return one object directly
        if ($isSingle) {
            $user = $query->first();
            if (!$user) return response()->json(['error' => ['message' => 'Not found']], 404);
            $teacherExtras = $buildTeacherExtras(collect([$user]));
            $allCampusRowsForSingle = DB::table('UserCampus')->get()->groupBy('UserID');
            $out = [
                'id'              => $user->id,
                'username'        => $user->Name,
                'account'         => $user->LoginName,
                'email'           => $user->LoginName,
                'role'            => match ($user->type) { 'S' => 'super_admin', 'T' => 'teacher', default => 'director' },
                'branch_id'       => $allCampusRowsForSingle->get($user->id, collect())->isNotEmpty() ? (int) $allCampusRowsForSingle->get($user->id)->first()->CampusID : null,
                'branch_ids'      => $allCampusRowsForSingle->get($user->id, collect())->pluck('CampusID')->map(fn($id) => (int)$id)->values()->all(),
                'status'          => $user->status ?? 'active',
                'employment_type' => $user->employment_type ?? 'full_time',
                'phone'           => $user->phone,
                'teaching_session_count' => $user->TeachingSessionCount ?? 0,
            ];
            if ($user->type === 'T' && isset($teacherExtras[$user->id])) {
                $out['phone'] = $teacherExtras[$user->id]['phone'] ?: $user->phone;
                $out['line_id'] = $teacherExtras[$user->id]['line_id'] ?? '';
                $out['rfid'] = $teacherExtras[$user->id]['rfid'] ?? '';
                $out['rfid_by_branch'] = $teacherExtras[$user->id]['rfid_by_branch'] ?? [];
                $out['subject_ids'] = $teacherExtras[$user->id]['subject_ids'] ?? [];
                $out['subject_names'] = $teacherExtras[$user->id]['subject_names'] ?? [];
                $out['subject_level_scopes'] = $teacherExtras[$user->id]['subject_level_scopes'] ?? [];
            }
            return response()->json($out);
        }

        $countBeforeGet = $query->count();

        // Fallback: when listing teachers with branch_id and result is empty, return all teachers visible to this director (ignore branch filter) so list is not empty
        if (
            $perPage === 'all'
            && $countBeforeGet === 0
            && $request->filled('branch_id')
            && $request->input('role') === 'teacher'
            && !$request->boolean('strict_branch')
            && !empty($campusIds)
        ) {
            $fallbackQuery = User::query()->where('type', 'T');
            $fallbackQuery->where(function ($q) use ($campusIds) {
                $q->whereIn('id', function ($sub) use ($campusIds) {
                    $sub->select('UserID')->from('UserCampus')->whereIn('CampusID', $campusIds);
                });
                if (Schema::hasTable('teacher_branches')) {
                    $q->orWhereIn('id', function ($sub) use ($campusIds) {
                        $sub->select('teacher_id')->from('teacher_branches')->whereIn('branch_id', $campusIds);
                    });
                }
            });
            if ($request->filled('q')) {
                $q = $request->input('q');
                $fallbackQuery->where(function ($qry) use ($q) {
                    $qry->where('User.Name', 'like', '%' . $q . '%')
                        ->orWhere('User.phone', 'like', '%' . $q . '%');
                });
            }
            if ($request->filled('status')) {
                $fallbackQuery->where('User.status', $request->input('status'));
            }
            if ($request->filled('subject_id') && Schema::hasTable('teacher_subjects')) {
                $fallbackQuery->whereExists(function ($sub) use ($request) {
                    $sub->select(DB::raw(1))->from('teacher_subjects')->whereColumn('teacher_subjects.teacher_id', 'User.id')->where('teacher_subjects.subject_id', (int) $request->input('subject_id'));
                });
            }
            $query = $fallbackQuery;
        }

        if ($perPage === 'all') {
            $users = $query->get();
            $teacherExtras = $buildTeacherExtras($users);
            $users->transform(function ($user) use ($allCampusRows, $teacherExtras) {
                $campusRows = $allCampusRows->get($user->id, collect());
                $out = [
                    'id'              => $user->id,
                    'username'        => $user->Name,
                    'account'         => $user->LoginName,
                    'email'           => $user->LoginName,
                    'role'            => match ($user->type) { 'S' => 'super_admin', 'T' => 'teacher', default => 'director' },
                    'branch_id'       => $campusRows->isNotEmpty() ? (int) $campusRows->first()->CampusID : null,
                    'branch_ids'      => $campusRows->pluck('CampusID')->map(fn($id) => (int)$id)->values()->all(),
                    'status'          => $user->status ?? 'active',
                    'employment_type' => $user->employment_type ?? 'full_time',
                    'phone'           => $user->phone,
                    'teaching_session_count' => $user->TeachingSessionCount ?? 0,
                ];
                if ($user->type === 'T' && isset($teacherExtras[$user->id])) {
                    $out['phone'] = $teacherExtras[$user->id]['phone'] ?: $user->phone;
                    $out['line_id'] = $teacherExtras[$user->id]['line_id'] ?? '';
                    $out['rfid'] = $teacherExtras[$user->id]['rfid'] ?? '';
                    $out['rfid_by_branch'] = $teacherExtras[$user->id]['rfid_by_branch'] ?? [];
                    $out['subject_ids'] = $teacherExtras[$user->id]['subject_ids'] ?? [];
                    $out['subject_names'] = $teacherExtras[$user->id]['subject_names'] ?? [];
                    $out['subject_level_scopes'] = $teacherExtras[$user->id]['subject_level_scopes'] ?? [];
                }
                return $out;
            });
        } else {
            $users = $query->paginate(min((int) ($perPage ?? 50), 200));
            $teacherExtras = $buildTeacherExtras($users->getCollection());
            $users->getCollection()->transform(function ($user) use ($allCampusRows, $teacherExtras) {
                $campusRows = $allCampusRows->get($user->id, collect());
                $out = [
                    'id'              => $user->id,
                    'username'        => $user->Name,
                    'account'         => $user->LoginName,
                    'email'           => $user->LoginName,
                    'role'            => match ($user->type) { 'S' => 'super_admin', 'T' => 'teacher', default => 'director' },
                    'branch_id'       => $campusRows->isNotEmpty() ? (int) $campusRows->first()->CampusID : null,
                    'branch_ids'      => $campusRows->pluck('CampusID')->map(fn($id) => (int)$id)->values()->all(),
                    'status'          => $user->status ?? 'active',
                    'employment_type' => $user->employment_type ?? 'full_time',
                    'phone'           => $user->phone,
                    'teaching_session_count' => $user->TeachingSessionCount ?? 0,
                ];
                if ($user->type === 'T' && isset($teacherExtras[$user->id])) {
                    $out['phone'] = $teacherExtras[$user->id]['phone'] ?: $user->phone;
                    $out['line_id'] = $teacherExtras[$user->id]['line_id'] ?? '';
                    $out['rfid'] = $teacherExtras[$user->id]['rfid'] ?? '';
                    $out['rfid_by_branch'] = $teacherExtras[$user->id]['rfid_by_branch'] ?? [];
                    $out['subject_ids'] = $teacherExtras[$user->id]['subject_ids'] ?? [];
                    $out['subject_names'] = $teacherExtras[$user->id]['subject_names'] ?? [];
                    $out['subject_level_scopes'] = $teacherExtras[$user->id]['subject_level_scopes'] ?? [];
                }
                return $out;
            });
        }

        return response()->json($perPage === 'all' ? $users : $users);
    }

    private function getTeacherExtra(int $userId): array
    {
        $user = DB::table('User')->where('id', $userId)->first();
        $subjectIds = [];
        if (Schema::hasTable('teacher_subjects')) {
            $subjectIds = DB::table('teacher_subjects')->where('teacher_id', $userId)->pluck('subject_id')->all();
        }
        $subjectNames = [];
        if (!empty($subjectIds)) {
            $names = DB::table('Subject')->whereIn('id', $subjectIds)->pluck('Subject_Name', 'id')->all();
            $subjectNames = array_values(array_map(fn($id) => $names[$id] ?? (string) $id, $subjectIds));
        }
        $rfidByBranch = [];
        if (Schema::hasColumn('UserCampus', 'RFID')) {
            foreach (DB::table('UserCampus')->where('UserID', $userId)->get() as $ucRow) {
                $r = isset($ucRow->RFID) ? trim((string) $ucRow->RFID) : '';
                if ($r !== '') {
                    $rfidByBranch[(string) (int) $ucRow->CampusID] = $r;
                }
            }
        }
        $displayRfid = '';
        if (!empty($rfidByBranch)) {
            $displayRfid = (string) reset($rfidByBranch);
        }

        $scopes = TeacherScopeService::getScopesForTeachers([(int) $userId]);
        $subjectLevelScopes = $scopes[(int) $userId] ?? [];

        return [
            'phone'        => $user->phone ?? '',
            'line_id'      => Schema::hasColumn('User', 'LineID') ? ($user->LineID ?? '') : '',
            'rfid'         => $displayRfid,
            'rfid_by_branch' => $rfidByBranch,
            'subject_ids'   => array_map('intval', $subjectIds),
            'subject_names' => $subjectNames,
            'subject_level_scopes' => $subjectLevelScopes,
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:64',
            'account'        => 'nullable|string|max:128|required_without:email',
            'email'          => 'nullable|string|max:128|required_without:account',
            'password'       => 'required|string|min:8|max:128',
            'role'           => 'nullable|string|in:teacher,director',
            'campus_id'      => 'nullable|integer',
            'branch_id'      => 'nullable|integer',
            'multi_branches' => 'nullable|array',
            'multi_branches.*' => 'integer',
            'status'         => 'nullable|string|in:active,pending,suspended',
            'phone'          => 'nullable|string|max:32',
            'line_id'        => 'nullable|string|max:128',
            'subject_ids'    => 'nullable|array',
            'subject_ids.*'  => 'integer',
            'subject_level_scopes' => 'nullable|array',
            'subject_level_scopes.*.subject_id' => 'required_with:subject_level_scopes|integer|min:1',
            'subject_level_scopes.*.level' => 'required_with:subject_level_scopes|string|in:elementary,junior,high',
        ]);
        $loginName = trim((string) ($data['account'] ?? $data['email'] ?? ''));
        if ($loginName === '') {
            return response()->json(['message' => '帳號不可空白'], 422);
        }
        $normalizedPhone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
        if ($normalizedPhone === '') {
            $normalizedPhone = null;
        }

        $type = match ($data['role'] ?? 'teacher') {
            'director' => 'D',
            default    => 'T',
        };

        if (User::where('LoginName', $loginName)->exists()) {
            return response()->json(['message' => '此帳號已存在'], 409);
        }

        $attributes = [
            'LoginName' => $loginName,
            'Name'      => $data['name'],
            'PSW'       => password_hash($data['password'], PASSWORD_DEFAULT),
            'type'      => $type,
            'status'   => $data['status'] ?? 'active',
            'phone'    => $normalizedPhone,
        ];
        if (Schema::hasColumn('User', 'LineID')) {
            $attributes['LineID'] = $data['line_id'] ?? null;
        }

        $user = User::create($attributes);

        $campusId = $data['campus_id'] ?? $data['branch_id'] ?? null;
        if (!$campusId) {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            $campusId = $campusIds[0] ?? null;
        }
        if ($campusId) {
            UserCampus::firstOrCreate([
                'UserID'   => $user->id,
                'CampusID' => $campusId,
            ], ['Approved' => true]);
        }

        if (isset($data['multi_branches']) && is_array($data['multi_branches'])) {
            $mainBranch = $campusId;
            $allBranches = array_unique(array_merge(array_filter([$mainBranch]), $data['multi_branches']));
            foreach ($allBranches as $bid) {
                if ($bid) {
                    if (Schema::hasTable('teacher_branches')) {
                        DB::table('teacher_branches')->insertOrIgnore(['teacher_id' => $user->id, 'branch_id' => (int) $bid]);
                    }
                    UserCampus::firstOrCreate(['UserID' => $user->id, 'CampusID' => (int) $bid], ['Approved' => true]);
                }
            }
        }

        if ($type === 'T' && !empty($data['subject_ids']) && Schema::hasTable('teacher_subjects')) {
            foreach (array_unique($data['subject_ids']) as $sid) {
                if ($sid) {
                    DB::table('teacher_subjects')->insertOrIgnore(['teacher_id' => $user->id, 'subject_id' => (int) $sid]);
                }
            }
        }
        if ($type === 'T' && array_key_exists('subject_level_scopes', $data)) {
            TeacherScopeService::replaceScopes((int) $user->id, (array) ($data['subject_level_scopes'] ?? []));
        }

        $user->username = $user->Name;
        return response()->json($user, 201);
    }

    public function bulkTeachers(Request $request)
    {
        $data = $request->validate([
            'default_branch_id' => 'nullable|integer',
            'teachers' => 'required|array|min:1|max:300',
            'teachers.*.name' => 'required|string|max:64',
            'teachers.*.account' => 'nullable|string|max:128',
            'teachers.*.email' => 'nullable|string|max:128',
            'teachers.*.branch_id' => 'nullable|integer',
            'teachers.*.multi_branches' => 'nullable|array',
            'teachers.*.multi_branches.*' => 'integer',
            'teachers.*.phone' => 'nullable|string|max:32',
            'teachers.*.line_id' => 'nullable|string|max:128',
            'teachers.*.subject_ids' => 'nullable|array',
            'teachers.*.subject_ids.*' => 'integer',
            'teachers.*.status' => 'nullable|string|in:active,pending,suspended',
        ]);

        $authUser = $request->attributes->get('auth_user');
        $authCampusIds = array_values(array_unique(array_map('intval', (array) ($request->attributes->get('auth_campus_ids', [])))));
        $enforceCampusScope = !empty($authCampusIds);

        $teachers = $data['teachers'] ?? [];
        $defaultBranchId = isset($data['default_branch_id'])
            ? (int) $data['default_branch_id']
            : ($authCampusIds[0] ?? null);

        $branchIdsForValidation = [];
        if (!empty($defaultBranchId)) {
            $branchIdsForValidation[] = (int) $defaultBranchId;
        }
        foreach ($teachers as $row) {
            if (!empty($row['branch_id'])) {
                $branchIdsForValidation[] = (int) $row['branch_id'];
            }
            foreach ((array) ($row['multi_branches'] ?? []) as $branchId) {
                if (!empty($branchId)) {
                    $branchIdsForValidation[] = (int) $branchId;
                }
            }
        }

        $branchIdsForValidation = array_values(array_unique(array_filter($branchIdsForValidation)));
        $existingCampusLookup = [];
        if (!empty($branchIdsForValidation)) {
            $existingCampusIds = Campus::query()
                ->whereIn('id', $branchIdsForValidation)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $existingCampusLookup = array_fill_keys($existingCampusIds, true);
        }

        $created = [];
        $failed = [];

        foreach ($teachers as $index => $row) {
            $rowNumber = $index + 1;
            $loginName = trim((string) ($row['account'] ?? $row['email'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $branchId = isset($row['branch_id']) ? (int) $row['branch_id'] : $defaultBranchId;

            if ($loginName === '') {
                $failed[] = [
                    'row' => $rowNumber,
                    'account' => '',
                    'error' => '帳號不可空白',
                ];
                continue;
            }

            if ($branchId === null || $branchId <= 0) {
                $failed[] = [
                    'row' => $rowNumber,
                    'account' => $loginName,
                    'error' => '請提供有效主分校',
                ];
                continue;
            }

            if (!isset($existingCampusLookup[$branchId])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'account' => $loginName,
                    'error' => '主分校不存在',
                ];
                continue;
            }

            if ($enforceCampusScope && !in_array($branchId, $authCampusIds, true)) {
                $failed[] = [
                    'row' => $rowNumber,
                    'account' => $loginName,
                    'error' => '主分校不在可操作範圍',
                ];
                continue;
            }

            $multiBranches = array_values(array_unique(array_map('intval', (array) ($row['multi_branches'] ?? []))));
            $multiBranches = array_values(array_filter($multiBranches, fn ($id) => $id > 0 && $id !== $branchId));

            $invalidMultiBranch = null;
            foreach ($multiBranches as $branch) {
                if (!isset($existingCampusLookup[$branch])) {
                    $invalidMultiBranch = '跨校分校不存在';
                    break;
                }
                if ($enforceCampusScope && !in_array($branch, $authCampusIds, true)) {
                    $invalidMultiBranch = '跨校分校不在可操作範圍';
                    break;
                }
            }
            if ($invalidMultiBranch !== null) {
                $failed[] = [
                    'row' => $rowNumber,
                    'account' => $loginName,
                    'error' => $invalidMultiBranch,
                ];
                continue;
            }

            $status = $row['status'] ?? 'active';
            $subjectIds = array_values(array_unique(array_filter(array_map('intval', (array) ($row['subject_ids'] ?? [])))));
            $normalizedPhone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));
            $normalizedPhone = $normalizedPhone === '' ? null : $normalizedPhone;
            $lineId = trim((string) ($row['line_id'] ?? ''));
            $initialPassword = $this->generateInitialPassword();

            try {
                $user = DB::transaction(function () use ($authUser, $branchId, $initialPassword, $lineId, $loginName, $multiBranches, $name, $normalizedPhone, $status, $subjectIds) {
                    $existsConflict = User::query()
                        ->where('LoginName', $loginName)
                        ->whereIn('type', ['T', 'S', 'A', 'U'])
                        ->exists();

                    if ($existsConflict) {
                        throw new \RuntimeException('此帳號已存在');
                    }

                    $attributes = [
                        'LoginName' => $loginName,
                        'Name' => $name,
                        'PSW' => password_hash($initialPassword, PASSWORD_DEFAULT),
                        'type' => 'T',
                        'status' => $status,
                        'phone' => $normalizedPhone,
                    ];
                    if (Schema::hasColumn('User', 'LineID')) {
                        $attributes['LineID'] = $lineId !== '' ? $lineId : null;
                    }

                    if (Schema::hasColumn('User', 'MustChangePassword')) {
                        $attributes['MustChangePassword'] = true;
                    }
                    if (Schema::hasColumn('User', 'PasswordChangedAt')) {
                        $attributes['PasswordChangedAt'] = null;
                    }
                    if (Schema::hasColumn('User', 'PasswordSetByUserID')) {
                        $attributes['PasswordSetByUserID'] = $authUser?->id;
                    }

                    $user = User::create($attributes);

                    $approvedAttr = Schema::hasColumn('UserCampus', 'Approved') ? ['Approved' => true] : [];
                    UserCampus::firstOrCreate(
                        ['UserID' => $user->id, 'CampusID' => $branchId],
                        $approvedAttr
                    );

                    foreach ($multiBranches as $multiBranchId) {
                        UserCampus::firstOrCreate(
                            ['UserID' => $user->id, 'CampusID' => $multiBranchId],
                            $approvedAttr
                        );
                    }

                    if (Schema::hasTable('teacher_branches')) {
                        $allBranches = array_values(array_unique(array_merge([$branchId], $multiBranches)));
                        foreach ($allBranches as $branch) {
                            DB::table('teacher_branches')->insertOrIgnore([
                                'teacher_id' => $user->id,
                                'branch_id' => $branch,
                            ]);
                        }
                    }

                    if (!empty($subjectIds) && Schema::hasTable('teacher_subjects')) {
                        foreach ($subjectIds as $subjectId) {
                            DB::table('teacher_subjects')->insertOrIgnore([
                                'teacher_id' => $user->id,
                                'subject_id' => $subjectId,
                            ]);
                        }
                    }

                    return $user;
                });

                $created[] = [
                    'row' => $rowNumber,
                    'user_id' => (int) $user->id,
                    'name' => $name,
                    'account' => $loginName,
                    'branch_id' => $branchId,
                    'initial_password' => $initialPassword,
                    'must_change_password' => true,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'row' => $rowNumber,
                    'account' => $loginName,
                    'error' => $e->getMessage() ?: '建立失敗',
                ];
            }
        }

        $response = [
            'message' => '批次建立完成',
            'created' => $created,
            'failed' => $failed,
            'summary' => [
                'total' => count($teachers),
                'created' => count($created),
                'failed' => count($failed),
            ],
        ];

        $statusCode = count($created) > 0 ? 201 : 422;
        return response()->json($response, $statusCode);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $input = $request->validate([
            'username'        => 'nullable|string|max:64',
            'name'            => 'nullable|string|max:64',
            'account'         => 'nullable|string|max:128',
            'email'           => 'nullable|string|max:128',
            'password'        => 'nullable|string|min:8|max:128',
            'employment_type' => 'nullable|string|max:32',
            'status'          => 'nullable|string|in:active,pending,suspended',
            'phone'           => 'nullable|string|max:32',
            'line_id'         => 'nullable|string|max:128',
            'rfid'            => 'nullable|string|max:128',
            'rfid_by_branch'  => 'nullable|array',
            'rfid_by_branch.*' => 'nullable|string|max:32',
            'branch_id'       => 'nullable|integer',
            'multi_branches'  => 'nullable|array',
            'multi_branches.*'=> 'integer',
            'subject_ids'     => 'nullable|array',
            'subject_ids.*'   => 'integer',
            'subject_level_scopes' => 'sometimes|nullable|array',
            'subject_level_scopes.*.subject_id' => 'required_with:subject_level_scopes|integer|min:1',
            'subject_level_scopes.*.level' => 'required_with:subject_level_scopes|string|in:elementary,junior,high',
        ]);

        $newLoginName = trim((string) ($input['account'] ?? $input['email'] ?? ''));
        if ($newLoginName !== '') {
            $exists = User::where('LoginName', $newLoginName)
                ->where('id', '!=', $user->id)
                ->whereIn('type', $this->conflictingTypesForLoginName((string) $user->type))
                ->exists();
            if ($exists) {
                return response()->json(['message' => '此帳號已存在'], 409);
            }
            $user->LoginName = $newLoginName;
        }

        if (!empty($input['username'])) {
            $user->Name = $input['username'];
        }
        if (!empty($input['name'])) {
            $user->Name = $input['name'];
        }
        if (!empty($input['password'])) {
            $user->PSW = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        if (isset($input['employment_type']) && Schema::hasColumn('User', 'employment_type')) {
            $user->employment_type = $input['employment_type'];
        }
        if (isset($input['status']) && Schema::hasColumn('User', 'status')) {
            $user->status = $input['status'];
        }
        $hasPhoneInput = array_key_exists('phone', $input);
        $normalizedPhone = null;
        if ($hasPhoneInput) {
            $digits = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
            $normalizedPhone = $digits !== '' ? $digits : null;
        }
        if ($hasPhoneInput && Schema::hasColumn('User', 'phone')) {
            $user->phone = $normalizedPhone;
        }
        if (array_key_exists('line_id', $input) && Schema::hasColumn('User', 'LineID')) {
            $lineId = trim((string) ($input['line_id'] ?? ''));
            $user->LineID = $lineId !== '' ? $lineId : null;
        }
        $user->save();

        // 通知中心側欄 pending_teachers 依 UserCampus.Approved；老師管理「核准」只送 status=active 時也要釋出分校綁定。
        if (
            isset($input['status'])
            && $input['status'] === 'active'
            && (string) $user->type === 'T'
            && Schema::hasColumn('UserCampus', 'Approved')
        ) {
            DB::table('UserCampus')->where('UserID', $user->id)->update(['Approved' => true]);
        }

        if (isset($input['branch_id'])) {
            $existing = UserCampus::where('UserID', $user->id)->first();
            $approvedAttr = Schema::hasColumn('UserCampus', 'Approved') ? ['Approved' => true] : [];
            if ($existing) {
                $newCampusId = (int) $input['branch_id'];
                $updateData = ['CampusID' => $newCampusId];
                if (Schema::hasColumn('UserCampus', 'Approved')) {
                    $updateData['Approved'] = true;
                }
                DB::table('UserCampus')->where('UserID', $user->id)->where('CampusID', $existing->CampusID)->update($updateData);
            } else {
                UserCampus::create(array_merge(['UserID' => $user->id, 'CampusID' => (int) $input['branch_id']], $approvedAttr));
            }
        }

        if (isset($input['multi_branches']) && is_array($input['multi_branches'])) {
            $mainBranch = $input['branch_id'] ?? UserCampus::where('UserID', $user->id)->value('CampusID');
            $allBranches = array_values(array_unique(array_filter(array_map('intval', array_merge([$mainBranch], $input['multi_branches'])))));
            $approvedAttr = Schema::hasColumn('UserCampus', 'Approved') ? ['Approved' => true] : [];
            if (Schema::hasTable('teacher_branches')) {
                DB::table('teacher_branches')->where('teacher_id', $user->id)->delete();
            }
            DB::table('UserCampus')->where('UserID', $user->id)->whereNotIn('CampusID', $allBranches)->delete();
            foreach ($allBranches as $bid) {
                if ($bid) {
                    if (Schema::hasTable('teacher_branches')) {
                        DB::table('teacher_branches')->insertOrIgnore(['teacher_id' => $user->id, 'branch_id' => (int) $bid]);
                    }
                    UserCampus::firstOrCreate(['UserID' => $user->id, 'CampusID' => (int) $bid], $approvedAttr);
                }
            }
        }

        if ($user->type === 'T') {
            $rfidErr = $this->applyTeacherRfidInput($user, $input);
            if ($rfidErr !== null) {
                return $rfidErr;
            }
            if (array_key_exists('subject_ids', $input) && Schema::hasTable('teacher_subjects')) {
                DB::table('teacher_subjects')->where('teacher_id', $user->id)->delete();
                $ids = is_array($input['subject_ids']) ? array_unique(array_filter($input['subject_ids'])) : [];
                foreach ($ids as $sid) {
                    DB::table('teacher_subjects')->insertOrIgnore(['teacher_id' => $user->id, 'subject_id' => (int) $sid]);
                }
            }
            if (array_key_exists('subject_level_scopes', $input)) {
                TeacherScopeService::replaceScopes((int) $user->id, (array) ($input['subject_level_scopes'] ?? []));
            }
        }

        $campusRows = DB::table('UserCampus')->where('UserID', $user->id)->get();
        $out = [
            'id'              => $user->id,
            'username'        => $user->Name,
            'account'         => $user->LoginName,
            'email'           => $user->LoginName,
            'role'            => match ($user->type) { 'S' => 'super_admin', 'T' => 'teacher', default => 'director' },
            'branch_id'       => $campusRows->isNotEmpty() ? (int) $campusRows->first()->CampusID : null,
            'status'          => $user->status ?? 'active',
            'employment_type' => $user->employment_type ?? 'full_time',
        ];
        if ($user->type === 'T') {
            $this->teacherExtraCache = [];
            $extra = $this->getTeacherExtra($user->id);
            $out['phone'] = $extra['phone'] ?? '';
            $out['line_id'] = $extra['line_id'] ?? '';
            $out['rfid'] = $extra['rfid'] ?? '';
            $out['rfid_by_branch'] = $extra['rfid_by_branch'] ?? [];
            $out['subject_ids'] = $extra['subject_ids'] ?? [];
            $out['subject_names'] = $extra['subject_names'] ?? [];
            $out['subject_level_scopes'] = $extra['subject_level_scopes'] ?? [];
        }
        return response()->json($out);
    }

    public function resetPassword(Request $request, $id)
    {
        $user = User::findOrFail($id);
        if ((string) $user->type !== 'T') {
            return response()->json(['message' => '僅支援重設老師密碼'], 422);
        }

        if (!$this->canManageTeacher($request, (int) $user->id)) {
            return response()->json(['message' => '無權限操作此老師帳號'], 403);
        }

        $temporaryPassword = $this->generateInitialPassword();
        $user->PSW = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        if (Schema::hasColumn('User', 'MustChangePassword')) {
            $user->MustChangePassword = true;
        }
        if (Schema::hasColumn('User', 'PasswordChangedAt')) {
            $user->PasswordChangedAt = null;
        }
        if (Schema::hasColumn('User', 'PasswordSetByUserID')) {
            $operator = $request->attributes->get('auth_user');
            $user->PasswordSetByUserID = $operator?->id;
        }
        $user->save();

        return response()->json([
            'message' => '密碼已重設',
            'id' => (int) $user->id,
            'username' => $user->Name,
            'account' => $user->LoginName,
            'temporary_password' => $temporaryPassword,
            'must_change_password' => true,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);
        if ((string) $user->type !== 'T') {
            return response()->json(['message' => '僅支援刪除老師資料'], 422);
        }

        $authUser = $request->attributes->get('auth_user');
        if ($authUser && (int) $authUser->id === (int) $user->id) {
            return response()->json(['message' => '不可刪除目前登入的帳號'], 422);
        }

        if (!$this->canManageTeacher($request, (int) $user->id)) {
            return response()->json(['message' => '無權限操作此老師帳號'], 403);
        }

        $dependencyCounts = $this->getTeacherDependencyCounts((int) $user->id);
        $hasDependencies = array_sum($dependencyCounts) > 0;
        if ($hasDependencies) {
            return response()->json([
                'message' => '此老師已有授課或歷史資料，請先改為停用狀態',
                'dependency_counts' => $dependencyCounts,
            ], 409);
        }

        DB::transaction(function () use ($user) {
            if (Schema::hasTable('teacher_subjects')) {
                DB::table('teacher_subjects')->where('teacher_id', $user->id)->delete();
            }
            if (Schema::hasTable('teacher_branches')) {
                DB::table('teacher_branches')->where('teacher_id', $user->id)->delete();
            }

            DB::table('UserCampus')->where('UserID', $user->id)->delete();

            if (Schema::hasTable('auth_tokens')) {
                DB::table('auth_tokens')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('user_login_activities')) {
                DB::table('user_login_activities')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('user_notification_preferences')) {
                DB::table('user_notification_preferences')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('password_reset_requests')) {
                DB::table('password_reset_requests')->where('user_id', $user->id)->delete();
            }

            $user->delete();
        });

        return response()->json([
            'message' => '老師資料已刪除',
            'id' => (int) $user->id,
            'username' => $user->Name,
            'account' => $user->LoginName,
        ]);
    }

    /**
     * 寫入老師 RFID：UserCampus.RFID 是唯一權威來源。
     *
     * @return \Illuminate\Http\JsonResponse|null 錯誤時回傳 JSON，成功為 null
     */
    private function applyTeacherRfidInput(User $user, array $input): ?\Illuminate\Http\JsonResponse
    {
        if (!Schema::hasColumn('UserCampus', 'RFID')) {
            return null;
        }

        $allowed = DB::table('UserCampus')
            ->where('UserID', $user->id)
            ->pluck('CampusID')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (array_key_exists('rfid_by_branch', $input)) {
            $map = $input['rfid_by_branch'];
            if (!is_array($map)) {
                return response()->json(['message' => 'rfid_by_branch 必須為物件'], 422);
            }
            foreach ($map as $campusKey => $raw) {
                $cid = (int) $campusKey;
                if (!in_array($cid, $allowed, true)) {
                    continue;
                }
                $val = $raw === null ? '' : trim((string) $raw);
                if ($val === '') {
                    DB::table('UserCampus')->where('UserID', $user->id)->where('CampusID', $cid)->update(['RFID' => null]);
                    continue;
                }
                if (strlen($val) > 32) {
                    return response()->json(['message' => 'RFID 長度不可超過 32 字元'], 422);
                }
                if (!$this->teacherRfidUniqueAtCampus($cid, $val, (int) $user->id)) {
                    $label = $this->campusLabel($cid);

                    return response()->json(['message' => "RFID「{$val}」在{$label}已被其他老師使用"], 422);
                }
                DB::table('UserCampus')->where('UserID', $user->id)->where('CampusID', $cid)->update(['RFID' => $val]);
            }
        } elseif (array_key_exists('rfid', $input)) {
            $raw = $input['rfid'];
            $val = $raw === null || $raw === '' ? null : trim((string) $raw);
            if ($val !== null && strlen($val) > 32) {
                return response()->json(['message' => 'RFID 長度不可超過 32 字元'], 422);
            }
            $mainCampusId = (int) (DB::table('UserCampus')->where('UserID', $user->id)->value('CampusID') ?? 0);
            if ($mainCampusId > 0 && in_array($mainCampusId, $allowed, true)) {
                if ($val !== null && !$this->teacherRfidUniqueAtCampus($mainCampusId, $val, (int) $user->id)) {
                    return response()->json(['message' => 'RFID 於主分校已被其他老師使用'], 422);
                }
                DB::table('UserCampus')->where('UserID', $user->id)->where('CampusID', $mainCampusId)->update(['RFID' => $val]);
            }
        }

        return null;
    }

    private function teacherRfidUniqueAtCampus(int $campusId, string $rfid, int $excludeUserId): bool
    {
        return !DB::table('UserCampus')
            ->where('CampusID', $campusId)
            ->where('RFID', $rfid)
            ->where('UserID', '!=', $excludeUserId)
            ->exists();
    }

    private function campusLabel(int $campusId): string
    {
        if (!Schema::hasTable('Campus')) {
            return '分校';
        }
        $name = DB::table('Campus')->where('id', $campusId)->value('name');

        return $name ? "「{$name}」" : '該分校';
    }

    private function generateInitialPassword(): string
    {
        // Avoid ambiguous characters for easier manual typing.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        $length = 12;
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function getTeacherDependencyCounts(int $teacherUserId): array
    {
        $targets = [
            'StudentClass' => 'TeacherID',
            'LearningRecord' => 'TeacherID',
            'StudentSingIn' => 'TeacherID',
            'TeacherSingIn' => 'TeacherID',
            'schedules' => 'teacher_id',
        ];

        $counts = [];
        foreach ($targets as $table => $column) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                $counts[$table] = 0;
                continue;
            }
            $counts[$table] = (int) DB::table($table)->where($column, $teacherUserId)->count();
        }

        return $counts;
    }

    private function canManageTeacher(Request $request, int $teacherUserId): bool
    {
        $role = (string) ($request->attributes->get('auth_role') ?? '');
        if ($role === 'super_admin') {
            return true;
        }

        $campusIds = array_values(array_unique(array_map(
            'intval',
            (array) ($request->attributes->get('auth_campus_ids', []))
        )));
        if (empty($campusIds)) {
            return false;
        }

        $hasUserCampus = DB::table('UserCampus')
            ->where('UserID', $teacherUserId)
            ->whereIn('CampusID', $campusIds)
            ->exists();
        if ($hasUserCampus) {
            return true;
        }

        if (Schema::hasTable('teacher_branches')) {
            $hasTeacherBranch = DB::table('teacher_branches')
                ->where('teacher_id', $teacherUserId)
                ->whereIn('branch_id', $campusIds)
                ->exists();
            if ($hasTeacherBranch) {
                return true;
            }
        }

        return false;
    }

    private function conflictingTypesForLoginName(string $type): array
    {
        return match ($type) {
            // teacher/director can share login account with each other only
            'T' => ['T', 'S', 'A', 'U'],
            'D' => ['D', 'S', 'A', 'U'],
            default => ['T', 'D', 'S', 'A', 'U'],
        };
    }
}
