<?php

namespace App\Services\Billing;

use Carbon\Carbon;

/**
 * PHP twin of frontend/src/lib/billRows.js for the 帳務中心 Excel export: the kind, 狀態, 期間, 到期／逾期 and 未收
 * the director sees for one /alerts/tuition row. tests/fixtures/bill_rows_parity.json is run by both sides.
 */
final class BillRowText
{
    private const OWING = ['unpaid', 'partial', 'pending_reconciliation', 'monthly_due_soon', 'renew_needed'];
    private const PAID = ['paid', 'renew_needed', 'monthly_due_soon', 'free'];
    private const LABELS = [
        'partial' => '繳了一部分', 'pending_reconciliation' => '已結束，未收',
        'renew_needed' => '續課待處理', 'monthly_due_soon' => '月結將到期',
    ];

    /** @return array{kind: string, status: string, period: string, due: string, owed: int, paid: int, payable: int} */
    public static function describe(array $r, string $today): array
    {
        $items = self::items($r);
        $day = fn (string $d) => Carbon::parse(substr($d, 0, 10))->startOfDay();
        $now = 0;
        $dues = [];
        foreach ($items as $it) {
            if ($it['amount'] > 0 && $it['due'] && $day($it['due'])->gt($day($today))) {
                continue; // owed later
            }
            if ($it['amount'] > 0) {
                $now += $it['amount'];
                if ($it['due']) {
                    $dues[] = substr($it['due'], 0, 10);
                }
            }
        }
        sort($dues);
        $all = array_values(array_filter(array_map(fn ($i) => $i['amount'] > 0 && $i['due'] ? substr($i['due'], 0, 10) : null, $items)));
        sort($all);
        $nextDue = $dues[0] ?? $all[0] ?? null;

        $kind = self::kind($r, $items);
        $overdue = $kind === 'owing' && isset($dues[0]) ? max(0, (int) round($day($today)->diffInDays($day($dues[0]), false) * -1)) : 0;
        $owed = $kind === 'owing' ? $now : 0;

        $remaining = ($r['schedule_mode'] ?? null) === 'date' || ($r['remaining_sessions'] ?? null) === null ? null : max(0, (int) $r['remaining_sessions']);
        $start = $r['course_start_date'] ?? null;
        $end = $r['course_end_date'] ?? null;
        $period = $remaining !== null ? "剩 {$remaining} 堂"
            : ($start || $end ? ($start ? substr($start, 0, 10) : '—') . ' ~ ' . ($end ? substr($end, 0, 10) : '—') : '—');

        $due = $overdue > 0 ? "逾期 {$overdue} 天"
            : ($nextDue && $kind === 'owing' ? (int) substr($nextDue, 5, 2) . '/' . (int) substr($nextDue, 8, 2) . ' 到期' : '—');

        // One scope for the money columns, same as billRows.js: 應收 = 已收 + 未收 (all open periods due by today).
        $paid = (int) round((float) ($r['paid_amount'] ?? 0));

        return ['kind' => $kind, 'status' => self::label($kind, $r, $owed), 'period' => $period, 'due' => $due, 'owed' => $owed, 'paid' => $paid, 'payable' => $paid + $owed];
    }

    private static function items(array $r): array
    {
        if (!empty($r['open_periods']) && is_array($r['open_periods'])) {
            return array_map(fn ($p) => ['amount' => (int) round((float) ($p['outstanding'] ?? 0)), 'due' => $p['due_date'] ?? null], $r['open_periods']);
        }

        return [['amount' => (int) round((float) ($r['payable_outstanding'] ?? 0)), 'due' => $r['due_date'] ?? null]];
    }

    private static function legacy(array $r): bool
    {
        return ($r['legacy_paid_flag_only'] ?? null) === true;
    }

    private static function kind(array $r, array $items): string
    {
        $s = $r['payment_status'] ?? '';
        $review = $s === 'amount_review' || ($r['amount_pending_confirmation'] ?? null) === true;
        if ($s === 'pending_report' || !empty($r['latest_payment_report_id'])) {
            return 'confirm';
        }
        if ($review || (in_array($s, self::PAID, true) && self::legacy($r))) {
            return 'review';
        }
        if ($s === 'waived' || ($r['closed_reason'] ?? null) === 'waived') {
            return 'waived';
        }
        $owes = in_array($s, self::OWING, true);
        if ($owes && array_filter($items, fn ($i) => $i['amount'] > 0)) {
            return 'owing';
        }
        if (($r['renewed_by_newer_course'] ?? null) !== true && in_array($s, ['renew_needed', 'monthly_due_soon'], true)) {
            return 'issue';
        }
        if ($owes && !in_array($s, self::PAID, true) && ($r['payable_status'] ?? null) === 'unbilled') {
            return 'issue';
        }

        return in_array($s, self::PAID, true) ? 'paid' : 'review';
    }

    private static function label(string $kind, array $r, int $owed): string
    {
        $s = $r['payment_status'] ?? '';
        return match (true) {
            $kind === 'confirm' => '待確認入帳',
            $kind === 'review' => self::legacy($r) ? '查無收款' : '需核對',
            $kind === 'waived' => '不收了',
            $kind === 'paid' => '已收',
            $kind === 'issue' => in_array($s, ['renew_needed', 'monthly_due_soon'], true) ? self::LABELS[$s] : '要開帳單',
            $owed <= 0 => '快到期',
            default => in_array($s, ['partial', 'pending_reconciliation'], true) ? self::LABELS[$s] : '未繳',
        };
    }
}
