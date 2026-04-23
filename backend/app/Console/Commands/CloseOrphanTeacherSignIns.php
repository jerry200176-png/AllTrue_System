<?php

namespace App\Console\Commands;

use App\Models\TeacherSignIn;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 每日凌晨自動補登 TeacherSingIn.SignOutDT。
 *
 * 孤兒定義：SignOutDT = null 且 DATE(SignInDT) < today（前日或更早未刷退記錄）
 *
 * 補登邏輯：
 *   固定補 SignInDT 當日 23:59:00（老師無排課對應，無法查 ClassSession）
 *   若 SignInDT >= 23:59，fallback 為 SignInDT + 1 小時
 *
 * 參考：CloseOrphanStudentSignIns（學生版同模式）
 */
class CloseOrphanTeacherSignIns extends Command
{
    protected $signature   = 'teacher-signin:close-orphans';
    protected $description = 'Close orphan TeacherSingIn records (SignOutDT=null from previous days)';

    public function handle(): int
    {
        $today = Carbon::today();

        $orphans = TeacherSignIn::query()
            ->whereNull('SignOutDT')
            ->where('SignInDT', '<', $today->toDateTimeString())
            ->get();

        $closed = 0;

        foreach ($orphans as $orphan) {
            $signInDate = Carbon::parse($orphan->SignInDT)->toDateString();
            $signInDT   = Carbon::parse($orphan->SignInDT);
            $signOutDT  = Carbon::parse($signInDate . ' 23:59:00');

            // 防呆：SignOutDT 不應早於或等於 SignInDT
            if ($signOutDT->lte($signInDT)) {
                $signOutDT = $signInDT->copy()->addHour();
            }

            $orphan->SignOutDT = $signOutDT->toDateTimeString();
            $orphan->Status    = 'adjusted';
            $orphan->Memo      = '系統自動補登簽退';
            $orphan->MDT       = now()->toDateTimeString();
            $orphan->save();

            Log::info('teacher_signin_autoclosed', [
                'teacher_signin_id' => $orphan->id,
                'teacher_id'        => $orphan->TeacherID,
                'campus_id'         => $orphan->CampusID,
                'sign_in_dt'        => $orphan->SignInDT,
                'sign_out_dt'       => $signOutDT->toDateTimeString(),
            ]);

            $closed++;
        }

        $this->info("Closed {$closed} orphan TeacherSingIn record(s).");

        return self::SUCCESS;
    }
}
