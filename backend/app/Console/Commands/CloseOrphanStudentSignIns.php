<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * TD-008: 每日凌晨自動補上跨日孤兒 StudentSignIn.SignOutDT。
 *
 * 孤兒定義：SignOutDT = null 且 SignInDT < today（昨天或更早的未刷出記錄）
 *
 * 修復邏輯：
 *   1. 找該學生當日最後一堂 ClassSession.EndTime → 設為 SignOutDT
 *   2. 若無 ClassSession → fallback SignInDT 當日 22:00（補習班預設關門）
 *
 * 注意：此指令僅補 SignOutDT，presenceWindow backfill（若需要）
 *       請由 SwipeRfidController::backfillPresenceWindow 在下次自然刷退時處理，
 *       或另外排程觸發。
 */
class CloseOrphanStudentSignIns extends Command
{
    protected $signature   = 'student-signin:close-orphans';
    protected $description = 'Close orphan StudentSignIn records (SignOutDT=null from previous days)';

    public function handle(): int
    {
        $today = Carbon::today();

        $orphans = StudentSignIn::query()
            ->whereNull('SignOutDT')
            ->whereNull('VoidedAt')
            ->where('SignInDT', '<', $today->toDateTimeString())
            ->get();

        $closed = 0;

        foreach ($orphans as $orphan) {
            $signInDate = Carbon::parse($orphan->SignInDT)->toDateString();
            $signInDT   = Carbon::parse($orphan->SignInDT);

            // 找當日最後一堂課的 EndTime（依此學生的 StudentClass 關聯）
            $lastSession = ClassSession::query()
                ->whereHas('studentClass', fn ($q) =>
                    $q->where('StudentID', $orphan->StudentID)->where('Stop', 0)
                )
                ->whereDate('SessionDate', $signInDate)
                ->whereNotNull('EndTime')
                ->orderByRaw("TIME(EndTime) DESC")
                ->first();

            if ($lastSession) {
                $signOutDT = Carbon::parse($signInDate . ' ' . $lastSession->EndTime);
                $source    = 'class_session_end_time';
            } else {
                $signOutDT = Carbon::parse($signInDate . ' 22:00:00');
                $source    = 'fallback_2200';
            }

            // 防呆：SignOutDT 不應早於 SignInDT
            if ($signOutDT->lte($signInDT)) {
                $signOutDT = $signInDT->copy()->addHours(1);
                $source   .= '_adjusted';
            }

            $orphan->SignOutDT = $signOutDT;
            $orphan->MDT       = now();
            $orphan->save();

            Log::info('orphan_signin_autoclosed', [
                'student_signin_id' => $orphan->id,
                'student_id'        => $orphan->StudentID,
                'sign_in_dt'        => $orphan->SignInDT,
                'sign_out_dt'       => $signOutDT->toDateTimeString(),
                'source'            => $source,
            ]);

            $closed++;
        }

        $this->info("Closed {$closed} orphan StudentSignIn record(s).");

        return self::SUCCESS;
    }
}
