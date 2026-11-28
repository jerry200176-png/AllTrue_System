<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\StudentClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Server decides, screen obeys (#5721): the amount-independent refusals of POST 確認不收 (AccountingController::waiveCourse),
 * moved there unchanged (same sentences, same order), so the 帳務中心 list can show why 改成不收 would be refused.
 * Batched: one query each for bills, their payments, involved bills, their lines and reports, over every course given.
 */
final class WaiveGate
{
    /**
     * @param  Collection<int, StudentClass>  $courses  loaded courses (campus already authorized by the caller)
     * @param  bool  $lock  the write path's locking read of the courses' own bills (the caller holds the course row lock)
     * @return array<int, string> course id => why 確認不收 is refused
     */
    public function refusals(Collection $courses, bool $lock = false): array
    {
        $out = [];
        $rest = [];
        foreach ($courses as $course) {
            $id = (int) $course->getKey();
            if ($course->isPartOfPackage()) {
                $out[$id] = '套裝課程請到套裝處理';
                continue;
            }
            $closedReason = (string) ($course->closed_reason ?? '');
            $unpaid = (int) ($course->Paid ?? 0) !== 1;
            $pending = $closedReason === 'settled_pending' || ($closedReason === 'contract_amended' && $unpaid);
            if ((int) $course->Stop !== 1 || !$pending || !$unpaid) {
                $out[$id] = '只有「課已結束，尚未收款」且還沒繳清的合約，才能改成「不收了」';
                continue;
            }
            $rest[] = $id;
        }
        if ($rest === []) {
            return $out;
        }

        $bills = Invoice::with('payments')->whereIn('StudentClassID', $rest)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'));
        $bills = ($lock ? $bills->lockForUpdate() : $bills)->get()->groupBy('StudentClassID');
        // Every non-void bill involving these courses (as anchor or line), and every line on those bills.
        $involved = DB::table('Invoice')->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->where(fn ($q) => $q->whereIn('StudentClassID', $rest)
                ->orWhereIn('id', DB::table('InvoiceItem')->whereIn('StudentClassID', $rest)->select('InvoiceID')))
            ->get(['id', 'StudentClassID']);
        $lines = $involved->isEmpty() ? collect()
            : DB::table('InvoiceItem')->whereIn('InvoiceID', $involved->pluck('id')->all())->get(['InvoiceID', 'StudentClassID'])->groupBy('InvoiceID');
        $reports = PaymentReport::query()->whereIn('StudentClassID', $rest)->whereIn('status', ['confirmed', 'pending'])
            ->get(['StudentClassID', 'status'])->groupBy('StudentClassID');

        foreach ($rest as $id) {
            // 已有收款的帳單不在此處理，避免作廢帳單蓋住已收的錢。
            if (collect($bills->get($id, []))->contains(fn ($i) => $i->payments->isNotEmpty() || (int) ($i->PaidAmount ?? 0) !== 0)) {
                $out[$id] = '此合約已有收款紀錄，請先到帳務更正後再處理';
                continue;
            }
            // A waiver may only touch invoices that belong to this course alone. Any non-void invoice involving this
            // course (as anchor or line) that also involves another course, or has no anchor, needs accounting first.
            $mine = $involved->filter(fn ($inv) => (int) ($inv->StudentClassID ?? 0) === $id
                || collect($lines->get($inv->id, []))->contains(fn ($l) => (int) ($l->StudentClassID ?? 0) === $id));
            $shared = $mine->contains(fn ($inv) => (int) ($inv->StudentClassID ?? 0) !== $id)
                || $mine->contains(fn ($inv) => collect($lines->get($inv->id, []))->contains(fn ($l) => $l->StudentClassID !== null && (int) $l->StudentClassID !== $id));
            if ($shared) {
                $out[$id] = '此合約在合併帳單中，請先到帳務處理該帳單';
                continue;
            }
            $statuses = collect($reports->get($id, []))->pluck('status');
            if ($statuses->contains('confirmed')) {
                $out[$id] = '此合約已有確認過的繳費回報，請先到帳務更正後再處理';
            } elseif ($statuses->contains('pending')) {
                $out[$id] = '有待確認的繳費回報，請先確認或退回';
            }
        }

        return $out;
    }
}
