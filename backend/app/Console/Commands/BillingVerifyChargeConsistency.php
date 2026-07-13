<?php

namespace App\Console\Commands;

use App\Models\StudentClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingVerifyChargeConsistency extends Command
{
    protected $signature = 'billing:verify-charge-consistency {--campus= : Filter by CampusID} {--json : Output as JSON}';
    protected $description = 'Compare Charge vs Rate × SessionCount for all active date-mode StudentClasses';

    public function handle(): int
    {
        $query = StudentClass::with('student')
            ->where('Stop', 0)
            ->where('ScheduleMode', 'date');

        if ($campusId = $this->option('campus')) {
            $query->whereHas('student', fn ($q) => $q->where('CampusID', (int) $campusId));
        }

        $inconsistent = [];
        $total = 0;

        foreach ($query->cursor() as $sc) {
            $total++;
            $rate = (float) ($sc->Rate ?? 0);
            $sessionCount = (int) ($sc->SessionCount ?? 0);
            $expected = (int) round($rate * $sessionCount);
            $actual = (int) ($sc->Charge ?? 0);

            if ($expected !== $actual) {
                $inconsistent[] = [
                    'student_class_id' => (int) $sc->ID,
                    'student_id'       => (int) $sc->StudentID,
                    'campus_id'        => (int) ($sc->student->CampusID ?? 0),
                    'rate'             => $rate,
                    'session_count'    => $sessionCount,
                    'expected_charge'  => $expected,
                    'actual_charge'    => $actual,
                    'diff'             => $actual - $expected,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'total_scanned'       => $total,
                'inconsistent_count'  => count($inconsistent),
                'inconsistent'        => $inconsistent,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Scanned {$total} active date-mode courses");
            if (empty($inconsistent)) {
                $this->info('✅ All charges consistent with Rate × SessionCount');
            } else {
                $this->warn('Found ' . count($inconsistent) . ' inconsistent charge(s):');
                $this->table(
                    ['SC ID', 'Student', 'Rate', 'Sessions', 'Expected', 'Actual', 'Diff'],
                    array_map(fn ($i) => [
                        $i['student_class_id'],
                        $i['student_id'],
                        $i['rate'],
                        $i['session_count'],
                        $i['expected_charge'],
                        $i['actual_charge'],
                        $i['diff'],
                    ], $inconsistent)
                );
            }
        }

        return empty($inconsistent) ? self::SUCCESS : self::FAILURE;
    }
}
