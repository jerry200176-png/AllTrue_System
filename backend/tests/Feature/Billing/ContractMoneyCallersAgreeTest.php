<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\ContractMoneyVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADMIN-OPS billing: the cross-caller property. Same seeded random contracts as ContractMoneyVerdictInvariantsTest;
 * every screen that shows "owes / settled" for a contract must agree with ContractMoneyVerdict.
 *
 * Verdict class: settled = paid|free; owing = unpaid|partial|unbilled; review_required makes no claim.
 * A caller that has not moved onto the Verdict yet is listed in PENDING with the follow-up that moves it: a
 * disagreement there is reported markTestIncomplete with the seed (like #3781), anywhere else it fails the build.
 * Re-run one case: PHPUnit --filter 'seed_<n>'. Change the corpus: MONEY_INVARIANT_SEED=<n>.
 */
class ContractMoneyCallersAgreeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    private const CASES = 120;

    /** caller => what moves it (remove the entry in that PR). */
    private const PENDING = []; // every caller is on the Verdict (Founder Q2/Q3, 2026-10-11)

    public static function seeds(): iterable
    {
        $base = (int) (getenv('MONEY_INVARIANT_SEED') ?: 20261008);
        for ($i = 0; $i < self::CASES; $i++) {
            yield 'seed_' . ($base + $i) => [$base + $i];
        }
    }

    private static function verdictClass(string $status): ?string
    {
        return match ($status) {
            'paid', 'free' => 'settled',
            'unpaid', 'partial', 'unbilled' => 'owing',
            default => null,
        };
    }

    /** @dataProvider seeds */
    public function test_every_caller_agrees_with_the_verdict(int $seed): void
    {
        $c = $this->randomContract($seed);
        $course = $c['course'];
        if ($c['date']) {
            $course->update(['settlement_day' => 15]); // monthly contracts only reach the alert list with a settlement day
        } elseif (!$c['trial']) {
            $course->update(['Rate' => 1000]); // Rate x SessionCount == Charge; amended-price drift is a separate decision
        }
        $course = $course->fresh();
        $id = (int) $course->ID;
        $v = app(ContractMoneyVerdict::class)->forCourse($course);
        $class = self::verdictClass($v->status);
        $answers = []; // caller => string describing a disagreement

        $headers = $this->directorHeaders();
        // 1. Alerts / tuition-collection queue row (when the listing rule includes the contract).
        $row = collect($this->withHeaders($headers)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $id);
        if ($row && $class) {
            $rowClass = match ($row['payment_status']) {
                'paid', 'renew_needed', 'monthly_due_soon', 'waived' => 'settled',
                'unpaid', 'partial', 'pending_reconciliation' => 'owing',
                default => null,
            };
            if ($rowClass !== $class) {
                $answers['alerts'] = "status {$row['payment_status']} but verdict {$v->status}";
            } elseif ($v->source === 'invoice' && (int) $row['paid_amount'] !== $v->applied) {
                $answers['alerts'] = "paid_amount {$row['paid_amount']} but verdict applied {$v->applied}";
            } elseif ($v->source === 'invoice' && (int) $row['outstanding'] !== $v->outstanding) {
                $answers['alerts'] = "outstanding {$row['outstanding']} but verdict {$v->outstanding}";
            }
        }
        if (!$row && $class === 'owing' && !$c['date'] && !$c['package'] && $v->source === 'invoice' && $v->outstanding > 0) {
            $answers['alerts_listing'] = "owes {$v->outstanding} but is not in the alert list (Paid={$c['paidFlag']})";
        }
        // 2. Course list.
        $list = collect($this->withHeaders($headers)->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $course->StudentID)->assertOk()->json('data'));
        $listRow = $list->first(fn ($r) => (int) ($r['ID'] ?? $r['id'] ?? 0) === $id);
        $this->assertNotNull($listRow, 'seed ' . $seed . ': course list must exercise the seeded contract');
        $this->assertSame(['status' => $v->status, 'source' => $v->source, 'outstanding' => $v->outstanding],
            $listRow['billing_verdict'] ?? null, 'seed ' . $seed . ': canonical list verdict');
        if ($listRow && $class) {
            $listClass = match ($listRow['payment_status'] ?? '') {
                'paid', 'free' => 'settled',
                'unpaid', 'partial', 'pending_report' => 'owing',
                default => null,
            };
            if ($listClass !== null && $listClass !== $class && !($listRow['closed_reason'] ?? null)) {
                $answers['course_list'] = "status {$listRow['payment_status']} but verdict {$v->status}";
            }
        }

        // 3. Student ledger (the billing panel): its owed total is the Verdict's outstanding.
        if ($class && $v->source === 'invoice') {
            $ledger = $this->withHeaders($headers)->getJson('/api/v1/accounting/ledger?student_class_id=' . $id)->assertOk()->json('summary');
            if ((int) $ledger['outstanding_total'] !== $v->outstanding) {
                $answers['ledger'] = "outstanding_total {$ledger['outstanding_total']} but verdict {$v->outstanding}";
            }
        }
        // 4. Duplicate-payment guard: blocks exactly the settled contracts.
        if ($class) {
            $ctl = app(\App\Http\Controllers\PaymentReportController::class);
            $blocked = (new \ReflectionMethod($ctl, 'courseMoneyBlockReason'))->invoke($ctl, $id, (int) $course->Paid) !== null;
            if ($blocked !== ($class === 'settled')) {
                $answers['intake_guard'] = ($blocked ? 'blocks' : 'allows') . " a payment but verdict {$v->status}";
            }
        }
        // 5. Tuition slip: offered for owing contracts, refused for settled ones.
        if ($class) {
            $slip = $this->withHeaders($headers)->getJson("/api/v1/alerts/tuition-slip/$id")->status();
            if (($slip === 422) !== ($class === 'settled')) {
                $answers['slip'] = "answers $slip but verdict {$v->status}";
            }
        }

        // 6. R28 invariant: collecting is only ever against what still owes. Never above the outstanding amount, never
        //    against a bill whose Payment rows already cover it (stored Status may be stale), whatever Paid says.
        if ($class === 'owing' && $v->source === 'invoice' && !$c['package'] && $v->outstanding > 0) {
            $over = $this->withHeaders($headers)->postJson('/api/v1/payment-reports/director-record', [
                'student_class_id' => $id, 'payment_date' => '2026-08-20', 'payment_method' => 'cash', 'amount' => $v->outstanding + 1,
            ]);
            if ($over->status() !== 422) {
                $answers['collect_invariant'] = "accepted {$over->status()} amount outstanding+1 ({$v->outstanding})";
            }
            // A bill whose Payment rows cover it but whose stored Status went stale ("unpaid") must be refused too.
            $this->invoice($course->fresh(), \App\Models\Student::findOrFail($course->StudentID), '2026-04', 1000, 'unpaid', 0, [[1000, 'cash']]);
            foreach (\App\Models\Invoice::with(['payments', 'items'])->where('StudentClassID', $id)->where('Status', '!=', 'void')->get() as $inv) {
                $p = app(\App\Services\InvoiceAmountReconciliationService::class)->resolve($inv, $course);
                if ((int) $p['total_amount'] > 0 && (int) $p['outstanding_amount'] <= 0) {
                    $res = $this->withHeaders($headers)->postJson('/api/v1/payment-reports/director-record', [
                        'student_class_id' => $id, 'invoice_id' => $inv->id, 'payment_date' => '2026-08-20', 'payment_method' => 'cash', 'amount' => 100,
                    ]);
                    if ($res->status() !== 422) {
                        $answers['collect_invariant'] = "accepted a payment against invoice {$inv->id} already covered by Payment rows";
                    }
                }
            }
            $recorded = \App\Models\PaymentReport::where('StudentClassID', $id)->count();
            if ($recorded !== 0) {
                $answers['collect_invariant'] = "$recorded payment report(s) were created by refused attempts";
            }
            // #3864 hotfix: each bill is capped by its OWN Payment-row outstanding (never the course total), and after a
            // record + confirm its Payment rows never exceed its total while PaidAmount/Status equal that projection.
            $recon = app(\App\Services\InvoiceAmountReconciliationService::class);
            $owing = \App\Models\Invoice::with(['payments', 'items'])->where('StudentClassID', $id)->whereNotIn('Status', ['paid', 'void'])->orderBy('id')->get()
                ->first(fn ($inv) => (int) $recon->resolve($inv, $course)['outstanding_amount'] > 0);
            if ($owing) {
                $left = (int) $recon->resolve($owing, $course)['outstanding_amount'];
                $send = fn (int $amount) => $this->withHeaders($headers)->postJson('/api/v1/payment-reports/director-record', [
                    'student_class_id' => $id, 'invoice_id' => $owing->id, 'payment_date' => '2026-08-20', 'payment_method' => 'cash', 'amount' => $amount,
                ]);
                if (($s = $send($left + 1)->status()) !== 422) {
                    $answers['collect_invariant'] = "invoice {$owing->id} owes $left but accepted $s for " . ($left + 1);
                }
                \App\Models\PaymentReport::where('StudentClassID', $id)->delete();
                $reportId = $send($left)->json('report_id');
                if ($reportId) {
                    $this->withHeaders($headers)->putJson("/api/v1/payment-reports/$reportId/confirm");
                }
                $after = \App\Models\Invoice::with(['payments', 'items'])->findOrFail($owing->id);
                $p = $recon->resolve($after, $course->fresh());
                $wantStatus = $p['outstanding_amount'] <= 0 ? 'paid' : ($p['net_applied'] > 0 ? 'partial' : 'unpaid');
                if ($p['net_applied'] > $p['total_amount']) {
                    $answers['collect_invariant'] = "invoice {$after->id} Payment rows {$p['net_applied']} exceed total {$p['total_amount']}";
                } elseif ($reportId && ((int) $after->PaidAmount !== $p['net_applied'] || (string) $after->Status !== $wantStatus)) {
                    $answers['collect_invariant'] = "invoice {$after->id} stored {$after->PaidAmount}/{$after->Status} but Payment rows say {$p['net_applied']}/$wantStatus";
                }
            }
        }

        if ($answers === []) {
            $this->addToAssertionCount(1);

            return;
        }
        $msg = "seed=$seed (" . ($c['date'] ? 'date' : 'count') . ", invoices={$c['nInvoices']}, Paid={$c['paidFlag']}, trial=" . (int) $c['trial']
            . ', package=' . (int) $c['package'] . "): verdict {$v->status}/{$v->outstanding}; " . json_encode($answers, JSON_UNESCAPED_UNICODE);
        if ($c['package']) {
            $this->markTestIncomplete("Known: package members are listed by package, not by member. $msg");
        }
        $unmoved = array_intersect_key($answers, self::PENDING);
        if ($unmoved !== [] && count($unmoved) === count($answers)) {
            $this->markTestIncomplete('Caller not yet on the Verdict (' . implode(', ', array_map(fn ($k) => "$k: " . self::PENDING[$k], array_keys($unmoved))) . "). $msg");
        }
        $this->fail($msg);
    }

    /**
     * #3781: a bill whose stored Status/PaidAmount went stale as "paid" while its Payment rows still owe. Payment rows are
     * the truth (Founder Q2, R28), so every receivable listing must still show it, not pre-filter on the stored columns.
     */
    public function test_stale_paid_invoice_still_owes_on_every_receivable_list(): void
    {
        $course = $this->course([['2026-08', 10000, 'paid', 10000, [[4000, 'cash']]]], ['Paid' => 1, 'Rate' => 1000]);
        $id = (int) $course->ID;
        $v = app(ContractMoneyVerdict::class)->forCourse($course);
        $this->assertSame(['partial', 6000], [$v->status, $v->outstanding]);
        $headers = $this->directorHeaders();
        $answers = [];

        $row = collect($this->withHeaders($headers)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('id', $id);
        if (!$row) {
            $answers['alerts_listing'] = 'not listed';
        } elseif ($row['payment_status'] !== 'partial' || (int) $row['outstanding'] !== $v->outstanding) {
            $answers['alerts'] = "status {$row['payment_status']} outstanding {$row['outstanding']}";
        }
        $aging = $this->withHeaders($headers)->getJson('/api/v1/finance/ar-aging?branch_id=1')->assertOk()->json('totals.grand_total');
        if ((int) $aging !== $v->outstanding) {
            $answers['ar_aging'] = "grand_total $aging";
        }
        $txn = \App\Models\BankTransaction::create(['campus_id' => 1, 'transaction_date' => '2026-08-20', 'amount' => $v->outstanding, 'reference' => 'stale', 'status' => 'unmatched']);
        $suggested = collect($this->withHeaders($headers)->getJson("/api/v1/bank-reconciliation/{$txn->id}/suggest")->assertOk()->json('suggestions'))->pluck('student_class_id')->all();
        if ($suggested !== [$id]) {
            $answers['bank_suggest'] = 'suggests ' . json_encode($suggested);
        }

        $this->assertSame([], $answers, "verdict {$v->status}/{$v->outstanding}");
    }

    /**
     * #3891 review (P2): bank suggestions compare the course's summed debt, not one invoice at a time. A stale-paid
     * bill owing 6,000 plus an unpaid bill owing 2,000 is one 8,000 course balance (the resolver's outstanding). The
     * suggested date is the oldest bill that still owes, not an older stored-unpaid bill its Payment rows cover.
     */
    public function test_bank_suggest_matches_the_course_balance_across_invoices(): void
    {
        $course = $this->course([['2026-06', 3000, 'unpaid', 0, [[3000, 'cash']]], ['2026-07', 10000, 'paid', 10000, [[4000, 'cash']]],
            ['2026-08', 2000, 'unpaid', 0, []]], ['Paid' => 1, 'Rate' => 1000]);
        $v = app(ContractMoneyVerdict::class)->forCourse($course);
        $this->assertSame(['partial', 8000], [$v->status, $v->outstanding]);
        $txn = \App\Models\BankTransaction::create(['campus_id' => 1, 'transaction_date' => '2026-08-20', 'amount' => 8000, 'reference' => 'sum', 'status' => 'unmatched']);
        $suggested = collect($this->withHeaders($this->directorHeaders())->getJson("/api/v1/bank-reconciliation/{$txn->id}/suggest")->assertOk()->json('suggestions'));
        $this->assertSame([(int) $course->ID], $suggested->pluck('student_class_id')->all());
        $this->assertSame('2026-07-01', $suggested->first()['payment_date']);
    }

    /**
     * #3891 review: a paid shared package is listed by package (DIRECTOR_PAYMENT_ALERT_RULES), never member by member.
     * Whether a member's stale-paid bill reopens the package is a package money rule not decided yet (#3785 hold), so
     * the stale-paid widening must not emit a lone member row for it.
     */
    public function test_stale_paid_member_of_a_paid_package_is_not_listed_as_a_lone_course(): void
    {
        $course = $this->course([['2026-08', 10000, 'paid', 10000, [[4000, 'cash']]]], ['Paid' => 1, 'Rate' => 1000]);
        $pkg = \App\Models\CoursePackage::create([
            'student_id' => $course->StudentID, 'campus_id' => 1, 'name' => 'Shared', 'billing_mode' => 'session',
            'total_sessions' => 16, 'remaining_sessions' => 16, 'used_sessions' => 0, 'rate' => 1500, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'paid_at' => '2026-08-05', 'stop' => false, 'enabled' => true,
        ]);
        $course->update(['PackageID' => $pkg->id]);

        $h = $this->directorHeaders();
        $rows = collect($this->withHeaders($h)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json());
        $this->assertNull($rows->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $course->ID && empty($r['package_id'])));
        // Same hold for the class-level finance lists (#3891 review): no member-level AR or bank match either.
        $this->assertSame(0, (int) $this->withHeaders($h)->getJson('/api/v1/finance/ar-aging?branch_id=1')->assertOk()->json('totals.grand_total'));
        $txn = \App\Models\BankTransaction::create(['campus_id' => 1, 'transaction_date' => '2026-08-20', 'amount' => 6000, 'reference' => 'pkg', 'status' => 'unmatched']);
        $this->assertSame([], $this->withHeaders($h)->getJson("/api/v1/bank-reconciliation/{$txn->id}/suggest")->assertOk()->json('suggestions'));
    }

    /** #3923 review: an UNPAID package holds nothing, so its member's stale-paid bill (rows 4,000/10,000) still owes 6,000. */
    public function test_stale_paid_member_of_an_unpaid_package_stays_owed(): void
    {
        $course = $this->course([['2026-08', 10000, 'paid', 10000, [[4000, 'cash']]]], ['Paid' => 1, 'Rate' => 1000]);
        $course->update(['PackageID' => \App\Models\CoursePackage::create([
            'student_id' => $course->StudentID, 'campus_id' => 1, 'name' => 'Unpaid', 'billing_mode' => 'session',
            'total_sessions' => 16, 'remaining_sessions' => 16, 'used_sessions' => 0, 'rate' => 1500, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
        ])->id]);
        $this->assertSame(1, \App\Models\Invoice::query()->where('StudentClassID', $course->ID)->whereRaw(\App\Models\Invoice::mayOweSql())->count());
        $this->assertSame(6000, (int) $this->withHeaders($this->directorHeaders())->getJson('/api/v1/finance/ar-aging?branch_id=1')->assertOk()->json('totals.grand_total'));
    }

    /** @return array<string, string> */
    private function directorHeaders(): array
    {
        $user = User::where('LoginName', 'callers-agree@example.com')->first();
        if (!$user) {
            $user = User::create(['LoginName' => 'callers-agree@example.com', 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }
}
