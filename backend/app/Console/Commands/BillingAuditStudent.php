<?php

namespace App\Console\Commands;

use App\Models\StudentClass;
use App\Services\PaymentStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingAuditStudent extends Command
{
    protected $signature = 'billing:audit-student {studentId : Student ID to audit} {--recompute : Execute recompute (dry-run by default)} {--json : Output as JSON}';
    protected $description = 'Audit a single student — output session timeline + recompute before/after';

    public function handle(): int
    {
        $studentId = (int) $this->argument('studentId');
        $recompute = (bool) $this->option('recompute');
        $asJson = (bool) $this->option('json');

        $courses = StudentClass::where('StudentID', $studentId)
            ->with('student')
            ->orderBy('ID')
            ->get();

        if ($courses->isEmpty()) {
            $this->error("Student {$studentId} not found or has no courses");
            return self::FAILURE;
        }

        $studentName = $courses->first()->student->StudentName ?? 'Unknown';
        $this->info("Auditing student #{$studentId} ({$studentName}) — " . $courses->count() . " course(s)");

        $invoiceMap = PaymentStatusService::invoiceAggregateByStudentClassIds(
            $courses->pluck('ID')->all()
        );
        $pendingMap = PaymentStatusService::latestPendingReportByStudentClassIds(
            $courses->pluck('ID')->all()
        );
        $ps = new PaymentStatusService();

        $timeline = [];
        foreach ($courses as $sc) {
            $inv = $invoiceMap[(int) $sc->ID] ?? ['paid_amount' => 0, 'total_amount' => 0];
            $charge = (int) ($sc->Charge ?? 0);
            $rate = (float) ($sc->Rate ?? 0);
            $sessionCount = (int) ($sc->SessionCount ?? 0);
            $expectedCharge = (int) round($rate * $sessionCount);
            $hasPending = isset($pendingMap[(int) $sc->ID]);

            $entry = [
                'sc_id'             => (int) $sc->ID,
                'subject'           => $sc->displaySubjectName(),
                'mode'              => $sc->ScheduleMode ?? '?',
                'paid'              => (int) ($sc->Paid ?? 0),
                'stop'              => (int) ($sc->Stop ?? 0),
                'session_count'     => $sessionCount,
                'remaining'         => (int) ($sc->RemainingSessions ?? 0),
                'rate'              => $rate,
                'charge'            => $charge,
                'expected_charge'   => $expectedCharge,
                'charge_ok'         => $expectedCharge === $charge,
                'invoice_paid'      => $inv['paid_amount'],
                'invoice_total'     => $inv['total_amount'],
                'pending_report'    => $hasPending,
                'payment_status'    => $ps->computePaymentStatus($sc, $inv['paid_amount'], $inv['total_amount'], $hasPending),
                'start_date'        => $sc->StartDate ? substr($sc->StartDate, 0, 10) : null,
                'pay_date'          => $sc->PayDate ? substr($sc->PayDate, 0, 10) : null,
            ];

            // Recompute if charge is inconsistent
            if (!$entry['charge_ok'] && $recompute) {
                DB::table('StudentClass')->where('ID', $sc->ID)->update([
                    'Charge' => $expectedCharge,
                    'updated_at' => now(),
                ]);
                $entry['recomputed'] = true;
                $entry['charge_after'] = $expectedCharge;
            }

            $timeline[] = $entry;
        }

        if ($asJson) {
            $this->line(json_encode([
                'student_id'  => $studentId,
                'student_name' => $studentName,
                'courses'     => $timeline,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['SC', 'Subject', 'Mode', 'Paid', 'Stop', 'Sess', 'Rem', 'Rate', 'Charge', 'Invoice', 'Status'],
                array_map(fn ($t) => [
                    $t['sc_id'],
                    $t['subject'],
                    $t['mode'],
                    $t['paid'] ? 'Y' : 'N',
                    $t['stop'] ? 'Y' : 'N',
                    $t['session_count'],
                    $t['remaining'],
                    $t['rate'],
                    ($t['charge_ok'] ? '✅' : '❌') . ' ' . $t['charge'],
                    $t['invoice_paid'] . '/' . $t['invoice_total'],
                    $t['payment_status'],
                ], $timeline)
            );

            $inconsistent = array_filter($timeline, fn ($t) => !$t['charge_ok']);
            if (!empty($inconsistent)) {
                $this->warn(count($inconsistent) . ' course(s) have inconsistent charge');
                if (!$recompute) {
                    $this->line('Run with --recompute to fix automatically');
                }
            }
        }

        return self::SUCCESS;
    }
}
