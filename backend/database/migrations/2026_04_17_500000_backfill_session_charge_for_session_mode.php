<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill: session mode 課程的 session_charge 應永遠等於合約 Rate
 *
 * 背景：在導入「按堂計費固定金額」修正前，
 * `ClassSessionController::syncSessionChargeForTimeChange` 對 rate_unit='session'
 * 也套用時長比例縮放（`Rate × actual / standard`），造成兩筆不一致：
 *   - `ClassSession.session_charge` 被寫成縮放後的值
 *   - `StudentClass.Charge` 被依此 delta 錯誤加減
 *
 * 本 migration 一次性把受汙染的資料拉回正確狀態：
 *   - session mode 的 `session_charge` 一律強制為 Rate
 *   - `StudentClass.Charge` 按「目標 Rate − 既存 session_charge」加回差額
 *
 * 所有被調整的 row 在寫入前會先備份到 session_charge_backfill_log，
 * 支援 down() 回滾。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ClassSession') || !Schema::hasTable('StudentClass')) {
            return;
        }

        if (!Schema::hasColumn('ClassSession', 'session_charge')) {
            return;
        }

        if (!Schema::hasTable('session_charge_backfill_log')) {
            Schema::create('session_charge_backfill_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('class_session_id');
                $table->unsignedBigInteger('student_class_id');
                $table->integer('old_session_charge')->nullable();
                $table->integer('new_session_charge')->nullable();
                $table->integer('charge_delta')->default(0);
                $table->string('reason', 64)->default('session_mode_fixed_rate_backfill');
                $table->timestamp('applied_at')->useCurrent();
                $table->index('class_session_id');
                $table->index('student_class_id');
            });
        }

        // 找出所有受影響 rows：session mode + session_charge IS NOT NULL + session_charge != round(Rate)
        // 對 rate_unit 為 null 的舊資料視為 session mode（系統預設）
        $rows = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereNotNull('cs.session_charge')
            ->where(function ($q) {
                $q->where('sc.rate_unit', 'session')
                  ->orWhereNull('sc.rate_unit');
            })
            ->where(function ($q) {
                $q->whereRaw('cs.session_charge <> ROUND(sc.Rate)');
            })
            ->select([
                'cs.id as session_id',
                'cs.StudentClassID as student_class_id',
                'cs.session_charge as old_session_charge',
                'sc.Rate as rate',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($rows) {
            $now = date('Y-m-d H:i:s');

            // 以 StudentClass 為單位累計 charge delta，避免同一筆 StudentClass 多次 UPDATE
            $deltasByStudentClass = [];

            foreach ($rows as $row) {
                $newSessionCharge = (int) round((float) $row->rate);
                $oldSessionCharge = (int) $row->old_session_charge;
                $delta = $newSessionCharge - $oldSessionCharge;
                $scId = (int) $row->student_class_id;

                DB::table('session_charge_backfill_log')->insert([
                    'class_session_id' => (int) $row->session_id,
                    'student_class_id' => $scId,
                    'old_session_charge' => $oldSessionCharge,
                    'new_session_charge' => $newSessionCharge,
                    'charge_delta' => $delta,
                    'reason' => 'session_mode_fixed_rate_backfill',
                    'applied_at' => $now,
                ]);

                DB::table('ClassSession')
                    ->where('id', (int) $row->session_id)
                    ->update(['session_charge' => $newSessionCharge]);

                if (!isset($deltasByStudentClass[$scId])) {
                    $deltasByStudentClass[$scId] = 0;
                }
                $deltasByStudentClass[$scId] += $delta;
            }

            foreach ($deltasByStudentClass as $scId => $delta) {
                if ($delta === 0) {
                    continue;
                }
                $sc = DB::table('StudentClass')->where('ID', $scId)->first(['Charge']);
                if (!$sc) {
                    continue;
                }
                $newCharge = max(0, (int) ($sc->Charge ?? 0) + $delta);
                DB::table('StudentClass')->where('ID', $scId)->update(['Charge' => $newCharge]);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('session_charge_backfill_log')) {
            return;
        }

        $logs = DB::table('session_charge_backfill_log')
            ->where('reason', 'session_mode_fixed_rate_backfill')
            ->orderBy('id', 'desc')
            ->get();

        if ($logs->isEmpty()) {
            Schema::dropIfExists('session_charge_backfill_log');
            return;
        }

        DB::transaction(function () use ($logs) {
            foreach ($logs as $log) {
                DB::table('ClassSession')
                    ->where('id', (int) $log->class_session_id)
                    ->update(['session_charge' => $log->old_session_charge]);
            }

            // 將 StudentClass.Charge 扣回：-sum(delta)
            $revertByStudentClass = [];
            foreach ($logs as $log) {
                $scId = (int) $log->student_class_id;
                if (!isset($revertByStudentClass[$scId])) {
                    $revertByStudentClass[$scId] = 0;
                }
                $revertByStudentClass[$scId] -= (int) $log->charge_delta;
            }
            foreach ($revertByStudentClass as $scId => $revertDelta) {
                if ($revertDelta === 0) {
                    continue;
                }
                $sc = DB::table('StudentClass')->where('ID', $scId)->first(['Charge']);
                if (!$sc) {
                    continue;
                }
                $newCharge = max(0, (int) ($sc->Charge ?? 0) + $revertDelta);
                DB::table('StudentClass')->where('ID', $scId)->update(['Charge' => $newCharge]);
            }
        });

        Schema::dropIfExists('session_charge_backfill_log');
    }
};
