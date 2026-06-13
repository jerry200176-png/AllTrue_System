<?php

namespace App\Support;

/**
 * #765 出缺席狀態語意化 — 單一真相（Single Source of Truth）。
 *
 * 參考競品 backerwebapp 的結構化狀態元資料。每個 StudentSingIn.Status 帶語意旗標，
 * 讓「扣堂 / 計薪 / 是否需教學日誌」由此一處決定，避免散落各 controller/service 漂移。
 *
 * 金流語意（與既有行為對齊）：
 * - deductible：是否扣該生堂數（對應 ClassSession.Status ∈ 可扣集）。
 * - payable：是否計入老師鐘點（FinanceController payroll 可計集）。
 * - 兩者刻意可不同：值班 duty 計薪但不扣堂。
 * - session_status：寫回 ClassSession.Status 的字串（扣堂/計薪查詢以此為準）。
 *
 * 既有四狀態（present/late/absent/leave）行為完全不變；其餘為 #765 新增。
 */
final class AttendanceStatus
{
    /** @var array<string, array{label:string, attended:bool, deductible:bool, payable:bool, requires_log:bool, session_status:string, is_trial?:bool, is_tutoring?:bool, is_duty?:bool}> */
    public const META = [
        // ── 既有（行為不變）──────────────────────────────────────────
        'present' => ['label' => '到班', 'attended' => true,  'deductible' => true,  'payable' => true,  'requires_log' => true,  'session_status' => 'attended'],
        'late'    => ['label' => '遲到', 'attended' => true,  'deductible' => true,  'payable' => true,  'requires_log' => true,  'session_status' => 'late'],
        'absent'  => ['label' => '缺席', 'attended' => false, 'deductible' => false, 'payable' => false, 'requires_log' => false, 'session_status' => 'absent'],
        'leave'   => ['label' => '請假', 'attended' => false, 'deductible' => false, 'payable' => false, 'requires_log' => false, 'session_status' => 'leave'],

        // ── #765 新增 ────────────────────────────────────────────────
        'trial'           => ['label' => '試聽有到', 'attended' => true,  'deductible' => false, 'payable' => false, 'requires_log' => true,  'session_status' => 'trial',           'is_trial' => true],
        'trial_absent'    => ['label' => '試聽未到', 'attended' => false, 'deductible' => false, 'payable' => false, 'requires_log' => false, 'session_status' => 'trial_absent',    'is_trial' => true],
        'tutoring'        => ['label' => '輔導有到', 'attended' => true,  'deductible' => false, 'payable' => false, 'requires_log' => true,  'session_status' => 'tutoring_attend', 'is_tutoring' => true],
        'tutoring_absent' => ['label' => '輔導未到', 'attended' => false, 'deductible' => false, 'payable' => false, 'requires_log' => false, 'session_status' => 'tutoring_absent', 'is_tutoring' => true],
        'duty'            => ['label' => '值班',     'attended' => true,  'deductible' => false, 'payable' => true,  'requires_log' => false, 'session_status' => 'duty',            'is_duty' => true],
        'makeup'          => ['label' => '補課',     'attended' => true,  'deductible' => true,  'payable' => true,  'requires_log' => true,  'session_status' => 'attended'],
        'suspended'       => ['label' => '停課',     'attended' => false, 'deductible' => false, 'payable' => false, 'requires_log' => false, 'session_status' => 'suspended'],
    ];

    /** 合法的 StudentSingIn.Status 代碼（含相容別名 excused→leave 在呼叫端處理）。 */
    public static function codes(): array
    {
        return array_keys(self::META);
    }

    public static function exists(string $code): bool
    {
        return isset(self::META[$code]);
    }

    public static function label(string $code): string
    {
        return self::META[$code]['label'] ?? $code;
    }

    /** 寫回 ClassSession.Status 的字串。 */
    public static function sessionStatus(string $code): string
    {
        return self::META[$code]['session_status'] ?? 'attended';
    }

    public static function isDeductible(string $code): bool
    {
        return (bool) (self::META[$code]['deductible'] ?? false);
    }

    public static function isPayable(string $code): bool
    {
        return (bool) (self::META[$code]['payable'] ?? false);
    }

    public static function requiresLog(string $code): bool
    {
        return (bool) (self::META[$code]['requires_log'] ?? false);
    }

    /** StudentSingIn.Status 代碼中，會「扣堂」的集合（即時扣堂判斷用）。 */
    public static function deductibleCodes(): array
    {
        return array_keys(array_filter(self::META, fn ($m) => $m['deductible']));
    }

    /** ClassSession.Status 中，需要老師填寫教學日誌（LearningRecord）的集合（#768）。 */
    public static function requiresLogSessionStatuses(): array
    {
        $out = [];
        foreach (self::META as $m) {
            if ($m['requires_log']) {
                $out[$m['session_status']] = true;
            }
        }
        $out['completed'] = true; // 歷史 attended 同義
        return array_keys($out);
    }

    /** ClassSession.Status 中，計入老師鐘點（payroll）的集合。 */
    public static function payableSessionStatuses(): array
    {
        $out = [];
        foreach (self::META as $m) {
            if ($m['payable']) {
                $out[$m['session_status']] = true;
            }
        }
        // 'completed' 為歷史寫法（=attended 的同義），payroll 既有集合含之。
        $out['completed'] = true;
        return array_keys($out);
    }
}
