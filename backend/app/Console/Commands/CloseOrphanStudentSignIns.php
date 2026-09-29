<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\StudentSignIn;
use App\Models\Student;
use App\Services\StudentPresenceBackfillService;
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
 * 關閉後與自然刷退相同，呼叫 StudentPresenceBackfillService 對在場時段內
 * 開始、尚無有效簽到的堂次補出席並扣堂（#2809：忘記刷退不應漏算後續連堂）。冪等。
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
        $sourceCounts = [];

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

            // Only RFID-door rows prove presence; manual/absent rows must not trigger
            // deduction. Old backlog (>2 days) is closed but never retro-deducted.
            $isRfidPresence = in_array($orphan->Memo, ['swipe-rfid', 'self_study'], true)
                && $orphan->Status === 'present';
            $isRecent = $signInDT->gte($today->copy()->subDays(2));
            if ($isRfidPresence && $isRecent && ($student = Student::find($orphan->StudentID))) {
                StudentPresenceBackfillService::backfill(
                    $student,
                    $signInDT,
                    $signOutDT,
                    (int) ($orphan->CampusID ?: $student->CampusID)
                );
            }

            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
            $closed++;
        }

        ksort($sourceCounts);
        Log::info('orphan_signin_autoclose_summary', [
            'closed_count' => $closed,
            'source_counts' => $sourceCounts,
        ]);

        $this->info("Closed {$closed} orphan StudentSignIn record(s).");

        return self::SUCCESS;
    }
}
