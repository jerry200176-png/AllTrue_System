<?php

namespace App\Services\Billing;

use App\Models\Invoice;
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
        // 2026-11-17 census (學生帳務整理與資料治理計畫 §2): links that broke and to-dos nobody picked up.
        'bill_course_deleted' => '課程已刪除、還沒收齊的帳單',
        'receipt_payment_missing' => '已確認的收據，收款紀錄不見了',
        'receipt_on_void_bill' => '還有效的收據，帳單卻已作廢',
        'items_not_total' => '明細合計和應收對不上的帳單',
        'sessions_over_contract' => '已上堂數超過合約堂數的合約',
        'report_pending_14_days' => '待確認入帳超過 14 天的收據',
    ];

    /**
     * @return array{as_of: string, rules: list<array{key: string, label: string}>,
     *   campuses: list<array{campus_id: int, campus_name: string, counts: array<string, int>}>, totals: array<string, int>}
     */
    public function run(): array
    {
        $today = now('Asia/Taipei')->toDateString();
        $cutoff = now('Asia/Taipei')->subDays(60)->toDateString();
        $pendingCutoff = now('Asia/Taipei')->subDays(14)->toDateTimeString();
        $report = 'FROM payment_reports r JOIN Student s ON s.id = r.StudentID';
        $bill = 'FROM Invoice i JOIN Student s ON s.id = i.StudentID';
        $monthlyBill = $bill . ' JOIN StudentClass sc ON sc.ID = i.StudentClassID';
        // Payment rows are the truth (Founder Q2, R28), never a stored Status or closure flag.
        $owes = 'i.TotalAmount > ' . Invoice::netPaidSql('i');
        $link = PaidFlagInvoiceMismatch::linkSql();
        // The mode the bill was issued under; a course later converted keeps its old bills as they were (#934).
        $monthly = "COALESCE(i.ScheduleModeAtIssue, sc.ScheduleMode) = 'date'";
        $queries = [
            // An old 「0 元」 登記: not payment evidence (#3991). A refunded bill is NT$0 too, but money moved on it.
            'zero_paid_bill' => ["{$bill} WHERE i.Status = 'paid' AND i.TotalAmount = 0 AND i.zero_amount_reason IS NULL"
                . ' AND NOT EXISTS (SELECT 1 FROM Payment p WHERE p.InvoiceID = i.id AND p.Amount <> 0)', []],
            'unpaid_over_60_days' => ["{$bill} WHERE COALESCE(i.Status, '') <> 'void' AND {$owes} AND i.IssueDate < ?", [$cutoff]],
            // Still pending unless its bills exist and Payment rows cover every one (then 已結清 keeps it in history).
            'ended_money_pending' => ["FROM StudentClass sc JOIN Student s ON s.id = sc.StudentID WHERE sc.closed_reason = 'settled_pending'"
                . " AND NOT (EXISTS (SELECT 1 FROM Invoice i WHERE {$link}) AND NOT EXISTS (SELECT 1 FROM Invoice i WHERE {$link} AND {$owes}))", []],
            // The nightly G-009 predicate, per campus.
            'paid_flag_vs_bill' => ['FROM StudentClass sc JOIN Student s ON s.id = sc.StudentID WHERE ' . PaidFlagInvoiceMismatch::whereSql(), []],
            'monthly_bill_without_month' => ["{$monthlyBill} WHERE {$monthly} AND COALESCE(i.Status, '') <> 'void'"
                . " AND (i.billing_period IS NULL OR i.billing_period NOT REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$')", []],
            // Root cause A of the rework: a bill issued before its period was over, still unpaid.
            'advance_monthly_bill' => ["{$monthlyBill} WHERE {$monthly} AND i.Status = 'unpaid'"
                . ' AND EXISTS (SELECT 1 FROM InvoiceItem ii WHERE ii.InvoiceID = i.id AND ii.PeriodEnd >= ?)', [$today]],
            // The main list is built from courses, so money owed on a deleted course shows only inside one student's account.
            // A bill issued with no course on its header (BillingController::store allows it) is not a deleted course.
            'bill_course_deleted' => ["{$bill} WHERE COALESCE(i.Status, '') <> 'void' AND {$owes} AND COALESCE(i.StudentClassID, 0) > 0"
                . ' AND NOT EXISTS (SELECT 1 FROM StudentClass sc WHERE sc.ID = i.StudentClassID)', []],
            'receipt_payment_missing' => ["{$report} WHERE r.status = 'confirmed'"
                . ' AND NOT EXISTS (SELECT 1 FROM Payment p WHERE p.id = r.payment_id)', []],
            'receipt_on_void_bill' => ["{$report} WHERE r.status = 'confirmed'"
                . " AND EXISTS (SELECT 1 FROM Invoice i WHERE i.id = r.InvoiceID AND i.Status = 'void')", []],
            'items_not_total' => ["{$bill} WHERE COALESCE(i.Status, '') <> 'void'"
                . ' AND EXISTS (SELECT 1 FROM InvoiceItem ii WHERE ii.InvoiceID = i.id)'
                . ' AND (SELECT SUM(ii.Amount) FROM InvoiceItem ii WHERE ii.InvoiceID = i.id) <> i.TotalAmount', []],
            'sessions_over_contract' => ['FROM StudentClass sc JOIN Student s ON s.id = sc.StudentID'
                // Count mode only: a monthly contract bills each lesson held, so passing its planned count is normal.
                . " WHERE COALESCE(sc.ScheduleMode, 'count') = 'count' AND sc.SessionCount > 0 AND sc.UsedSessions > sc.SessionCount", []],
            'report_pending_14_days' => ["{$report} WHERE r.status = 'pending' AND r.created_at < ?", [$pendingCutoff]],
        ];

        $zero = array_fill_keys(array_keys(self::RULES), 0);
        $counts = [];
        foreach ($queries as $key => [$from, $bindings]) {
            foreach (DB::select("SELECT s.CampusID AS campus_id, COUNT(*) AS c {$from} GROUP BY s.CampusID", $bindings) as $row) {
                $counts[(int) $row->campus_id][$key] = (int) $row->c;
            }
        }
        $names = DB::table('Campus')->pluck('name', 'id');
        // Every campus has a row: a zero is a verified zero, not a missing campus.
        $counts += array_fill_keys($names->keys()->map(fn ($id) => (int) $id)->all(), []);
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
