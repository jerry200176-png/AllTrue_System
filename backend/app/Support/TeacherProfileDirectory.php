<?php

namespace App\Support;

use App\Models\User;
use App\Services\TeacherScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherProfileDirectory
{
    public static function nameFor(?int $userId, string $fallback = '未指派'): string
    {
        if (!$userId) {
            return $fallback;
        }

        $name = DB::table('User')->where('id', $userId)->value('Name');

        return $name !== null && trim((string) $name) !== '' ? (string) $name : $fallback;
    }

    /**
     * @param int[]|null $userIds
     * @return array<int, string>
     */
    public static function names(?array $userIds = null): array
    {
        $query = DB::table('User')->whereIn('type', ['T', 'U', 'D']);
        if ($userIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0)));
            if (empty($ids)) {
                return [];
            }
            $query->whereIn('id', $ids);
        }

        return $query->pluck('Name', 'id')->toArray();
    }

    /**
     * @param Collection<int, User|\stdClass> $users
     * @return array<int, array<string, mixed>>
     */
    public static function extrasForUsers(Collection $users): array
    {
        $userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
        $teacherIds = $users
            ->filter(fn ($user) => (string) ($user->type ?? '') === 'T')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $extras = [];
        foreach ($userIds as $uid) {
            $extras[$uid] = [
                'phone' => '',
                'line_id' => '',
                'rfid' => '',
                'rfid_by_branch' => [],
                'subject_ids' => [],
                'subject_names' => [],
                'subject_level_scopes' => [],
            ];
        }

        if (empty($teacherIds)) {
            return $extras;
        }

        $userRows = DB::table('User')->whereIn('id', $teacherIds)->get()->keyBy('id');
        $tsRows = Schema::hasTable('teacher_subjects')
            ? DB::table('teacher_subjects')->whereIn('teacher_id', $teacherIds)->get()->groupBy('teacher_id')
            : collect();
        $allSubjectIds = $tsRows->flatten(1)->pluck('subject_id')->unique()->all();
        $subjectNames = empty($allSubjectIds)
            ? []
            : DB::table('Subject')->whereIn('id', $allSubjectIds)->pluck('Subject_Name', 'id')->all();

        $ucByTeacher = DB::table('UserCampus')->whereIn('UserID', $teacherIds)->get()->groupBy('UserID');
        $hasLineId = Schema::hasColumn('User', 'LineID');

        foreach ($teacherIds as $tid) {
            $user = $userRows->get($tid);
            $extras[$tid]['phone'] = (string) ($user->phone ?? '');
            $extras[$tid]['line_id'] = $hasLineId ? (string) ($user->LineID ?? '') : '';

            $byBranch = [];
            foreach ($ucByTeacher->get($tid, collect()) as $ucRow) {
                $r = isset($ucRow->RFID) ? trim((string) $ucRow->RFID) : '';
                if ($r !== '') {
                    $byBranch[(string) (int) $ucRow->CampusID] = $r;
                }
            }
            $extras[$tid]['rfid_by_branch'] = $byBranch;
            $extras[$tid]['rfid'] = !empty($byBranch) ? (string) reset($byBranch) : '';

            $ids = $tsRows->get($tid, collect())->pluck('subject_id')->all();
            $extras[$tid]['subject_ids'] = array_map('intval', $ids);
            $extras[$tid]['subject_names'] = array_values(array_map(fn ($id) => $subjectNames[$id] ?? (string) $id, $ids));
        }

        $scopeMap = TeacherScopeService::getScopesForTeachers($teacherIds);
        foreach ($teacherIds as $tid) {
            $extras[$tid]['subject_level_scopes'] = $scopeMap[$tid] ?? [];
        }

        return $extras;
    }

    public static function mainCampusId(int $userId): ?int
    {
        $campusId = DB::table('UserCampus')->where('UserID', $userId)->value('CampusID');

        return $campusId !== null ? (int) $campusId : null;
    }
}
