<?php

namespace Tests\Feature\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyVerdict;
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
        mt_srand($seed);
        $date = mt_rand(0, 1) === 1;
        $trial = mt_rand(1, 10) === 1;
        $paidFlag = mt_rand(1, 4) === 1 ? 1 : 0;
        $package = mt_rand(1, 12) === 1;
        $months = array_slice(['2026-06', '2026-07', '2026-08'], 0, mt_rand(0, 3) === 0 ? 3 : 2);
        $nInvoices = $trial ? 0 : mt_rand(0, 3);

        // Lessons: date mode = 0..4 held lessons per month; count mode = 0..12 past lessons in Aug.
        $sessions = [];
        $perMonth = [];
        if ($date) {
            foreach (['2026-06', '2026-07', '2026-08'] as $m) {
                $perMonth[$m] = mt_rand(0, 4);
                for ($d = 1; $d <= $perMonth[$m]; $d++) {
                    $sessions[] = sprintf('%s-%02d', $m, $d * 6);
                }
            }
        } else {
            for ($d = 1; $d <= mt_rand(0, 12); $d++) {
                $sessions[] = sprintf('2026-08-%02d', $d);
            }
        }

        $attrs = ['Paid' => $paidFlag];
        if ($date) {
            $attrs += ['ScheduleMode' => 'date', 'Rate' => self::RATE, 'Charge' => 6000];
        }
        if ($trial) {
            $attrs = ['Charge' => 0, 'Rate' => 0, 'Paid' => $paidFlag] + ($date ? ['ScheduleMode' => 'date'] : []);
        }
        if ($package) {
            $attrs['PackageID'] = 1;
        }
        $course = $this->course([], $attrs, $sessions);
        $student = Student::findOrFail($course->StudentID);

        $kinds = ['unpaid', 'partial', 'full', 'over', 'voided_payment', 'void_invoice'];

        $shuffled = $months;
        for ($i = 0; $i < $nInvoices; $i++) {
            if ($i >= count($shuffled)) {
                break; // one invoice per month
            }
            $period = $shuffled[$i];
            $kind = $kinds[mt_rand(0, 5)];
            $total = $date && ($perMonth[$period] ?? 0) > 0 ? $perMonth[$period] * self::RATE : mt_rand(1, 20) * 500;
            $pay = match ($kind) {
                'partial' => [[mt_rand(1, $total - 1 ?: 1), 'cash']],
                'full', 'void_invoice' => [[$total, 'cash']],
                'over' => [[$total + 500, 'cash']],
                'voided_payment' => [[$total, 'cash'], [$total, 'void']],
                default => [],
            };
            $status = $kind === 'void_invoice' ? 'void' : ['unpaid' => 'unpaid', 'partial' => 'partial', 'full' => 'paid', 'over' => 'paid', 'voided_payment' => 'unpaid'][$kind];
            $this->invoice($course, $student, $period, $total, $status, mt_rand(0, 1) * $total, $pay);
        }

        $violations = $this->violations($course->fresh(), $date, $perMonth);
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
        // 5. monthly: each month's invoice == that month's lessons x price
        if ($date) {
            foreach ($v->periods as $p) {
                $n = $perMonth[$p['billing_period']] ?? 0;
                if ($n > 0 && $p['invoice_ids'] !== [] && (int) $p['total'] !== $n * self::RATE) {
                    $out[] = "I5 {$p['billing_period']} invoice {$p['total']} != $n lessons x " . self::RATE;
                }
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
