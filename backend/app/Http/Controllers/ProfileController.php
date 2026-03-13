<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserCampus;
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
                    ->orWhere('User.phone', 'like', '%' . $q . '%')
                    ->orWhereExists(function ($sub) use ($q) {
                        $sub->select(DB::raw(1))
                            ->from('Teacher')
                            ->whereColumn('Teacher.id', 'User.id')
                            ->where('Teacher.Phone', 'like', '%' . $q . '%');
                    });
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
                $extras[$uid] = ['phone' => '', 'line_id' => '', 'rfid' => '', 'subject_ids' => [], 'subject_names' => []];
            }
            if (empty($teacherIds)) {
                return $extras;
            }
            $teachers = DB::table('Teacher')->whereIn('id', $teacherIds)->get()->keyBy('id');
            $userPhones = DB::table('User')->whereIn('id', $teacherIds)->pluck('phone', 'id');
            $tsRows = Schema::hasTable('teacher_subjects')
                ? DB::table('teacher_subjects')->whereIn('teacher_id', $teacherIds)->get()->groupBy('teacher_id')
                : collect();
            $allSubjectIds = $tsRows->flatten(1)->pluck('subject_id')->unique()->all();
            $subjectNames = empty($allSubjectIds)
                ? []
                : DB::table('Subject')->whereIn('id', $allSubjectIds)->pluck('Subject_Name', 'id')->all();
            foreach ($teacherIds as $tid) {
                $t = $teachers->get($tid);
                $extras[$tid]['phone'] = $t->Phone ?? $userPhones[$tid] ?? '';
                $extras[$tid]['line_id'] = $t->LineID ?? '';
                $extras[$tid]['rfid'] = $t->RFID ?? '';
                $ids = $tsRows->get($tid, collect())->pluck('subject_id')->all();
                $extras[$tid]['subject_ids'] = array_map('intval', $ids);
                $extras[$tid]['subject_names'] = array_values(array_map(fn($id) => $subjectNames[$id] ?? (string) $id, $ids));
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
                $out['subject_ids'] = $teacherExtras[$user->id]['subject_ids'] ?? [];
                $out['subject_names'] = $teacherExtras[$user->id]['subject_names'] ?? [];
            }
            return response()->json($out);
        }

        $countBeforeGet = $query->count();

        // Fallback: when listing teachers with branch_id and result is empty, return all teachers visible to this director (ignore branch filter) so list is not empty
        if ($perPage === 'all' && $countBeforeGet === 0 && $request->filled('branch_id') && $request->input('role') === 'teacher' && !empty($campusIds)) {
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
                        ->orWhere('User.phone', 'like', '%' . $q . '%')
                        ->orWhereExists(function ($sub) use ($q) {
                            $sub->select(DB::raw(1))->from('Teacher')->whereColumn('Teacher.id', 'User.id')->where('Teacher.Phone', 'like', '%' . $q . '%');
                        });
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
                    $out['subject_ids'] = $teacherExtras[$user->id]['subject_ids'] ?? [];
                    $out['subject_names'] = $teacherExtras[$user->id]['subject_names'] ?? [];
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
                    $out['subject_ids'] = $teacherExtras[$user->id]['subject_ids'] ?? [];
                    $out['subject_names'] = $teacherExtras[$user->id]['subject_names'] ?? [];
                }
                return $out;
            });
        }

        return response()->json($perPage === 'all' ? $users : $users);
    }

    private function getTeacherExtra(int $userId): array
    {
        $user = DB::table('User')->where('id', $userId)->first();
        $teacher = DB::table('Teacher')->where('id', $userId)->first();
        $subjectIds = [];
        if (Schema::hasTable('teacher_subjects')) {
            $subjectIds = DB::table('teacher_subjects')->where('teacher_id', $userId)->pluck('subject_id')->all();
        }
        $subjectNames = [];
        if (!empty($subjectIds)) {
            $names = DB::table('Subject')->whereIn('id', $subjectIds)->pluck('Subject_Name', 'id')->all();
            $subjectNames = array_values(array_map(fn($id) => $names[$id] ?? (string) $id, $subjectIds));
        }
        return [
            'phone'        => $teacher->Phone ?? $user->phone ?? '',
            'line_id'      => $teacher->LineID ?? '',
            'rfid'         => $teacher->RFID ?? '',
            'subject_ids'   => array_map('intval', $subjectIds),
            'subject_names' => $subjectNames,
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:64',
            'email'          => 'required|string|max:128',
            'password'       => 'required|string|min:4|max:128',
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
        ]);

        $exists = User::where('LoginName', $data['email'])->exists();
        if ($exists) {
            return response()->json(['message' => '此帳號已存在'], 409);
        }

        $type = match ($data['role'] ?? 'teacher') {
            'director' => 'D',
            default    => 'T',
        };

        $user = User::create([
            'LoginName' => $data['email'],
            'Name'      => $data['name'],
            'PSW'       => password_hash($data['password'], PASSWORD_DEFAULT),
            'type'      => $type,
            'status'   => $data['status'] ?? 'active',
            'phone'    => $data['phone'] ?? null,
        ]);

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

        if ($type === 'T' && !DB::table('Teacher')->where('id', $user->id)->exists()) {
            DB::table('Teacher')->insert([
                'id'         => $user->id,
                'T_Name'     => $user->Name,
                'CampusID'   => $campusId ?? 0,
                'Enable'     => 1,
                'MDT'        => now(),
                'TelegramID' => '',
                'Phone'      => $data['phone'] ?? '',
                'LineID'     => $data['line_id'] ?? '',
            ]);
        }

        if ($type === 'T' && !empty($data['subject_ids']) && Schema::hasTable('teacher_subjects')) {
            foreach (array_unique($data['subject_ids']) as $sid) {
                if ($sid) {
                    DB::table('teacher_subjects')->insertOrIgnore(['teacher_id' => $user->id, 'subject_id' => (int) $sid]);
                }
            }
        }

        $user->username = $user->Name;
        return response()->json($user, 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $input = $request->all();

        if (!empty($input['username'])) {
            $user->Name = $input['username'];
            if ($user->type === 'T' && Schema::hasTable('Teacher')) {
                DB::table('Teacher')->where('id', $user->id)->update(['T_Name' => $input['username']]);
            }
        }
        if (!empty($input['name'])) {
            $user->Name = $input['name'];
            if ($user->type === 'T' && Schema::hasTable('Teacher')) {
                DB::table('Teacher')->where('id', $user->id)->update(['T_Name' => $input['name']]);
            }
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
        if (array_key_exists('phone', $input) && Schema::hasColumn('User', 'phone')) {
            $user->phone = ($input['phone'] !== null && $input['phone'] !== '') ? $input['phone'] : 0;
        }
        $user->save();

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
            $allBranches = array_unique(array_filter(array_merge([$mainBranch], $input['multi_branches'])));
            $approvedAttr = Schema::hasColumn('UserCampus', 'Approved') ? ['Approved' => true] : [];
            if (Schema::hasTable('teacher_branches')) {
                DB::table('teacher_branches')->where('teacher_id', $user->id)->delete();
            }
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
            $teacherUpdates = [];
            if (array_key_exists('phone', $input)) {
                $teacherUpdates['Phone'] = $input['phone'] ?? '';
            }
            if (array_key_exists('line_id', $input)) {
                $teacherUpdates['LineID'] = $input['line_id'] ?? '';
            }
            if (array_key_exists('rfid', $input)) {
                $teacherUpdates['RFID'] = $input['rfid'] ?? null;
            }
            if (!empty($teacherUpdates)) {
                DB::table('Teacher')->where('id', $user->id)->update($teacherUpdates);
            }
            if (array_key_exists('subject_ids', $input) && Schema::hasTable('teacher_subjects')) {
                DB::table('teacher_subjects')->where('teacher_id', $user->id)->delete();
                $ids = is_array($input['subject_ids']) ? array_unique(array_filter($input['subject_ids'])) : [];
                foreach ($ids as $sid) {
                    DB::table('teacher_subjects')->insertOrIgnore(['teacher_id' => $user->id, 'subject_id' => (int) $sid]);
                }
            }
        }

        $campusRows = DB::table('UserCampus')->where('UserID', $user->id)->get();
        $out = [
            'id'              => $user->id,
            'username'        => $user->Name,
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
            $out['subject_ids'] = $extra['subject_ids'] ?? [];
            $out['subject_names'] = $extra['subject_names'] ?? [];
        }
        return response()->json($out);
    }
}
