<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\ParentSession;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingPayableResolver;
use App\Services\MonthlyPeriodPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F7 S0 parity harness (docs/plans/F7_PAID_STATUS_DECISION_PACKET_20261002.md).
 *
 * Runs every callable "is it paid" decision site over one fixture library
 * (packet section 3) and compares each result with the Founder target
 * semantics (2026-10-02):
 *   1. partial payment => `partial` (not paid)
 *   2. paid is derived from invoices/payments; voided receipts never count;
 *      the stored Paid flag counts alone only for legacy courses with no invoice
 *   3. monthly courses => status of the oldest unsettled period
 *
 * Known divergences live in paid_status_parity_allowlist.json and must shrink
 * as S1..S7 land: an unlisted mismatch fails, and so does a non-uncertain
 * allowlist entry that no longer mismatches (or whose current/target drifted).
 *
 * Read-only against app code. Fixed dates; HTTP "today" is pinned at noon.
 */
class PaidStatusParityTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum fixtures each filtered site must observe, so a broken harness cannot pass vacuously. */
    private const MIN_OBSERVED = [
        'api.alerts.tuition.payment_status' => 10,
        'api.alerts.tuition.outstanding' => 10,
    ];

    /**
     * Sites that must answer for exactly these fixtures (every fixture, or every fixture with an invoice):
     * a fixture silently disappearing from a list/card/ledger is itself a regression.
     */
    private function exactObservable(array $fixtures): array
    {
        $all = array_keys($fixtures);

        return [
            'resolver.byStudentClassIds' => $all,
            'resolver.live.byStudentClassIds' => $all,
            'amount.resolver.live.outstanding' => $all,
            'api.student_classes.payment_status' => $all,
            'api.parent.dashboard.payment_status' => $all,
            'api.accounting.ledger' => $all,
            'monthly_period_payment.batch' => array_keys(array_filter($fixtures, fn ($fx) => ($fx['mode'] ?? 'count') === 'date')),
            'amount.resolver.outstanding' => $all,
            'amount.ledger.outstanding' => $all,
            'amount.resolver.total' => $all,
            'amount.resolver.applied' => $all,
            'amount.ledger.total' => $all,
            'amount.ledger.applied' => $all,
        ];
    }

    /** Paid predicates that can only answer paid / notpaid. Target: only `paid` is paid; `free` is notpaid. */
    private const BINARY_SITES = [
        'model.isEffectivelyPaid',
        'model.scopeEffectivelyPaid',
        'model.isFullyPaidWithInvoiceAmount',
        'api.accounting.settled_courses',
    ];

    /** Binary sites with no opinion on `free` fixtures (list membership, not a paid predicate). */
    private const BINARY_SKIP_FREE = ['api.accounting.settled_courses'];

    /** Numeric sites: total / applied / outstanding (as strings) must equal expectedAmounts(), not just the status. */
    private const AMOUNT_SITES = [
        'amount.resolver.outstanding' => 'outstanding', 'amount.resolver.live.outstanding' => 'outstanding', 'amount.alerts.tuition.outstanding' => 'outstanding',
        'amount.ledger.outstanding' => 'outstanding', 'amount.resolver.total' => 'total', 'amount.resolver.applied' => 'applied',
        'amount.ledger.total' => 'total', 'amount.ledger.applied' => 'applied',
    ];

    /** Unpaid predicate (reminders/notifications): in scope iff target is partial or unpaid. */
    private const UNPAID_SITES = ['model.scopeEffectivelyUnpaid'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> */
    private function fixtures(): array
    {
        $cash = fn (int $amount): array => ['amount' => $amount, 'method' => 'cash'];
        $void = fn (int $amount): array => ['amount' => -$amount, 'method' => 'void'];
        $voidPositive = fn (int $amount): array => ['amount' => $amount, 'method' => 'void']; // imported reversal, positive sign
        $inv = fn (string $period, int $total, int $stored, string $status, array $payments = []): array => [
            'period' => $period, 'total' => $total, 'stored_paid' => $stored, 'status' => $status, 'payments' => $payments,
        ];

        return [
            'full_paid_flag1' => ['flag' => 1, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 10000, 'paid', [$cash(10000)])]],
            'full_paid_invoice_only' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 10000, 'paid', [$cash(10000)])]],
            // The current writer (B11/B16) sets Flag=1 on a confirmed partial receipt.
            'partial_flag1' => ['flag' => 1, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 4000, 'partial', [$cash(4000)])]],
            'partial_flag0' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 4000, 'partial', [$cash(4000)])]],
            'void_full' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 0, 'unpaid', [$cash(10000), $void(10000)])]],
            'void_partial_flag1' => ['flag' => 1, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 4000, 'partial', [$cash(10000), $void(6000)])]],
            // Discount is baked into Charge/Total at issue; Rate x qty (10000) is the list price.
            'discount_count' => ['flag' => 0, 'charge' => 9000, 'rate' => 1000, 'invoices' => [$inv('2026-08', 9000, 9000, 'paid', [$cash(9000)])]],
            'zero_fee_non_tutoring' => ['flag' => 0, 'charge' => 0, 'rate' => 0, 'invoices' => []],
            'tutoring' => ['flag' => 0, 'charge' => 0, 'rate' => 0, 'class_type' => 'tutoring', 'invoices' => []],
            'overpaid' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 10000, 'paid', [$cash(6000), $cash(6000)])]],
            // Flag=1 is left over from the paid August period.
            'monthly_earlier_unpaid' => ['mode' => 'date', 'flag' => 1, 'charge' => 6000, 'invoices' => [
                $inv('2026-07', 6000, 0, 'unpaid'),
                $inv('2026-08', 6000, 6000, 'paid', [$cash(6000)]),
            ]],
            'monthly_two_open_partial' => ['mode' => 'date', 'flag' => 0, 'charge' => 6000, 'invoices' => [
                $inv('2026-07', 6000, 2000, 'partial', [$cash(2000)]),
                $inv('2026-08', 6000, 0, 'unpaid'),
            ]],
            'monthly_all_paid' => ['mode' => 'date', 'flag' => 1, 'charge' => 6000, 'invoices' => [
                $inv('2026-07', 6000, 6000, 'paid', [$cash(6000)]),
                $inv('2026-08', 6000, 6000, 'paid', [$cash(6000)]),
            ]],
            'legacy_flag_paid' => ['flag' => 1, 'charge' => 10000, 'invoices' => []],
            'legacy_unpaid' => ['flag' => 0, 'charge' => 10000, 'invoices' => []],
            // #230: stale Charge copied from a previous contract; contract price is 8 x 1650 = 13200.
            'amendment_stale_charge' => ['flag' => 0, 'charge' => 24750, 'rate' => 1650, 'session_count' => 8, 'invoices' => [$inv('2026-08', 13200, 13200, 'paid', [$cash(13200)])]],
            'unpaid_invoice' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 0, 'unpaid')]],
            // Stored PaidAmount/Status disagree with Payment rows (the F7 split): payments decide.
            'stored_full_pay_partial' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 10000, 'paid', [$cash(4000)])]],
            'stored_full_pay_voided' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 10000, 'paid', [$cash(10000), $void(10000)])]],
            'stored_zero_pay_full' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 0, 'unpaid', [$cash(10000)])]],
            // Only a void invoice: no non-void invoice, so the legacy flag decides.
            'void_invoice_only_flag1' => ['flag' => 1, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 0, 'void')]],
            // Zero-fee wins over a stray flag or a zero-total invoice.
            'zero_fee_flag1' => ['flag' => 1, 'charge' => 0, 'rate' => 0, 'invoices' => []],
            'zero_fee_zero_invoice' => ['flag' => 0, 'charge' => 0, 'rate' => 0, 'invoices' => [$inv('2026-08', 0, 0, 'paid')]],
            // R31 overpayment, 3 receipts > total, stored PaidAmount drifted below the rows.
            'overpaid_stored_drift' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 9000, 'partial', [$cash(5000), $cash(4000), $cash(3000)])]],
            // Historical/imported: stored PaidAmount says paid, no Payment rows (B15 falls back to stored PA).
            // Both periods of the Jul-Aug course are invoiced so no uncovered range makes B15 ambiguous.
            'monthly_stored_no_rows' => ['mode' => 'date', 'flag' => 0, 'charge' => 6000, 'invoices' => [
                $inv('2026-07', 6000, 6000, 'paid'),
                $inv('2026-08', 6000, 6000, 'paid'),
            ]],
            // A negative cash row is a reversal even without Method=void.
            'void_negative_cash' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 0, 'unpaid', [$cash(10000), $cash(-10000)])]],
            // Both reversal encodings on one invoice: each is a void (|−6000| + 4000), they must not cancel.
            'void_mixed_encodings' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 0, 'unpaid', [$cash(10000), $cash(-6000), $voidPositive(4000)])]],
            // A reversal recorded as Method=void with a positive amount is still a void.
            'void_method_positive' => ['flag' => 0, 'charge' => 10000, 'invoices' => [$inv('2026-08', 10000, 0, 'unpaid', [$cash(10000), $voidPositive(10000)])]],
            // 1: a non-void invoice overrides CoursePackage.paid.
            'package_paid_unpaid_invoice' => ['flag' => 0, 'charge' => 10000, 'package_paid' => true, 'invoices' => [$inv('2026-08', 10000, 0, 'unpaid')]],
            // 3: settled-courses keeps these unpaid closed courses listed so they stay actionable.
            'closed_settled_pending' => ['flag' => 0, 'charge' => 10000, 'closed_reason' => 'settled_pending', 'invoices' => []],
            'closed_contract_amended' => ['flag' => 0, 'charge' => 10000, 'closed_reason' => 'contract_amended', 'invoices' => []],
            // Tutoring is free before a stray flag or a paid invoice.
            'tutoring_flag1' => ['flag' => 1, 'charge' => 0, 'rate' => 0, 'class_type' => 'tutoring', 'invoices' => []],
            'tutoring_paid_invoice' => ['flag' => 0, 'charge' => 0, 'rate' => 0, 'class_type' => 'tutoring', 'invoices' => [$inv('2026-08', 5000, 5000, 'paid', [$cash(5000)])]],
            // Count-package member, no invoice: CoursePackage.paid is a legacy flag like StudentClass.Paid
            // (assumption for S1: it counts only while the member has no non-void invoice).
            'package_paid_member' => ['flag' => 0, 'charge' => 10000, 'package_paid' => true, 'invoices' => []],
            'package_unpaid_member' => ['flag' => 0, 'charge' => 10000, 'package_paid' => false, 'invoices' => []],
            // #3333 confirmed-session billing: Aug invoice stored 6000 but 5 attended sessions x 1500 = 7500.
            // Unpaid with no net payment, so the session amount is the effective total (target 7500).
            'monthly_confirmed_sessions_unpaid' => ['mode' => 'date', 'flag' => 0, 'charge' => 6000, 'rate' => 1500,
                'sessions' => ['2026-08-03', '2026-08-06', '2026-08-10', '2026-08-13', '2026-08-17'], 'invoices' => [
                    $inv('2026-07', 6000, 6000, 'paid', [$cash(6000)]),
                    $inv('2026-08', 6000, 0, 'unpaid') + ['effective_total' => 7500],
                ]],
            // Same sessions, but Aug was paid at 6000 before the sessions changed: stored total is kept (target 6000, paid).
            'monthly_paid_sessions_changed' => ['mode' => 'date', 'flag' => 1, 'charge' => 6000, 'rate' => 1500,
                'sessions' => ['2026-08-03', '2026-08-06', '2026-08-10', '2026-08-13', '2026-08-17'], 'invoices' => [
                    $inv('2026-07', 6000, 6000, 'paid', [$cash(6000)]),
                    $inv('2026-08', 6000, 6000, 'paid', [$cash(6000)]),
                ]],
            // R115: count -> date conversion after payment. Confirmed receipts and Paid=1 stay (no void rows, no reversal);
            // the partial Aug invoice is kept too.
            'amendment_after_payment_r115' => ['mode' => 'date', 'flag' => 1, 'charge' => 6000, 'rate' => 1500, 'invoices' => [
                $inv('2026-07', 6000, 6000, 'paid', [$cash(6000)]) + ['issue_mode' => 'count'],
                $inv('2026-08', 6000, 2000, 'partial', [$cash(2000)]) + ['issue_mode' => 'count'],
            ]],
        ];
    }

    /** Founder target semantics, derived from the fixture rows (not from any app code). */
    private function expected(array $fx): string
    {
        // Tutoring and zero-fee resolve to `free` before any flag/invoice rule (packet target contract).
        if (($fx['class_type'] ?? 'one_on_one') === 'tutoring'
            || ((int) $fx['charge'] <= 0 && (float) ($fx['rate'] ?? 0) <= 0)) {
            return 'free';
        }
        // Void invoices are not invoices for paid status; with none left the legacy flag decides.
        $invoices = array_values(array_filter($fx['invoices'], fn ($i) => $i['status'] !== 'void'));
        if ($invoices === []) {
            return (!empty($fx['flag']) || !empty($fx['package_paid'])) ? 'paid' : 'unpaid';
        }
        usort($invoices, fn ($a, $b) => strcmp($a['period'], $b['period'])); // oldest first
        foreach ($invoices as $invoice) {
            $applied = $this->applied($invoice);
            if ($applied < $invoice['total']) {
                return $applied > 0 ? 'partial' : 'unpaid';
            }
        }

        return 'paid';
    }

    /** Net applied on one invoice. Void = negative amount OR Method=void (packet legend; B13 kernel); capped at total. */
    private function applied(array $invoice): int
    {
        $isVoid = fn ($p) => $p['amount'] < 0 || $p['method'] === 'void';
        $positive = array_sum(array_map(fn ($p) => !$isVoid($p) && $p['amount'] > 0 ? $p['amount'] : 0, $invoice['payments']));
        $voided = array_sum(array_map(fn ($p) => $isVoid($p) ? abs($p['amount']) : 0, $invoice['payments']));

        return min($invoice['total'], max(0, $positive - $voided));
    }

    /**
     * Target course amounts {total, applied, outstanding}: free => all 0; no non-void invoice => total = Charge,
     * applied = Charge if flag/package paid else 0; else sums over non-void invoices (applied capped per invoice).
     */
    private function expectedAmounts(array $fx): array
    {
        if ($this->expected($fx) === 'free') {
            return ['total' => 0, 'applied' => 0, 'outstanding' => 0];
        }
        $invoices = array_values(array_filter($fx['invoices'], fn ($i) => $i['status'] !== 'void'));
        if ($invoices === []) {
            $applied = (!empty($fx['flag']) || !empty($fx['package_paid'])) ? (int) $fx['charge'] : 0;

            return ['total' => (int) $fx['charge'], 'applied' => $applied, 'outstanding' => (int) $fx['charge'] - $applied];
        }
        $total = array_sum(array_map(fn ($i) => $i['effective_total'] ?? $i['total'], $invoices));
        $applied = array_sum(array_map(fn ($i) => $this->applied($i), $invoices));

        return ['total' => $total, 'applied' => $applied, 'outstanding' => $total - $applied];
    }

    public function test_paid_status_sites_match_founder_target_or_are_allowlisted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Asia/Taipei'));

        $director = $this->directorToken();
        $ctx = [];
        foreach ($this->fixtures() as $id => $fx) {
            $ctx[$id] = $this->build($id, $fx);
        }

        $headers = ['Authorization' => "Bearer {$director}", 'Accept' => 'application/json'];
        $tuitionRes = $this->getJson('/api/v1/alerts/tuition?branch_id=1', $headers);
        $tuitionRes->assertOk();
        $tuitionRows = collect($tuitionRes->json())->filter(fn ($r) => !empty($r['id']))->keyBy(fn ($r) => (int) $r['id']);
        $listRes = $this->getJson('/api/v1/student-classes?branch_id=1&per_page=1000', $headers);
        $listRes->assertOk();
        $listRows = collect($listRes->json('data'))->keyBy(fn ($r) => (int) ($r['ID'] ?? $r['id']));
        $settledRes = $this->getJson('/api/v1/accounting/settled-courses?branch_id=1', $headers);
        $settledRes->assertOk();
        $settledIds = collect($settledRes->json('data'))->map(fn ($r) => (int) $r['student_class_id'])->flip();

        $mismatches = [];
        $observed = [];
        $seen = [];
        $inclusionProblems = [];
        foreach ($ctx as $fixtureId => $c) {
            $fx = $c['fx'];
            $classId = (int) $c['course']->ID;
            $expected = $this->expected($fx);
            $results = $this->observe($c, $tuitionRows->get($classId), $listRows->get($classId), $headers);
            // B17 settledCourses: listed = treated as paid (Paid=1 OR any Status=paid invoice), except closed
            // settled_pending / contract_amended rows, which are listed to stay actionable (inclusion, not status).
            if (isset($fx['closed_reason'])) {
                $results['api.accounting.settled_courses'] = null;
                if (!$settledIds->has($classId)) {
                    $inclusionProblems[] = "HARNESS: settled-courses dropped actionable closed_reason={$fx['closed_reason']} fixture {$fixtureId}";
                }
            } else {
                $results['api.accounting.settled_courses'] = $settledIds->has($classId) ? 'paid' : 'notpaid';
            }
            foreach ($results as $site => $actual) {
                if ($actual === null) {
                    continue; // site has no opinion on this fixture (excluded by design)
                }
                $observed[$site] = ($observed[$site] ?? 0) + 1;
                $seen[$site][] = $fixtureId;
                $target = $expected;
                if (in_array($site, self::BINARY_SITES, true)) {
                    if ($expected === 'free' && in_array($site, self::BINARY_SKIP_FREE, true)) {
                        continue;
                    }
                    $target = $expected === 'paid' ? 'paid' : 'notpaid';
                } elseif (isset(self::AMOUNT_SITES[$site])) {
                    $target = (string) $this->expectedAmounts($fx)[self::AMOUNT_SITES[$site]];
                } elseif (in_array($site, self::UNPAID_SITES, true)) {
                    $target = in_array($expected, ['partial', 'unpaid'], true) ? 'unpaid' : 'notunpaid';
                }
                if ($actual !== $target) {
                    $mismatches["{$site}|{$fixtureId}"] = ['site' => $site, 'fixture' => $fixtureId, 'current' => $actual, 'target' => $target];
                }
            }
        }

        $problems = $inclusionProblems;
        foreach (self::MIN_OBSERVED as $site => $min) {
            if (($observed[$site] ?? 0) < $min) {
                $problems[] = "HARNESS: site {$site} observed " . ($observed[$site] ?? 0) . " fixtures, expected >= {$min}";
            }
        }

        foreach ($this->exactObservable($this->fixtures()) as $site => $fixtureIds) {
            $missing = array_diff($fixtureIds, $seen[$site] ?? []);
            $extra = array_diff($seen[$site] ?? [], $fixtureIds);
            if ($missing !== [] || $extra !== []) {
                $problems[] = "HARNESS: site {$site} visibility changed; missing [" . implode(', ', $missing) . '] extra [' . implode(', ', $extra) . ']';
            }
        }

        // Allowlist: one entry per line; the per-site reason (packet row + removing step) is stored once.
        $file = json_decode(file_get_contents(__DIR__ . '/paid_status_parity_allowlist.json'), true, 512, JSON_THROW_ON_ERROR);
        $allowlist = [];
        foreach ($file['entries'] as $entry) {
            $key = "{$entry['site']}|{$entry['fixture']}";
            if (isset($allowlist[$key]) || trim((string) ($file['site_reasons'][$entry['site']] ?? '')) === '') {
                $problems[] = "HARNESS: allowlist entry {$key} is duplicated or its site has no reason";
            }
            $allowlist[$key] = $entry;
        }

        $rows = [];
        foreach ($mismatches as $key => $m) {
            if (!isset($allowlist[$key])) {
                $rows[] = ['NEW', $m['site'], $m['fixture'], $m['current'], $m['target'], 'not allowlisted: fix the regression or add a reasoned entry'];
            } elseif ($allowlist[$key]['current'] !== $m['current'] || $allowlist[$key]['target'] !== $m['target']) {
                // `uncertain` only excuses a missing mismatch (no STALE); an observed one must match exactly.
                $rows[] = ['DRIFT', $m['site'], $m['fixture'], $m['current'], $m['target'], "allowlist says {$allowlist[$key]['current']} -> {$allowlist[$key]['target']}"];
            }
        }
        foreach ($allowlist as $key => $entry) {
            if (!isset($ctx[$entry['fixture']])) {
                $rows[] = ['BAD', $entry['site'], $entry['fixture'], '-', '-', 'allowlist references unknown fixture'];
            } elseif (empty($entry['uncertain']) && !isset($mismatches[$key])) {
                $rows[] = ['STALE', $entry['site'], $entry['fixture'], $entry['current'], $entry['target'], 'no longer mismatches: delete this allowlist entry'];
            }
        }

        if ($rows !== [] || $problems !== []) {
            $lines = array_map(fn ($r) => sprintf('%-5s %-38s %-26s %-9s -> %-9s %s', ...$r), $rows);
            array_unshift($lines, sprintf('%-5s %-38s %-26s %-9s    %-9s %s', 'KIND', 'SITE', 'FIXTURE', 'CURRENT', 'TARGET', 'NOTE'));
            $this->fail("Paid-status parity drift (" . count($rows) . " rows):\n" . implode("\n", array_merge($problems, $lines)));
        }

        $this->assertTrue(true);
    }

    /** @return array<string, ?string> normalized per-site status: paid|partial|unpaid|free|notpaid|... or null = no opinion */
    private function observe(array $c, ?array $tuitionRow, ?array $listRow, array $headers): array
    {
        /** @var StudentClass $course */
        $course = $c['course']->fresh();
        $id = (int) $course->ID;
        $isMonthly = ($c['fx']['mode'] ?? 'count') === 'date';
        $out = [];

        // B1 / B27: Paid flag (+ package) only.
        $out['model.isEffectivelyPaid'] = $course->isEffectivelyPaid() ? 'paid' : 'notpaid';
        $out['model.scopeEffectivelyPaid'] = StudentClass::query()->effectivelyPaid()->where('ID', $id)->exists() ? 'paid' : 'notpaid';
        // B1 twin used by SendTuitionReminders / NotificationSync: an independently maintained SQL predicate.
        $out['model.scopeEffectivelyUnpaid'] = StudentClass::query()->effectivelyUnpaid()->where('ID', $id)->exists() ? 'unpaid' : 'notunpaid';

        // B2: flag OR (agg stored PaidAmount >= Charge > 0). Wraps the static isFullyPaid.
        $paidAmount = (int) (\App\Services\Billing\ContractMoneyState::invoiceAggregateByStudentClassIds([$id])[$id]['paid_amount'] ?? 0);
        $out['model.isFullyPaidWithInvoiceAmount'] = $course->isFullyPaidWithInvoiceAmount($paidAmount, (int) $course->Charge) ? 'paid' : 'notpaid';

        // B12 (F7 S1 contract): course-level resolver over all non-void invoices. `unbilled` (no invoice, no flag) reads as unpaid.
        $r = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$id], [$course])[$id];
        $out['amount.resolver.outstanding'] = (string) (int) $r['outstanding'];
        $out['amount.resolver.total'] = (string) (int) $r['payable_total'];
        $out['amount.resolver.applied'] = (string) (int) $r['applied'];
        $out['resolver.byStudentClassIds'] = $r['status'] === 'unbilled' ? 'unpaid' : $r['status'];

        // B12 live path (AlertController / PaymentReport / modals still call this): kept in the matrix until S3 migrates callers.
        $live = app(BillingPayableResolver::class)->byStudentClassIds([$id], [$course])[$id];
        $out['amount.resolver.live.outstanding'] = $live['payable_outstanding'] === null ? 'null' : (string) (int) $live['payable_outstanding'];
        if ($live['payable_status'] === 'unbilled') {
            $out['resolver.live.byStudentClassIds'] = 'unpaid';
        } else {
            $liveApplied = (int) $live['payable_amount'] - (int) $live['payable_outstanding'];
            $out['resolver.live.byStudentClassIds'] = (int) $live['payable_outstanding'] === 0 ? 'paid' : ($liveApplied > 0 ? 'partial' : 'unpaid');
        }

        // B15: monthly per-period engine (date mode only).
        $mpp = app(MonthlyPeriodPaymentService::class)->batch(collect([$course]));
        $out['monthly_period_payment.batch'] = $mpp[$id]['payment_status'] ?? null;

        // B3 / B4: alerts/tuition row (absent = excluded by inclusion rules, tutoring, charge <= 0).
        $out['api.alerts.tuition.payment_status'] = null;
        $out['api.alerts.tuition.outstanding'] = null;
        $out['amount.alerts.tuition.outstanding'] = null;
        if ($tuitionRow !== null) {
            $s = (string) $tuitionRow['payment_status'];
            $out['api.alerts.tuition.payment_status'] = in_array($s, ['paid', 'renew_needed', 'monthly_due_soon'], true) ? 'paid'
                : (in_array($s, ['partial', 'unpaid'], true) ? $s : "other:{$s}");
            $outstanding = (int) $tuitionRow['outstanding'];
            $out['amount.alerts.tuition.outstanding'] = (string) $outstanding;
            $charge = (int) $tuitionRow['charge'];
            $out['api.alerts.tuition.outstanding'] = $outstanding === 0 ? 'paid' : ($outstanding < $charge ? 'partial' : 'unpaid');
        }

        // B7: course lookup.
        $out['api.student_classes.payment_status'] = $listRow !== null ? (string) $listRow['payment_status'] : null;

        // B18: parent portal dashboard, per-course payment_status.
        $dash = $this->getJson('/api/v1/parent/dashboard', ['Authorization' => 'Bearer ' . $c['parent_token']]);
        $dash->assertOk();
        $card = collect($dash->json('classes'))->first(fn ($row) => (int) $row['id'] === $id);
        $out['api.parent.dashboard.payment_status'] = $card !== null ? (string) $card['payment_status'] : null;

        // B17: accounting ledger; course status = oldest invoice with an outstanding balance,
        // or the course row's `paid` (legacy StudentClass.Paid) when the course has no invoice.
        $ledger = $this->getJson("/api/v1/accounting/ledger?student_class_id={$id}", $headers);
        $ledger->assertOk();
        $out['amount.ledger.outstanding'] = (string) (int) $ledger->json('summary.outstanding_total');
        $out['amount.ledger.total'] = (string) (int) $ledger->json('summary.invoice_total');
        $out['amount.ledger.applied'] = (string) (int) $ledger->json('summary.applied_total');
        {
            $invoices = collect($ledger->json('invoices'))->sortBy('billing_period')->values();
            $got = $invoices->map(fn ($row) => (int) ($row['id'] ?? $row['invoice_id'] ?? 0))->sort()->values()->all();
            $want = collect($c['invoice_ids'])->sort()->values()->all();
            if ($got !== $want) {
                // An omitted (or extra) invoice must not normalize to `paid`.
                $out['api.accounting.ledger'] = 'other:ledger_invoices_' . implode(',', $got) . '_expected_' . implode(',', $want);

                return $out;
            }
            if ($want === []) {
                $courseRow = collect($ledger->json('courses'))->first(fn ($row) => (int) $row['id'] === $id);
                $out['api.accounting.ledger'] = $courseRow === null ? 'other:ledger_course_missing'
                    : (!empty($courseRow['paid']) ? 'paid' : 'unpaid');

                return $out;
            }
            $open = $invoices->first(fn ($row) => (int) $row['outstanding_amount'] > 0);
            $out['api.accounting.ledger'] = $open === null ? 'paid' : ((int) $open['calculated_applied_amount'] > 0 ? 'partial' : 'unpaid');
        }

        return $out;
    }

    /** @return array{fx: array, course: StudentClass, parent_token: string, invoice_ids: list<int>} */
    private function build(string $id, array $fx): array
    {
        $isDate = ($fx['mode'] ?? 'count') === 'date';
        $student = Student::create([
            'name' => mb_substr("pp-{$id}", 0, 32), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0911222333',
        ]);
        $package = !array_key_exists('package_paid', $fx) ? null : CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => "pp-pkg-{$id}",
            'billing_mode' => 'count', 'total_sessions' => 10, 'remaining_sessions' => 10,
            'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => $fx['package_paid'],
            'paid_at' => $fx['package_paid'] ? '2026-08-01' : null, 'stop' => false, 'enabled' => true,
        ]);
        $course = StudentClass::create([
            'PackageID' => $package?->id,
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $isDate ? '2026-07-01' : '2026-08-01',
            'EndDate' => $isDate ? '2026-08-31' : null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => $fx['charge'],
            'Pay' => null,
            'PayDate' => null,
            'Paid' => $fx['flag'],
            'Disconunt' => null,
            'Rate' => $fx['rate'] ?? null,
            'LearnTimeID' => null,
            'MDate' => now(),
            'Stop' => isset($fx['closed_reason']) ? 1 : 0, // real close/amend flows stop the course
            'closed_reason' => $fx['closed_reason'] ?? null,
            'ScheduleMode' => $isDate ? 'date' : 'count',
            'SessionCount' => $isDate ? 0 : ($fx['session_count'] ?? 10),
            'SessionDuration' => 120,
            'RemainingSessions' => $isDate ? 0 : 2,
            'UsedSessions' => 0,
            'ClassType' => $fx['class_type'] ?? 'one_on_one',
            'settlement_day' => $isDate ? 15 : null,
            'monthly_sessions' => null,
        ]);

        foreach ($fx['sessions'] ?? [] as $date) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }

        $invoiceIds = [];
        foreach ($fx['invoices'] as $row) {
            $invoice = Invoice::create([
                'StudentID' => $student->id,
                'StudentClassID' => $course->ID,
                'IssueDate' => $row['period'] . '-01',
                'DueDate' => $row['period'] . '-15',
                'TotalAmount' => $row['total'],
                'PaidAmount' => $row['stored_paid'],
                'Status' => $row['status'],
                'billing_period' => $row['period'],
                'ScheduleModeAtIssue' => $row['issue_mode'] ?? null,
            ]);
            $invoiceIds[] = (int) $invoice->id;
            foreach ($row['payments'] as $payment) {
                Payment::create([
                    'InvoiceID' => $invoice->id,
                    'Amount' => $payment['amount'],
                    'PaidAt' => $row['period'] . '-05',
                    'Method' => $payment['method'],
                ]);
            }
        }

        $raw = Str::random(32);
        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addHours(2),
        ]);

        return ['fx' => $fx, 'course' => $course, 'parent_token' => $raw, 'invoice_ids' => $invoiceIds];
    }

    private function directorToken(): string
    {
        $user = User::create([
            'LoginName' => 'paid-parity-' . uniqid() . '@example.com',
            'Name' => '對帳主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
