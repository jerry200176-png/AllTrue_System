<?php

namespace App\Services;

use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Support\Facades\DB;

/**
 * 點名／刷卡成功後扣堂邏輯（堂數制扣 RemainingSessions，月結只加 UsedSessions）
 */
class SessionDeductionService
{
    /**
     * Deduct one session from StudentClass on attendance (點名成功才扣堂).
     * - Session mode (SessionCount > 0): decrement RemainingSessions, update Stop/Paid
     * - Monthly mode: increment UsedSessions only
     */
    public static function deductOnAttendance(StudentClass $sc, StudentSignIn $signIn): void
    {
        try {
            DB::transaction(function () use ($sc, $signIn) {
                $sc = StudentClass::where('ID', $sc->ID)->lockForUpdate()->first();
                if (!$sc) {
                    return;
                }

                $sc->UsedSessions = ($sc->UsedSessions ?? 0) + 1;

                $isSessionMode = ($sc->ScheduleMode === 'count' || (int) ($sc->SessionCount ?? 0) > 0);
                if ($isSessionMode) {
                    $sc->RemainingSessions = max(0, (int) ($sc->RemainingSessions ?? 0) - 1);
                    if ($sc->RemainingSessions <= 0) {
                        $sc->Stop = 1;
                    }
                    if ($sc->RemainingSessions <= 2) {
                        $sc->Paid = 0;
                    }
                }

                $sc->save();

                $signIn->SessionDeducted = true;
                $signIn->save();
            });
        } catch (\Throwable $e) {
            // Don't fail the sign-in if session deduction errors
        }
    }
}
