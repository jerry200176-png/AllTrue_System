<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Services\BillingPayableResolver;
use Illuminate\Support\Facades\DB;

/**
 * The nightly invariant `paid_flag_vs_invoice` (bugs:verify-reproductions, G-009) and the read-only row dump that
 * names the contracts it counts (#5187). One predicate feeds both, so the count and the dump cannot drift.
 *
 * Side A: Paid=1 while some live positive bill linked to the contract (header or line) still owes.
 * Side B: Paid=0 while such bills exist and every one is fully covered by Payment rows.
 *
 * PII policy (public repo, production-case-dump): IDs, enums and booleans only. No names, no amounts, no free text.
 * Read-only.
 */
final class PaidFlagInvoiceMismatch
{
    public const SIDE_A = 'A_paid_flag_but_owed';
    public const SIDE_B = 'B_unpaid_flag_but_covered';

    /** A live positive bill naming the course on its header or on a line (`i` = Invoice, `sc` = StudentClass). */
    public static function linkSql(): string
    {
        return "COALESCE(i.Status, '') <> 'void' AND i.TotalAmount > 0 AND (i.StudentClassID = sc.ID"
            . " OR EXISTS (SELECT 1 FROM InvoiceItem ii WHERE ii.InvoiceID = i.id AND ii.StudentClassID = sc.ID AND ii.Amount > 0))";
    }

    private static function owesSql(): string
    {
        return 'i.TotalAmount > ' . Invoice::netPaidSql('i');
    }

    public static function sideASql(): string
    {
        $link = self::linkSql();

        return "(sc.Paid = 1 AND EXISTS (SELECT 1 FROM Invoice i WHERE {$link} AND " . self::owesSql() . '))';
    }

    public static function sideBSql(): string
    {
        $link = self::linkSql();

        return "(COALESCE(sc.Paid, 0) = 0 AND EXISTS (SELECT 1 FROM Invoice i WHERE {$link})"
            . " AND NOT EXISTS (SELECT 1 FROM Invoice i WHERE {$link} AND " . self::owesSql() . '))';
    }

    /** WHERE body over `StudentClass sc`: active, non-package contracts on either side. */
    public static function whereSql(): string
    {
        return '(sc.Stop = 0 OR sc.Stop IS NULL) AND sc.PackageID IS NULL AND (' . self::sideASql() . ' OR ' . self::sideBSql() . ')';
    }

    /**
     * One row per counted contract, ordered by campus then ID. Every row carries its campus_id (Student.CampusID).
     *
     * @return list<array<string,mixed>>
     */
    public function rows(): array
    {
        $found = DB::select(
            'SELECT sc.ID AS student_class_id, st.CampusID AS campus_id, sc.Paid AS paid, sc.Stop AS stop,'
            . ' sc.ScheduleMode AS schedule_mode, sc.SessionCount AS session_count,'
            . " (sc.closed_reason IS NOT NULL AND sc.closed_reason <> '') AS closed_reason_set,"
            . ' (' . self::sideASql() . ') AS is_side_a'
            . ' FROM StudentClass sc JOIN Student st ON st.id = sc.StudentID'
            . ' WHERE ' . self::whereSql() . ' ORDER BY st.CampusID, sc.ID'
        );
        if ($found === []) {
            return [];
        }

        $ids = array_map(static fn ($r) => (int) $r->student_class_id, $found);
        $bills = []; // student_class_id => list of {invoice_id, link, covered}
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $links = DB::select(
                'SELECT sc.ID AS sc_id, i.id AS invoice_id, (i.StudentClassID = sc.ID) AS by_header,'
                . ' (CASE WHEN ' . self::owesSql() . ' THEN 0 ELSE 1 END) AS covered'
                . ' FROM StudentClass sc JOIN Invoice i ON ' . self::linkSql()
                . " WHERE sc.ID IN ({$in}) ORDER BY sc.ID, i.id",
                $chunk
            );
            foreach ($links as $l) {
                $bills[(int) $l->sc_id][] = [
                    'invoice_id' => (int) $l->invoice_id,
                    'link' => (int) $l->by_header === 1 ? 'header' : 'line',
                    'covered' => (int) $l->covered === 1,
                ];
            }
        }

        // Courses each involved bill names (header or a positive line): more than one means a shared bill (H1).
        $invoiceIds = [];
        foreach ($bills as $list) {
            foreach ($list as $b) {
                $invoiceIds[$b['invoice_id']] = true;
            }
        }
        $courseSets = [];
        foreach (array_chunk(array_keys($invoiceIds), 400) as $chunk) {
            foreach (DB::table('Invoice')->whereIn('id', $chunk)->whereNotNull('StudentClassID')->get(['id', 'StudentClassID']) as $h) {
                $courseSets[(int) $h->id][(int) $h->StudentClassID] = true;
            }
            foreach (DB::table('InvoiceItem')->whereIn('InvoiceID', $chunk)->where('Amount', '>', 0)->whereNotNull('StudentClassID')
                ->get(['InvoiceID', 'StudentClassID']) as $it) {
                $courseSets[(int) $it->InvoiceID][(int) $it->StudentClassID] = true;
            }
        }

        // The resolver's verdict (H2: it holds `paid` back while a contract is under review).
        $verdicts = [];
        $resolver = app(BillingPayableResolver::class);
        foreach (array_chunk($ids, 400) as $chunk) {
            $verdicts += $resolver->courseStatusesByStudentClassIds($chunk);
        }

        $out = [];
        foreach ($found as $r) {
            $id = (int) $r->student_class_id;
            $mine = $bills[$id] ?? [];
            $status = (string) ($verdicts[$id]['status'] ?? '');
            $out[] = [
                'student_class_id' => $id,
                'campus_id' => (int) $r->campus_id,
                'side' => (int) $r->is_side_a === 1 ? self::SIDE_A : self::SIDE_B,
                'paid_flag' => (int) $r->paid,
                'fully_paid' => $mine !== [] && !in_array(false, array_column($mine, 'covered'), true),
                'linked_invoice_ids' => array_column($mine, 'invoice_id'),
                'open_invoice_ids' => array_column(array_filter($mine, static fn (array $b) => !$b['covered']), 'invoice_id'),
                'invoice_links' => $mine,
                'resolver_status' => preg_match('/^[a-z_]{1,32}$/', $status) === 1 ? $status : null,
                'under_review' => $status === 'review_required',
                'shared_bill' => array_filter($mine, static fn (array $b) => count($courseSets[$b['invoice_id']] ?? []) > 1) !== [],
                'stop' => (int) $r->stop,
                'closed_reason_set' => (int) $r->closed_reason_set === 1,
                // H3: SessionDeductionService::recomputeCounters forces Stop=0 on these, so a recount can reopen them.
                'recount_can_reopen' => (string) $r->schedule_mode !== 'count' || (int) $r->session_count === 0,
            ];
        }

        return $out;
    }
}
