<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 每日凌晨孤兒綁定清理與異常偵測（Phase 1-3）。
 *
 * 孤兒綁定：student_line_bindings 中關聯的學生已不存在（Student 表已刪）
 * 或已停用（Student.enable = 0）。這些綁定無實際用途且佔用資料行，
 * 因此自動清除並記錄於 Log。
 *
 * 異常偵測：同一學生有超過 3 個 LINE user 綁定 → 可能有誤綁/衝突，
 * 僅回報不處理，供主任審查。
 *
 * @see ADR-004 — Parent Binding Phase 1-3 架構設計
 */
class CleanupOrphanBindings extends Command
{
    protected $signature = 'bindings:cleanup-orphans';
    protected $description = 'Clean up orphan StudentLineBindings (deleted/disabled students) and report anomaly (>3 LINE users per student).';

    public function handle(): int
    {
        $orphanDeleted = 0;
        $anomalyStudents = 0;

        // ── Phase 1: Clean up bindings to non-existent students ──
        DB::transaction(function () use (&$orphanDeleted) {
            $hardOrphans = StudentLineBinding::query()
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('Student')
                        ->whereColumn('Student.id', 'student_line_bindings.student_id');
                })
                ->get();

            foreach ($hardOrphans as $orphan) {
                Log::info('orphan_binding_deleted_student_missing', [
                    'binding_id'    => $orphan->id,
                    'student_id'    => $orphan->student_id,
                    'line_user_id'  => $orphan->line_user_id,
                    'campus_id'     => $orphan->campus_id,
                    'bound_at'      => $orphan->bound_at,
                    'reason'        => 'student_record_does_not_exist',
                ]);

                $orphan->delete();
                $orphanDeleted++;
            }
        });

        // ── Phase 2: Clean up bindings to disabled (enable=0) students ──
        DB::transaction(function () use (&$orphanDeleted) {
            $disabledOrphans = StudentLineBinding::query()
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('Student')
                        ->whereColumn('Student.id', 'student_line_bindings.student_id')
                        ->where('Student.enable', 0);
                })
                ->get();

            foreach ($disabledOrphans as $orphan) {
                Log::info('orphan_binding_deleted_student_disabled', [
                    'binding_id'    => $orphan->id,
                    'student_id'    => $orphan->student_id,
                    'line_user_id'  => $orphan->line_user_id,
                    'campus_id'     => $orphan->campus_id,
                    'bound_at'      => $orphan->bound_at,
                    'reason'        => 'student_disabled_enable_0',
                ]);

                $orphan->delete();
                $orphanDeleted++;
            }
        });

        // ── Phase 3: Anomaly detection — students with >3 LINE user bindings ──
        $anomalies = StudentLineBinding::query()
            ->select('student_id', DB::raw('COUNT(*) as binding_count'))
            ->groupBy('student_id')
            ->having('binding_count', '>', 3)
            ->get();

        foreach ($anomalies as $anomaly) {
            $student = Student::find($anomaly->student_id);

            Log::warning('binding_anomaly_excessive_line_users', [
                'student_id'     => $anomaly->student_id,
                'student_name'   => $student?->name ?? '(deleted)',
                'campus_id'      => $student?->CampusID ?? '(unknown)',
                'binding_count'  => $anomaly->binding_count,
            ]);

            $anomalyStudents++;
        }

        // ── Output summary ──
        $this->info(sprintf(
            'orphan_bindings_deleted=%d anomaly_students=%d',
            $orphanDeleted,
            $anomalyStudents
        ));

        if ($anomalyStudents > 0) {
            $this->warn("Found {$anomalyStudents} student(s) with >3 LINE user bindings — check logs for details.");
        }

        return self::SUCCESS;
    }
}
