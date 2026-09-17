<?php

namespace App\Services;

use App\Models\GradePromotionBatch;
use App\Models\GradePromotionResult;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Campus grade promotion preview + Director confirm (in-app #297 Phase A).
 * H3 graduation changes student status only — no course inactivation.
 */
class GradePromotionService
{
    public function adminDateForYear(int $year): Carbon
    {
        $tz = (string) config('grade_promotion.timezone', 'Asia/Taipei');
        $month = (int) config('grade_promotion.admin_month', 8);
        $day = (int) config('grade_promotion.admin_day', 1);

        return Carbon::create($year, $month, $day, 0, 0, 0, $tz)->startOfDay();
    }

    public function defaultSeasonYear(?Carbon $now = null): int
    {
        $tz = (string) config('grade_promotion.timezone', 'Asia/Taipei');
        $now = ($now ?? Carbon::now($tz))->timezone($tz);

        return (int) $now->year;
    }

    /**
     * @return list<string>
     */
    public function gradeOrder(): array
    {
        return array_values(config('grade_promotion.grade_order', []));
    }

    public function nextGrade(?string $grade): ?string
    {
        $grade = $grade ? strtoupper(trim($grade)) : '';
        $order = $this->gradeOrder();
        $idx = array_search($grade, $order, true);
        if ($idx === false || $idx >= count($order) - 1) {
            return null;
        }

        return $order[$idx + 1];
    }

    public function classIdToGrade(?int $classId): string
    {
        $map = array_flip(config('grade_promotion.grade_to_class_id', []));

        return $map[$classId] ?? '';
    }

    public function gradeToClassId(string $grade): ?int
    {
        $map = config('grade_promotion.grade_to_class_id', []);
        $grade = strtoupper(trim($grade));

        return isset($map[$grade]) ? (int) $map[$grade] : null;
    }

    /**
     * @return list<array{
     *   student_id: int,
     *   name: string,
     *   from_grade: string,
     *   to_grade: ?string,
     *   graduated: bool,
     *   actionable: bool,
     *   already_promoted: bool,
     *   reason: ?string
     * }>
     */
    public function preview(int $campusId, int $seasonYear): array
    {
        $already = $this->alreadyPromotedStudentIds($campusId, $seasonYear);

        $rows = Student::query()
            ->where('CampusID', $campusId)
            ->where(function ($q) {
                $q->where('status', 'active')->orWhereNull('status')->orWhere('status', '');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'ClassID', 'status']);

        $out = [];
        foreach ($rows as $student) {
            $from = $this->classIdToGrade($student->ClassID !== null ? (int) $student->ClassID : null);
            $to = $this->nextGrade($from !== '' ? $from : null);
            $graduated = $to === null && $from === 'H3';
            $promoted = in_array((int) $student->id, $already, true);

            $actionable = !$promoted && ($to !== null || $graduated);
            $reason = null;
            if ($promoted) {
                $reason = 'already_promoted';
            } elseif ($from === '' || ($to === null && !$graduated)) {
                $reason = 'unknown_grade';
                $actionable = false;
            }

            $out[] = [
                'student_id' => (int) $student->id,
                'name' => (string) $student->name,
                'from_grade' => $from,
                'to_grade' => $to,
                'graduated' => $graduated,
                'actionable' => $actionable,
                'already_promoted' => $promoted,
                'reason' => $reason,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $excludeStudentIds
     * @param  array<int, array{to_grade?: string, graduate?: bool}>  $corrections  keyed by student_id
     * @return array{batch: GradePromotionBatch, results: list<array<string, mixed>>, replayed: bool}
     */
    public function confirm(
        int $campusId,
        int $seasonYear,
        string $idempotencyKey,
        int $actorUserId,
        array $excludeStudentIds = [],
        array $corrections = []
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency key is required (max 64 chars).'],
            ]);
        }

        $existing = GradePromotionBatch::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ((int) $existing->campus_id !== $campusId || (int) $existing->season_year !== $seasonYear) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Idempotency key already used for a different campus/season.'],
                ]);
            }

            return [
                'batch' => $existing,
                'results' => $this->serializeResultsForBatch((int) $existing->id),
                'replayed' => true,
            ];
        }

        $exclude = array_values(array_unique(array_map('intval', $excludeStudentIds)));
        $preview = $this->preview($campusId, $seasonYear);
        $byId = [];
        foreach ($preview as $row) {
            $byId[$row['student_id']] = $row;
        }

        $planned = [];
        foreach ($preview as $row) {
            $sid = $row['student_id'];
            if (in_array($sid, $exclude, true)) {
                continue;
            }
            if (!$row['actionable']) {
                continue;
            }

            $toGrade = $row['to_grade'];
            $graduate = $row['graduated'];
            if (isset($corrections[$sid]) && is_array($corrections[$sid])) {
                $corr = $corrections[$sid];
                if (!empty($corr['graduate'])) {
                    $graduate = true;
                    $toGrade = null;
                } elseif (!empty($corr['to_grade'])) {
                    $candidate = strtoupper(trim((string) $corr['to_grade']));
                    if ($this->gradeToClassId($candidate) === null) {
                        throw ValidationException::withMessages([
                            'corrections' => ["Invalid to_grade for student {$sid}."],
                        ]);
                    }
                    $toGrade = $candidate;
                    $graduate = false;
                }
            }

            $planned[] = [
                'student_id' => $sid,
                'from_grade' => $row['from_grade'],
                'to_grade' => $toGrade,
                'graduated' => $graduate,
            ];
        }

        if ($planned === []) {
            throw ValidationException::withMessages([
                'students' => ['No actionable students to promote.'],
            ]);
        }

        return DB::transaction(function () use ($campusId, $seasonYear, $idempotencyKey, $actorUserId, $planned) {
            // Re-check idempotency inside transaction.
            $again = GradePromotionBatch::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($again) {
                return [
                    'batch' => $again,
                    'results' => $this->serializeResultsForBatch((int) $again->id),
                    'replayed' => true,
                ];
            }

            $batch = GradePromotionBatch::create([
                'campus_id' => $campusId,
                'season_year' => $seasonYear,
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actorUserId,
                'status' => 'completed',
                'summary' => [
                    'planned' => count($planned),
                    'applied' => 0,
                    'graduated' => 0,
                    'promoted' => 0,
                ],
                'created_at' => now(),
            ]);

            $applied = 0;
            $graduatedCount = 0;
            $promotedCount = 0;
            $resultRows = [];

            foreach ($planned as $item) {
                $student = Student::query()
                    ->where('id', $item['student_id'])
                    ->where('CampusID', $campusId)
                    ->lockForUpdate()
                    ->first();
                if (!$student) {
                    continue;
                }

                if (GradePromotionResult::query()
                    ->where('student_id', $student->id)
                    ->where('season_year', $seasonYear)
                    ->exists()) {
                    // Race / already promoted — skip without failing the whole batch.
                    continue;
                }

                if ($item['graduated']) {
                    $student->status = 'graduated';
                    // Keep ClassID as H3; status carries graduation. No course Stop mutation.
                    $student->save();
                    $graduatedCount++;
                } else {
                    $classId = $this->gradeToClassId((string) $item['to_grade']);
                    if ($classId === null) {
                        continue;
                    }
                    $student->ClassID = $classId;
                    $student->save();
                    // Sync GradeID on active courses only (Stop=0); do not inactivate.
                    if (Schema::hasColumn('StudentClass', 'GradeID')) {
                        DB::table('StudentClass')
                            ->where('StudentID', $student->id)
                            ->where(function ($q) {
                                $q->where('Stop', 0)->orWhereNull('Stop');
                            })
                            ->update(['GradeID' => $classId]);
                    }
                    $promotedCount++;
                }

                GradePromotionResult::create([
                    'batch_id' => $batch->id,
                    'student_id' => $student->id,
                    'season_year' => $seasonYear,
                    'from_grade' => $item['from_grade'] ?: null,
                    'to_grade' => $item['graduated'] ? null : $item['to_grade'],
                    'graduated' => (bool) $item['graduated'],
                    'created_at' => now(),
                ]);
                $applied++;
                $resultRows[] = $item;
            }

            $batch->summary = [
                'planned' => count($planned),
                'applied' => $applied,
                'graduated' => $graduatedCount,
                'promoted' => $promotedCount,
            ];
            $batch->save();

            return [
                'batch' => $batch->fresh(),
                'results' => $this->serializeResultsForBatch((int) $batch->id),
                'replayed' => false,
            ];
        });
    }

    /**
     * @return list<int>
     */
    private function alreadyPromotedStudentIds(int $campusId, int $seasonYear): array
    {
        if (!Schema::hasTable('grade_promotion_results')) {
            return [];
        }

        return GradePromotionResult::query()
            ->where('season_year', $seasonYear)
            ->whereIn('student_id', function ($q) use ($campusId) {
                $q->select('id')->from('Student')->where('CampusID', $campusId);
            })
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeResultsForBatch(int $batchId): array
    {
        return GradePromotionResult::query()
            ->where('batch_id', $batchId)
            ->orderBy('id')
            ->get()
            ->map(static fn (GradePromotionResult $r) => [
                'student_id' => (int) $r->student_id,
                'from_grade' => $r->from_grade,
                'to_grade' => $r->to_grade,
                'graduated' => (bool) $r->graduated,
            ])
            ->all();
    }
}
