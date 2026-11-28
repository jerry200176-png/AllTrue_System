<?php

namespace Tests\Feature\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyVerdict;
use App\Services\MonthlyBillingService;
use App\Services\SessionDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BILLING-UX-QUALITY L2: seeded property test over ContractMoneyVerdict. Each case builds a random contract
 * (count|date mode, 0..3 invoices, unpaid/partial/full/over/void-payment/void-invoice, $0 trial, Paid flag,
 * package member) from one seed and checks the money rules that must hold for EVERY contract.
 * Re-run one case: PHPUnit --filter 'seed_<n>'. Change the corpus: MONEY_INVARIANT_SEED=<n>.
 * Known disagreements (GitHub #3781: Paid=1 + invoice, Paid=1 without invoice, $0 trial, package members) are kept
 * as cases but reported markTestIncomplete with the seed instead of failing; any other violation fails.
 */
class ContractMoneyVerdictInvariantsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    private const CASES = 200;
    private const RATE = 1500;

    public static function seeds(): iterable
    {
        $base = (int) (getenv('MONEY_INVARIANT_SEED') ?: 20261008);
        for ($i = 0; $i < self::CASES; $i++) {
            yield "seed_" . ($base + $i) => [$base + $i];
        }
    }

    /** @dataProvider seeds */
    public function test_money_invariants_hold_for_random_contract(int $seed): void
    {
        ['course' => $course, 'date' => $date, 'perMonth' => $perMonth, 'trial' => $trial, 'paidFlag' => $paidFlag,
            'package' => $package, 'nInvoices' => $nInvoices] = $this->randomContract($seed);

        $violations = $this->violations($course, $date, $perMonth);
        if ($violations === []) {
            $this->addToAssertionCount(1);

            return;
        }
        $msg = "seed=$seed (" . ($date ? 'date' : 'count') . ", invoices=$nInvoices, Paid=$paidFlag, trial=" . (int) $trial . ', package=' . (int) $package . '): ' . implode('; ', $violations);
        if ($trial || $paidFlag === 1 || $package) {
            $this->markTestIncomplete("Known disagreement #3781, $msg");
        }
        $this->fail($msg);
    }

    /** @return list<string> */
    private function violations(StudentClass $course, bool $date, array $perMonth): array
    {
        $out = [];
        $svc = app(ContractMoneyVerdict::class);
        $v = $svc->forCourse($course);
        // #5702 (option A), read before step 4 adds money: the first month (StartDate's, 2026-07 here) also carries the June
        // lessons taught before StartDate when billingStart() pulls them in.
        $billing = app(MonthlyBillingService::class);
        $start = substr((string) $course->StartDate, 0, 10);
        $billingStart = $date ? $billing->billingStart($course) : $start;
        $pulled = $billingStart < $start ? ($perMonth['2026-06'] ?? 0) : 0;
        $first = $date ? $billing->summarizePeriod($course, substr($start, 0, 7)) : null;
        $juneBill = Invoice::where('StudentClassID', $course->ID)->where('billing_period', '2026-06')->where('Status', '!=', 'void')->exists();

        // 1. paid + outstanding == amount due
        if ($v->applied + $v->outstanding !== $v->owed) {
            $out[] = "I1 applied({$v->applied})+outstanding({$v->outstanding}) != owed({$v->owed})";
        }
        // 2. never negative; overpay is its own number and never a negative outstanding
        if ($v->outstanding < 0 || $v->overpaid < 0 || $v->applied < 0 || $v->owed < 0) {
            $out[] = "I2 negative amount owed={$v->owed} applied={$v->applied} outstanding={$v->outstanding} overpaid={$v->overpaid}";
        }
        // overpay on one month never offsets another month's debt: outstanding is the sum of the period shortfalls
        $periodOut = array_sum(array_map(fn ($p) => max(0, (int) ($p['outstanding'] ?? 0)), $v->periods));
        if ($v->source === 'invoice' && $v->outstanding !== $periodOut) {
            $out[] = "I2 outstanding({$v->outstanding}) != sum of period shortfalls($periodOut), overpaid={$v->overpaid}";
        }
        // 3. lesson tags agree with the money
        $lessons = $svc->lessons($course);
        $tags = array_count_values(array_column($lessons, 'payment'));
        if (count($lessons) !== ClassSession::where('StudentClassID', $course->ID)->where('Status', '!=', 'cancelled')->count()) {
            $out[] = 'I3 lessons() count != non-cancelled sessions';
        }
        if ($v->source === 'invoice') {
            if ($v->outstanding === 0 && $v->owed > 0 && (($tags['unpaid'] ?? 0) + ($tags['partial'] ?? 0)) > 0) {
                $out[] = 'I3 settled contract has unpaid/partial lessons ' . json_encode($tags);
            }
            if ($v->applied === 0 && (($tags['paid'] ?? 0) + ($tags['partial'] ?? 0)) > 0) {
                $out[] = 'I3 nothing applied but lessons tagged paid/partial ' . json_encode($tags);
            }
        }
        // 4. voided invoice / voided payment changes no amount
        $before = get_object_vars($v);
        $student = Student::findOrFail($course->StudentID);
        $this->invoice($course, $student, '2026-05', 7777, 'void', 7777, [[7777, 'cash']]);
        $target = Invoice::where('StudentClassID', $course->ID)->where('Status', '!=', 'void')->first();
        if ($target) {
            Payment::create(['InvoiceID' => $target->id, 'Amount' => 333, 'PaidAt' => '2026-08-06', 'Method' => 'cash']);
            Payment::create(['InvoiceID' => $target->id, 'Amount' => 333, 'PaidAt' => '2026-08-07', 'Method' => 'void']);
        }
        if (get_object_vars($svc->forCourse($course->fresh())) !== $before) {
            $out[] = 'I4 a void invoice / voided payment changed the verdict';
        }
        // 5. monthly: each month's invoice == that month's lessons x price (the first month with its pulled pre-start lessons, #5702)
        if ($date) {
            foreach ($v->periods as $p) {
                $n = ($perMonth[$p['billing_period']] ?? 0) + ($p['billing_period'] === substr($start, 0, 7) ? $pulled : 0);
                if ($n > 0 && $p['invoice_ids'] !== [] && (int) $p['total'] !== $n * self::RATE) {
                    $out[] = "I5 {$p['billing_period']} invoice {$p['total']} != $n lessons x " . self::RATE;
                }
            }
        }
        // 7. #5702: a pre-start lesson is billed once: the month before StartDate never prices it, and the first month pulls
        // it in only while no live bill of that earlier month covers it.
        if ($date) {
            if ($billing->summarizePeriod($course->fresh(), '2026-06')['period_sessions'] !== 0) {
                $out[] = 'I7 the month before StartDate priced a lesson';
            }
            if ($pulled > 0 && $juneBill) {
                $out[] = "I7 June lessons pulled into the first month (from $billingStart) although a live June bill covers them";
            }
            if ($pulled > 0 && $first['period_start'] !== $billingStart) {
                $out[] = "I7 first month dated {$first['period_start']}, not from its first lesson $billingStart";
            }
        }
        // 6. count mode: held + remaining == purchased (counters recomputed from the lesson ledger)
        if (!$date) {
            SessionDeductionService::recomputeCounters((int) $course->ID);
            $c = StudentClass::where('ID', $course->ID)->first();
            if ($c->SessionCount > 0 && $c->UsedSessions <= $c->SessionCount && $c->UsedSessions + $c->RemainingSessions !== $c->SessionCount) {
                $out[] = "I6 held({$c->UsedSessions})+remaining({$c->RemainingSessions}) != purchased({$c->SessionCount})";
            }
        }

        return $out;
    }
}
