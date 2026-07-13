<?php

namespace App\Services;

use App\Models\StudentClass;
use App\Models\CoursePackage;
use Illuminate\Support\Facades\DB;

class PaymentStatusService
{
    public const STATUS_PENDING_REPORT   = 'pending_report';
    public const STATUS_PARTIAL          = 'partial';
    public const STATUS_UNPAID           = 'unpaid';
    public const STATUS_RENEW_NEEDED     = 'renew_needed';
    public const STATUS_MONTHLY_DUE_SOON = 'monthly_due_soon';
    public const STATUS_PAID             = 'paid';

    public function computePaymentStatus(
        ?StudentClass $sc,
        int $paidAmount,
        int $charge,
        bool $hasPendingReport
    ): string {
        if (!$sc) {
            return self::STATUS_UNPAID;
        }
        if ($hasPendingReport) {
            return self::STATUS_PENDING_REPORT;
        }
        $isPaid = (int) ($sc->Paid ?? 0) === 1;
        if (!$isPaid && $paidAmount > 0 && $charge > 0 && $paidAmount < $charge) {
            return self::STATUS_PARTIAL;
        }
        if (!$isPaid) {
            return self::STATUS_UNPAID;
        }
        $mode = $sc->ScheduleMode ?? 'count';
        $remaining = (int) ($sc->RemainingSessions ?? 0);
        if ($mode === 'count' && $remaining <= 2) {
            return self::STATUS_RENEW_NEEDED;
        }
        if ($mode === 'date') {
            return self::STATUS_MONTHLY_DUE_SOON;
        }
        return self::STATUS_PAID;
    }

    public function computePackagePaymentStatus(
        CoursePackage $pkg,
        int $paidAmount,
        int $charge,
        bool $hasPendingReport
    ): string {
        if ($hasPendingReport) {
            return self::STATUS_PENDING_REPORT;
        }
        $isPaid = (bool) $pkg->paid || ($charge > 0 && $paidAmount >= $charge);
        if (!$isPaid && $paidAmount > 0 && $charge > 0 && $paidAmount < $charge) {
            return self::STATUS_PARTIAL;
        }
        if (!$isPaid) {
            return self::STATUS_UNPAID;
        }
        return (int) ($pkg->remaining_sessions ?? 0) <= 2
            ? self::STATUS_RENEW_NEEDED
            : self::STATUS_PAID;
    }

    public function batchCompute(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }
        $scs = StudentClass::whereIn('ID', $studentClassIds)->get()->keyBy('ID');
        $invoiceMap = self::invoiceAggregateByStudentClassIds($studentClassIds);
        $pendingMap = self::latestPendingReportByStudentClassIds($studentClassIds);
        $result = [];
        foreach ($studentClassIds as $id) {
            $sc = $scs->get($id);
            $inv = $invoiceMap[$id] ?? ['paid_amount' => 0, 'total_amount' => 0];
            $hasPending = isset($pendingMap[$id]);
            $result[$id] = $this->computePaymentStatus($sc, $inv['paid_amount'], $inv['total_amount'], $hasPending);
        }
        return $result;
    }

    public function isEffectivelyPaid(?StudentClass $sc, int $paidAmount, int $charge, bool $hasPendingReport): bool
    {
        $status = $this->computePaymentStatus($sc, $paidAmount, $charge, $hasPendingReport);
        return in_array($status, [
            self::STATUS_RENEW_NEEDED,
            self::STATUS_MONTHLY_DUE_SOON,
            self::STATUS_PAID,
        ], true);
    }

    public static function invoiceAggregateByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }
        $rows = DB::table('Invoice')
            ->whereIn('StudentClassID', $studentClassIds)
            ->where(function ($q) {
                $q->whereNull('Status')->orWhere('Status', '!=', 'void');
            })
            ->select('StudentClassID',
                DB::raw('COALESCE(SUM(PaidAmount), 0) as paid_amount'),
                DB::raw('COALESCE(SUM(TotalAmount), 0) as total_amount'))
            ->groupBy('StudentClassID')
            ->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->StudentClassID] = [
                'paid_amount'  => (int) $row->paid_amount,
                'total_amount' => (int) $row->total_amount,
            ];
        }
        return $map;
    }

    public static function latestPendingReportByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }
        $rows = DB::table('payment_reports')
            ->whereIn('StudentClassID', $studentClassIds)
            ->where('status', 'pending')
            ->select('StudentClassID', DB::raw('MAX(id) as latest_id'))
            ->groupBy('StudentClassID')
            ->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->StudentClassID] = (int) $row->latest_id;
        }
        return $map;
    }
}
