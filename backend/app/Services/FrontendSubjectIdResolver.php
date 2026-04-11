<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FrontendSubjectIdResolver
{
    private const SUBJECT_CHINESE_MAP = [
        'Chinese' => '國文',
        'English' => '英文',
        'Math' => '數學',
        'Physics' => '物理',
        'Chemistry' => '化學',
        'Science' => '理化',
        'Biology' => '生物',
        'Social' => '社會',
    ];

    /**
     * Resolve a frontend subject key (e.g. 'Math') to a Subject table ID.
     *
     * Priority: exact English match → Chinese LIKE → BaseData LIKE.
     * Returns null when unresolvable (caller should 422 or handle gracefully).
     */
    public static function resolve(string $frontendSubject): ?int
    {
        $chineseName = self::SUBJECT_CHINESE_MAP[$frontendSubject] ?? $frontendSubject;

        $id = null;

        if (Schema::hasTable('Subject')) {
            $id = DB::table('Subject')->where('Subject_Name', $frontendSubject)->value('id')
                ?? DB::table('Subject')->where('Subject_Name', 'like', "%{$chineseName}%")->value('id');
        }

        if (!$id && Schema::hasTable('BaseData')) {
            $id = DB::table('BaseData')
                ->where('Name', '課程')
                ->where('Val', 'like', "%{$chineseName}%")
                ->value('id');
        }

        if (!$id) {
            Log::warning("[FrontendSubjectIdResolver] Cannot resolve SubjectID for '{$frontendSubject}' (mapped='{$chineseName}')");
            return null;
        }

        return (int) $id;
    }

    /**
     * Resolve a Subject table ID back to a display name.
     */
    public static function resolveName(int $subjectId, string $fallback): string
    {
        $name = null;

        if (Schema::hasTable('Subject')) {
            $name = DB::table('Subject')->where('id', $subjectId)->value('Subject_Name');
        }

        if (!$name && Schema::hasTable('BaseData')) {
            $name = DB::table('BaseData')->where('Name', '課程')->where('id', $subjectId)->value('Val');
        }

        return (string) ($name ?: $fallback);
    }
}
