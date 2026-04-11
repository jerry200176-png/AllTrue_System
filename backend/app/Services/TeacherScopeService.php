<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TeacherScopeService
{
    private const GRADE_LEVEL_MAP = [
        'P1' => 'elementary', 'P2' => 'elementary', 'P3' => 'elementary',
        'P4' => 'elementary', 'P5' => 'elementary', 'P6' => 'elementary',
        'J1' => 'junior', 'J2' => 'junior', 'J3' => 'junior',
        'H1' => 'high', 'H2' => 'high', 'H3' => 'high',
    ];

    private const GRADE_ID_LEVEL_MAP = [
        1 => 'elementary', 2 => 'elementary', 3 => 'elementary',
        4 => 'elementary', 5 => 'elementary', 6 => 'elementary',
        7 => 'junior', 8 => 'junior', 9 => 'junior',
        10 => 'high', 11 => 'high', 12 => 'high',
    ];

    private const LEVEL_LABELS = [
        'elementary' => '國小',
        'junior' => '國中',
        'high' => '高中',
    ];

    /**
     * Check whether the teacher is scoped for the given subject + student level.
     *
     * @return array{ok: bool, warnings: string[], matched_scopes: array}
     */
    public static function check(int $teacherId, int $subjectId, $gradeOrLevel = null): array
    {
        if (!Schema::hasTable('teacher_subject_levels')) {
            return ['ok' => true, 'warnings' => [], 'matched_scopes' => []];
        }

        $level = self::resolveLevel($gradeOrLevel);
        $hasAnyScope = DB::table('teacher_subject_levels')
            ->where('teacher_id', $teacherId)
            ->exists();

        if (!$hasAnyScope) {
            return [
                'ok' => true,
                'warnings' => ['此老師尚未設定授課學段，建議至老師管理頁補齊。'],
                'matched_scopes' => [],
            ];
        }

        $query = DB::table('teacher_subject_levels')
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId);

        if ($level) {
            $query->where('level', $level);
        }

        $matched = $query->get()->map(fn ($row) => [
            'subject_id' => (int) $row->subject_id,
            'level' => $row->level,
        ])->all();

        if (!empty($matched)) {
            return ['ok' => true, 'warnings' => [], 'matched_scopes' => $matched];
        }

        $warnings = [];

        $rawSubjectName = DB::table('Subject')->where('id', $subjectId)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $subjectId)->value('Val');
        $subjectDisplayNames = [
            'Chinese' => '國文', 'English' => '英文', 'Math' => '數學',
            'Physics' => '物理', 'Chemistry' => '化學', 'Science' => '理化',
            'Biology' => '生物', 'Social' => '社會',
        ];
        $subjectName = ($rawSubjectName ? ($subjectDisplayNames[$rawSubjectName] ?? $rawSubjectName) : null)
            ?? "科目#{$subjectId}";
        $teacherName = DB::table('User')->where('id', $teacherId)->value('Name') ?? "老師#{$teacherId}";
        $levelLabel = $level ? (self::LEVEL_LABELS[$level] ?? $level) : '未知學段';

        $hasSubject = DB::table('teacher_subject_levels')
            ->where('teacher_id', $teacherId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (!$hasSubject) {
            $warnings[] = "{$teacherName} 的授課設定中沒有「{$subjectName}」科目。";
        } elseif ($level) {
            $warnings[] = "{$teacherName} 可教「{$subjectName}」但未包含「{$levelLabel}」學段。";
        }

        return ['ok' => false, 'warnings' => $warnings, 'matched_scopes' => []];
    }

    /**
     * Bulk-fetch scopes for a list of teacher IDs.
     *
     * @return array<int, array<int, array{subject_id:int, level:string}>>
     */
    public static function getScopesForTeachers(array $teacherIds): array
    {
        if (empty($teacherIds) || !Schema::hasTable('teacher_subject_levels')) {
            return [];
        }

        $rows = DB::table('teacher_subject_levels')
            ->whereIn('teacher_id', $teacherIds)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $tid = (int) $row->teacher_id;
            if (!isset($result[$tid])) {
                $result[$tid] = [];
            }
            $result[$tid][] = [
                'subject_id' => (int) $row->subject_id,
                'level' => $row->level,
            ];
        }

        return $result;
    }

    /**
     * Replace all scopes for a teacher.
     *
     * @param array<int, array{subject_id:int, level:string}> $scopes
     */
    public static function replaceScopes(int $teacherId, array $scopes): void
    {
        if (!Schema::hasTable('teacher_subject_levels')) {
            return;
        }

        DB::table('teacher_subject_levels')->where('teacher_id', $teacherId)->delete();

        $now = now();
        foreach ($scopes as $scope) {
            $subjectId = (int) ($scope['subject_id'] ?? 0);
            $level = (string) ($scope['level'] ?? '');
            if ($subjectId <= 0 || !in_array($level, ['elementary', 'junior', 'high'], true)) {
                continue;
            }
            DB::table('teacher_subject_levels')->insertOrIgnore([
                'teacher_id' => $teacherId,
                'subject_id' => $subjectId,
                'level' => $level,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function resolveLevel($gradeOrLevel): ?string
    {
        if ($gradeOrLevel === null) {
            return null;
        }

        if (is_string($gradeOrLevel)) {
            $gradeOrLevel = trim($gradeOrLevel);
            if (isset(self::LEVEL_LABELS[$gradeOrLevel])) {
                return $gradeOrLevel;
            }
            if (isset(self::GRADE_LEVEL_MAP[$gradeOrLevel])) {
                return self::GRADE_LEVEL_MAP[$gradeOrLevel];
            }
        }

        if (is_numeric($gradeOrLevel)) {
            return self::GRADE_ID_LEVEL_MAP[(int) $gradeOrLevel] ?? null;
        }

        return null;
    }

    public static function levelLabel(string $level): string
    {
        return self::LEVEL_LABELS[$level] ?? $level;
    }
}
