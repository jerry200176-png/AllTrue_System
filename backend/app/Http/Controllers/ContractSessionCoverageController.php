<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\MonthlyBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every lesson date of one contract, each tagged with whether the money for
 * it is in (director billing panel, PRD v2 D13/D16/D17). Coverage reuses the
 * payment-slip rule (MonthlyBillingService::invoiceCoveredSessions); no
 * second date algorithm.
 */
class ContractSessionCoverageController extends Controller
{
    public function __construct(
        private InvoiceAmountReconciliationService $invoiceAmounts,
        private MonthlyBillingService $monthlyBilling,
    ) {
    }

    public function show(Request $request, StudentClass $studentClass): JsonResponse
    {
        $studentClass->loadMissing('student');
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if (!empty($campusIds) && !in_array((int) ($studentClass->student->CampusID ?? 0), $campusIds, true)) {
            abort(403);
        }

        $courseId = (int) $studentClass->getKey();
        $invoices = Invoice::with(['items', 'studentClass'])
            ->where(function ($q) use ($courseId) {
                $q->where('StudentClassID', $courseId)
                    ->orWhereHas('items', fn ($items) => $items->where('StudentClassID', $courseId));
            })
            ->whereNotIn('Status', ['void', 'cancelled'])
            ->get();

        // class_session_id => paid | partial | unpaid; the best state wins when invoices overlap.
        $rank = ['unpaid' => 1, 'partial' => 2, 'paid' => 3];
        $paymentBySession = [];
        foreach ($invoices as $invoice) {
            $projection = $this->invoiceAmounts->resolve($invoice, $invoice->getRelationValue('studentClass'));
            $state = $projection['outstanding_amount'] <= 0 && $projection['total_amount'] > 0
                ? 'paid'
                : ($projection['net_applied'] > 0 ? 'partial' : 'unpaid');
            foreach ($this->monthlyBilling->invoiceCoveredSessions($invoice, $projection) as $row) {
                $id = (int) ($row['class_session_id'] ?? 0);
                if ($id > 0 && ($rank[$paymentBySession[$id] ?? ''] ?? 0) < $rank[$state]) {
                    $paymentBySession[$id] = $state;
                }
            }
        }

        $sessions = array_map(function (array $row) use ($paymentBySession) {
            // no_invoice: no bill lists this lesson yet.
            $row['payment'] = $paymentBySession[$row['class_session_id']] ?? 'no_invoice';

            return $row;
        }, ClassSession::sessionsForPaymentSlip([$courseId]));

        $purchased = $studentClass->getAttribute('ScheduleMode') === 'date' ? 0 : (int) ($studentClass->SessionCount ?? 0);

        return response()->json([
            'student_class_id' => $courseId,
            'subject' => $studentClass->displaySubjectName(),
            'schedule_mode' => (string) ($studentClass->ScheduleMode ?? ''),
            // Tutoring has no payment obligation; the card hides 登記收款 (directorRecord rejects it).
            'class_type' => (string) ($studentClass->ClassType ?? ''),
            'start_date' => $studentClass->StartDate ? substr((string) $studentClass->StartDate, 0, 10) : null,
            'end_date' => $studentClass->EndDate ? substr((string) $studentClass->EndDate, 0, 10) : null,
            'memo' => (string) ($studentClass->Memo ?? ''),
            'sessions' => $sessions,
            // Count mode: bought lessons that have no date yet (PRD v2 D17).
            'unscheduled_count' => max(0, $purchased - count($sessions)),
        ]);
    }
}
