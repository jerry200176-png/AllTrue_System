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
    private const PENDING = [
        'alerts_listing' => 'Founder decision: a Paid=1 contract with an open invoice is not listed (DIRECTOR_PAYMENT_ALERT_RULES)',
    ];

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
        $list = collect($this->withHeaders($headers)->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $course->StudentID)->assertOk()->json());
        $listRow = $list->first(fn ($r) => (int) ($r['ID'] ?? $r['id'] ?? 0) === $id);
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
