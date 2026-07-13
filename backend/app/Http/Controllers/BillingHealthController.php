<?php

namespace App\Http\Controllers;

use App\Models\StudentClass;
use App\Services\PaymentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingHealthController extends Controller
{
    /**
     * GET /api/v1/admin/billing/health
     * Returns charge consistency, payment divergence, and mode transition anomalies.
     */
    public function health(Request $request): JsonResponse
    {
        $campusId = $request->query('campus_id');

        // 1. Charge consistency: Rate × SessionCount vs Charge
        $query = StudentClass::with('student')
            ->where('Stop', 0)
            ->where('ScheduleMode', 'date');
        if ($campusId) {
            $query->whereHas('student', fn ($q) => $q->where('CampusID', (int) $campusId));
        }

        $chargeInconsistencies = [];
        foreach ($query->cursor() as $sc) {
            $rate = (float) ($sc->Rate ?? 0);
            $sessionCount = (int) ($sc->SessionCount ?? 0);
            $expected = (int) round($rate * $sessionCount);
            $actual = (int) ($sc->Charge ?? 0);
            if ($expected !== $actual) {
                $chargeInconsistencies[] = [
                    'student_class_id' => (int) $sc->ID,
                    'student_id'       => (int) $sc->StudentID,
                    'expected'         => $expected,
                    'actual'           => $actual,
                ];
            }
        }

        // 2. Payment divergence: check if any SC has Paid=1 but no invoice payment
        //    or Paid=0 but has full invoice payment
        $allActiveIds = StudentClass::where('Stop', 0)
            ->when($campusId, fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('CampusID', (int) $campusId)))
            ->pluck('ID')->all();

        $invoiceMap = PaymentStatusService::invoiceAggregateByStudentClassIds($allActiveIds);
        $pendingMap = PaymentStatusService::latestPendingReportByStudentClassIds($allActiveIds);

        $divergences = [];
        foreach (StudentClass::whereIn('ID', $allActiveIds)->cursor() as $sc) {
            $inv = $invoiceMap[(int) $sc->ID] ?? ['paid_amount' => 0, 'total_amount' => 0];
            $paid = (int) ($sc->Paid ?? 0);
            $invoiceHasPayment = $inv['paid_amount'] > 0;

            // Divergence: Paid=1 but no invoice payment records
            if ($paid === 1 && !$invoiceHasPayment && (int) ($sc->Charge ?? 0) > 0) {
                $divergences[] = [
                    'student_class_id' => (int) $sc->ID,
                    'student_id'       => (int) $sc->StudentID,
                    'type'             => 'paid_without_invoice',
                    'detail'           => "Paid=1 but no invoice payment (Charge={$sc->Charge})",
                ];
            }

            // Divergence: Paid=0 but invoice has full payment
            if ($paid === 0 && $inv['total_amount'] > 0 && $inv['paid_amount'] >= $inv['total_amount']) {
                $divergences[] = [
                    'student_class_id' => (int) $sc->ID,
                    'student_id'       => (int) $sc->StudentID,
                    'type'             => 'unpaid_with_full_invoice',
                    'detail'           => "Paid=0 but invoice fully paid ({$inv['paid_amount']}/{$inv['total_amount']})",
                ];
            }
        }

        return response()->json([
            'data' => [
                'charge_consistency' => [
                    'inconsistent_count' => count($chargeInconsistencies),
                    'items'              => $chargeInconsistencies,
                ],
                'payment_divergence' => [
                    'divergence_count' => count($divergences),
                    'items'            => $divergences,
                ],
                'mode_transition_anomalies' => $this->detectModeTransitionAnomalies($campusId ? (int) $campusId : null),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/billing/recompute-student
     * Recompute Charge for one student and return before/after.
     */
    public function recomputeStudent(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|integer|min:1',
        ]);

        $studentId = (int) $request->input('student_id');

        $courses = StudentClass::where('StudentID', $studentId)
            ->where('Stop', 0)
            ->where('ScheduleMode', 'date')
            ->get();

        if ($courses->isEmpty()) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'No active date-mode courses found for this student'],
            ], 404);
        }

        $results = [];
        DB::transaction(function () use ($courses, &$results) {
            foreach ($courses as $sc) {
                $rate = (float) ($sc->Rate ?? 0);
                $sessionCount = (int) ($sc->SessionCount ?? 0);
                $expected = (int) round($rate * $sessionCount);
                $before = (int) ($sc->Charge ?? 0);

                if ($expected !== $before) {
                    DB::table('StudentClass')->where('ID', $sc->ID)->update([
                        'Charge' => $expected,
                        'updated_at' => now(),
                    ]);
                }

                $results[] = [
                    'student_class_id' => (int) $sc->ID,
                    'subject'          => $sc->displaySubjectName(),
                    'rate'             => $rate,
                    'session_count'    => $sessionCount,
                    'before'           => $before,
                    'after'            => $expected,
                    'changed'          => $expected !== $before,
                ];
            }
        });

        return response()->json([
            'data' => [
                'student_id'        => $studentId,
                'courses_recomputed' => count(array_filter($results, fn ($r) => $r['changed'])),
                'results'           => $results,
            ],
        ]);
    }

    /**
     * Detect anomalies where a student has both count-mode and date-mode courses
     * that may represent a mode transition gone wrong.
     */
    private function detectModeTransitionAnomalies(?int $campusId): array
    {
        // Find students with both count-mode and date-mode active courses for the same subject
        $anomalies = [];

        $baseQuery = StudentClass::where('Stop', 0);
        if ($campusId) {
            $baseQuery->whereHas('student', fn ($q) => $q->where('CampusID', $campusId));
        }

        $allActive = $baseQuery->get(['ID', 'StudentID', 'SubjectID', 'ScheduleMode', 'Charge', 'Paid']);

        // Group by student+subject, look for mixed modes
        $grouped = [];
        foreach ($allActive as $sc) {
            $key = $sc->StudentID . '-' . $sc->SubjectID;
            $grouped[$key][] = $sc;
        }

        foreach ($grouped as $key => $courses) {
            $modes = array_unique(array_map(fn ($c) => $c->ScheduleMode, $courses));
            if (count($modes) > 1) {
                $anomalies[] = [
                    'student_id' => (int) $courses[0]->StudentID,
                    'subject_id' => (int) $courses[0]->SubjectID,
                    'courses'    => array_map(fn ($c) => [
                        'id'   => (int) $c->ID,
                        'mode' => $c->ScheduleMode,
                        'charge' => (int) $c->Charge,
                        'paid' => (int) $c->Paid,
                    ], $courses),
                ];
            }
        }

        return $anomalies;
    }
}
