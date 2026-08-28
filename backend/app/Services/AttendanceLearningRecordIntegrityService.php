<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\StudentClass;
use App\Support\AttendanceStatus;
use Illuminate\Support\Facades\DB;

class AttendanceLearningRecordIntegrityService
{
    /** @return list<string> */
    public static function fillableStatuses(): array
    {
        return AttendanceStatus::requiresLogSessionStatuses();
    }

    /** @return list<string> */
    public static function nonAttendanceStatuses(): array
    {
        return array_merge(['scheduled', 'absent'], CourseLeaveCascadeService::NON_BILLABLE_STATUSES, ['suspended']);
    }

    public function scan(?int $campusId = null, int $limit = 500): array
    {
        $limit = max(1, min($limit, 2000));
        $fillable = self::fillableStatuses();

        $base = static function () use ($campusId) {
            $query = DB::table('ClassSession as cs')
                ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
                ->leftJoin('Student as st', 'st.id', '=', 'sc.StudentID');
            if ($campusId !== null && $campusId > 0) {
                $query->where('st.CampusID', $campusId);
            }
            return $query;
        };

        $select = [
            'cs.id as session_id',
            'cs.StudentClassID as class_id',
            'sc.StudentID as student_id',
            'st.CampusID as campus_id',
            'cs.SessionDate as session_date',
            'cs.StartTime as start_time',
            'cs.EndTime as end_time',
            'cs.Status as session_status',
        ];

        $missing = $base()
            ->whereIn(DB::raw('LOWER(cs.Status)'), $fillable)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('LearningRecord as lr')
                    ->whereColumn('lr.ClassSessionID', 'cs.id')
                    ->whereNull('lr.VoidedAt');
            })
            ->orderBy('cs.id')
            ->limit($limit)
            ->get($select)
            ->map(fn ($row): array => (array) $row)
            ->values()
            ->all();

        $ghost = $base()
            ->whereNotIn(DB::raw("LOWER(COALESCE(cs.Status, ''))"), $fillable)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('LearningRecord as lr')
                    ->whereColumn('lr.ClassSessionID', 'cs.id')
                    ->whereNull('lr.VoidedAt');
            })
            ->orderBy('cs.id')
            ->limit($limit)
            ->get($select)
            ->map(fn ($row): array => (array) $row)
            ->values()
            ->all();

        $duplicates = $base()
            ->join('LearningRecord as lr', 'lr.ClassSessionID', '=', 'cs.id')
            ->whereNull('lr.VoidedAt')
            ->groupBy('cs.id', 'cs.StudentClassID', 'sc.StudentID', 'st.CampusID', 'cs.SessionDate', 'cs.StartTime', 'cs.EndTime', 'cs.Status')
            ->havingRaw('COUNT(lr.id) > 1')
            ->orderBy('cs.id')
            ->limit($limit)
            ->get(array_merge($select, [DB::raw('COUNT(lr.id) as active_learning_record_count')]))
            ->map(fn ($row): array => (array) $row)
            ->values()
            ->all();

        $count = static function ($query): int {
            return (int) $query->count();
        };

        $missingCount = $count($base()
            ->whereIn(DB::raw('LOWER(cs.Status)'), $fillable)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('LearningRecord as lr')
                    ->whereColumn('lr.ClassSessionID', 'cs.id')->whereNull('lr.VoidedAt');
            }));
        $ghostCount = $count($base()
            ->whereNotIn(DB::raw("LOWER(COALESCE(cs.Status, ''))"), $fillable)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('LearningRecord as lr')
                    ->whereColumn('lr.ClassSessionID', 'cs.id')->whereNull('lr.VoidedAt');
            }));
        $duplicateCount = (int) DB::table('LearningRecord as lr')
            ->join('ClassSession as cs', 'cs.id', '=', 'lr.ClassSessionID')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->leftJoin('Student as st', 'st.id', '=', 'sc.StudentID')
            ->whereNull('lr.VoidedAt')
            ->when($campusId !== null && $campusId > 0, fn ($query) => $query->where('st.CampusID', $campusId))
            ->groupBy('lr.ClassSessionID')
            ->havingRaw('COUNT(lr.id) > 1')
            ->get(['lr.ClassSessionID'])
            ->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                'missing_learning_records' => $missingCount,
                'ghost_learning_records' => $ghostCount,
                'duplicate_active_learning_records' => $duplicateCount,
            ],
            'missing_learning_records' => $missing,
            'ghost_learning_records' => $ghost,
            'duplicate_active_learning_records' => $duplicates,
        ];
    }

    public function repair(int $actorUserId = 0): array
    {
        $before = $this->scan();
        $created = 0;
        $voided = 0;
        $blocked = [];
        $backfill = app(LearningRecordBackfillService::class);

        foreach ($before['missing_learning_records'] as $row) {
            $sessionId = (int) ($row['session_id'] ?? 0);
            $session = ClassSession::query()->find($sessionId);
            $sc = $session ? StudentClass::query()->find((int) $session->StudentClassID) : null;
            if (!$session || !$sc) {
                $blocked[] = $sessionId;
                continue;
            }
            DB::transaction(function () use ($backfill, $sessionId, $sc, &$created, &$blocked): void {
                $locked = ClassSession::query()->whereKey($sessionId)->lockForUpdate()->first();
                if (!$locked || !in_array(strtolower((string) $locked->Status), self::fillableStatuses(), true)) {
                    return;
                }
                $backfill->createPendingForSession($sc, $locked, DB::table('Subject')->pluck('Subject_Name', 'id')->all());
                if (LearningRecord::query()->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->exists()) {
                    $created++;
                } else {
                    $blocked[] = $sessionId;
                }
            });
        }

        foreach ($before['ghost_learning_records'] as $row) {
            $sessionId = (int) ($row['session_id'] ?? 0);
            $voided += DB::transaction(function () use ($sessionId, $actorUserId): int {
                $session = ClassSession::query()->whereKey($sessionId)->lockForUpdate()->first();
                if (!$session || in_array(strtolower((string) $session->Status), self::fillableStatuses(), true)) {
                    return 0;
                }
                $status = strtolower((string) $session->Status);
                if ($status === 'cancelled') {
                    $reason = CourseLeaveCascadeService::VOID_REASON_CANCELLED;
                } elseif (in_array($status, CourseLeaveCascadeService::NON_BILLABLE_STATUSES, true)) {
                    $reason = CourseLeaveCascadeService::VOID_REASON_LEAVE;
                } else {
                    $reason = '未到課狀態：評量一致性修復';
                }
                $beforeCount = LearningRecord::query()->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->count();
                CourseLeaveCascadeService::voidLiveArtifactsForNonAttendance($sessionId, $reason, $actorUserId > 0 ? $actorUserId : null);
                return $beforeCount > 0 ? 1 : 0;
            });
        }

        return [
            'created' => $created,
            'voided' => $voided,
            'blocked' => array_values(array_unique($blocked)),
            'after' => $this->scan(),
        ];
    }
}
