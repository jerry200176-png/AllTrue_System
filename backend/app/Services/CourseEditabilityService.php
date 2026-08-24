<?php

namespace App\Services;

use App\Models\PaymentReport;
use App\Models\StudentClass;
use Illuminate\Support\Facades\DB;

/**
 * Read-only course edit preflight.
 *
 * The PUT endpoint remains authoritative. This service only explains the
 * current state and names the safe command for the director's intent.
 */
final class CourseEditabilityService
{
    /** @return array<string, mixed> */
    public function inspect(StudentClass $course): array
    {
        $classId = (int) $course->getKey();
        $hasDeductionHistory = $course->hasDeductionHistory();
        // Mirror StudentClassController::update(): any dated payment on a
        // non-void invoice prevents silently changing the course to unpaid.
        $hasPaymentRecord = DB::table('Invoice')
            ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->where('Invoice.StudentClassID', $classId)
            ->where(function ($q) {
                $q->whereNull('Invoice.Status')->orWhere('Invoice.Status', '!=', 'void');
            })
            ->whereNotNull('Payment.PaidAt')
            ->exists();
        $hasPaymentReport = PaymentReport::query()
            ->where('StudentClassID', $classId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        $diagnostic = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$classId])[$classId] ?? null;
        $sessionCount = (int) ($course->SessionCount ?? 0);
        $expectedRemaining = $diagnostic === null
            ? null
            : max(0, $sessionCount - (int) $diagnostic['expected_used']);
        $usageMismatch = $diagnostic !== null && (
            (int) $diagnostic['cancelled_usage_artifacts'] > 0
            || (int) ($course->UsedSessions ?? 0) !== (int) $diagnostic['expected_used']
            || ((string) ($course->ScheduleMode ?? 'count') === 'count'
                && (int) ($course->RemainingSessions ?? 0) !== $expectedRemaining)
        );

        $isCountCourse = (string) ($course->ScheduleMode ?? 'count') === 'count';
        $isPackage = $course->isPartOfPackage();
        $reasons = [];

        if ($hasDeductionHistory) {
            $reasons[] = [
                'code' => 'billing_contract_locked',
                'message' => '已有扣堂紀錄；購買堂數、標準堂長與扣堂方式不可用一般編輯修改。',
                'next_step' => $isCountCourse && !$isPackage ? 'billing_correction' : 'new_contract',
            ];
        }
        if ($hasPaymentRecord) {
            $reasons[] = [
                'code' => 'payment_record_locked',
                'message' => '已有有效收款紀錄，不能直接改成未繳費。若是誤收款，請先作廢帳單。',
                'next_step' => 'void_payment',
            ];
        }
        if ($hasPaymentReport) {
            $reasons[] = [
                'code' => 'payment_report_locked',
                'message' => '已有待處理或已確認的繳費回報，請先完成或作廢回報。',
                'next_step' => 'payment_report',
            ];
        }
        if ($isPackage) {
            $reasons[] = [
                'code' => 'package_contract_owner',
                'message' => '這是共用方案課程，堂數由方案池管理，不能單獨修改本課程堂數。',
                'next_step' => 'package_adjustment',
            ];
        }
        if ($usageMismatch) {
            $reasons[] = [
                'code' => 'usage_reconciliation_required',
                'message' => '課堂狀態、扣堂紀錄或剩餘堂數不一致，請先完成對帳，再作為收費依據。',
                'next_step' => 'reconcile_usage',
            ];
        }

        $actions = ['edit_general'];
        if ($isPackage) {
            $actions[] = 'package_adjustment';
        } elseif ($isCountCourse && !$hasPaymentRecord && !$hasPaymentReport) {
            $actions[] = 'billing_correction';
        }
        if ($diagnostic !== null && (int) $diagnostic['observed_used'] > 0) {
            $actions[] = 'transfer_sessions';
        }
        if ($usageMismatch) {
            $actions[] = 'reconcile_usage';
        }

        return [
            'course_id' => $classId,
            'status' => $usageMismatch ? 'review_required' : 'ready',
            'locked_fields' => array_values(array_unique(array_merge(
                $hasDeductionHistory ? ['sessions_purchased', 'standard_lesson_minutes', 'deduction_basis'] : [],
                $isPackage ? ['sessions_purchased', 'remaining_sessions'] : [],
                $hasPaymentRecord ? ['paid_at'] : [],
            ))),
            'reasons' => $reasons,
            'available_actions' => array_values(array_unique($actions)),
            'diagnostic' => $diagnostic,
        ];
    }
}
