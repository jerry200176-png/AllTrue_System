<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Billing data-quality scoreboard (Founder 2026-11-16, 帳務中心重整 G2): how many records of each known problem shape
 * every campus holds right now, so each step of the rework can be judged by a number going down instead of by feel.
 * Counts only, per campus. Read-only: SELECT only, repairs nothing.
 * ponytail: computed on request (a handful of grouped counts over a few thousand bills); add a daily snapshot table
 * when a trend line is wanted.
 */
final class BillingDataQuality
{
    /** Rule key => what a director would call it. The order is the column order of the page. */
    public const RULES = [
        'zero_paid_bill' => '0 元卻標成已繳的帳單',
        'unpaid_over_60_days' => '開立超過 60 天還沒收齊的帳單',
        'ended_money_pending' => '已結束但帳還沒清的合約',
        'paid_flag_vs_bill' => '「已繳」標記和帳單對不上的合約',
        'monthly_bill_without_month' => '沒有所屬月份的月結帳單',
        'advance_monthly_bill' => '期間還沒結束就先開的月結帳單（未繳）',
    ];

    /**
     * @return array{as_of: string, rules: list<array{key: string, label: string}>,
     *   campuses: list<array{campus_id: int, campus_name: string, counts: array<string, int>}>, totals: array<string, int>}
     */
    public function run(): array
    {
        $today = now('Asia/Taipei')->toDateString();
        $cutoff = now('Asia/Taipei')->subDays(60)->toDateString();
        $bill = 'FROM Invoice i JOIN Student s ON s.id = i.StudentID';
        $monthlyBill = $bill . ' JOIN StudentClass sc ON sc.ID = i.StudentClassID';
        $queries = [
            // An old 「0 元」 登記: not payment evidence (#3991). A refunded bill is NT$0 too, but money moved on it.
            'zero_paid_bill' => ["{$bill} WHERE i.Status = 'paid' AND i.TotalAmount = 0 AND i.zero_amount_reason IS NULL"
                . ' AND NOT EXISTS (SELECT 1 FROM Payment p WHERE p.InvoiceID = i.id AND p.Amount <> 0)', []],
            'unpaid_over_60_days' => ["{$bill} WHERE i.Status IN ('unpaid', 'partial') AND i.IssueDate < ?", [$cutoff]],
            'ended_money_pending' => ["FROM StudentClass sc JOIN Student s ON s.id = sc.StudentID WHERE sc.closed_reason = 'settled_pending'", []],
            // The nightly G-009 predicate, per campus.
            'paid_flag_vs_bill' => ['FROM StudentClass sc JOIN Student s ON s.id = sc.StudentID WHERE ' . PaidFlagInvoiceMismatch::whereSql(), []],
            'monthly_bill_without_month' => ["{$monthlyBill} WHERE sc.ScheduleMode = 'date' AND COALESCE(i.Status, '') <> 'void'"
                . " AND (i.billing_period IS NULL OR i.billing_period NOT REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')", []],
            // Root cause A of the rework: a bill issued before its period was over, still unpaid.
            'advance_monthly_bill' => ["{$monthlyBill} WHERE sc.ScheduleMode = 'date' AND i.Status = 'unpaid'"
                . ' AND EXISTS (SELECT 1 FROM InvoiceItem ii WHERE ii.InvoiceID = i.id AND ii.PeriodEnd >= ?)', [$today]],
        ];

        $zero = array_fill_keys(array_keys(self::RULES), 0);
        $counts = [];
        foreach ($queries as $key => [$from, $bindings]) {
            foreach (DB::select("SELECT s.CampusID AS campus_id, COUNT(*) AS c {$from} GROUP BY s.CampusID", $bindings) as $row) {
                $counts[(int) $row->campus_id][$key] = (int) $row->c;
            }
        }
        $names = DB::table('Campus')->pluck('name', 'id');
        ksort($counts);
        $campuses = [];
        $totals = $zero;
        foreach ($counts as $campusId => $row) {
            $row += $zero;
            foreach ($row as $key => $count) {
                $totals[$key] += $count;
            }
            $campuses[] = ['campus_id' => $campusId, 'campus_name' => (string) ($names[$campusId] ?? ''), 'counts' => array_replace($zero, $row)];
        }

        return [
            'as_of' => now('Asia/Taipei')->toIso8601String(),
            'rules' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys(self::RULES), self::RULES),
            'campuses' => $campuses,
            'totals' => $totals,
        ];
    }
}
