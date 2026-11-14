<?php

namespace Tests\Feature;

use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ReceiptReassignFixtures;
use Tests\TestCase;

/**
 * #5113: the receipt move must treat a target exactly as confirm() treats the same bill, because both call one rule set
 * (App\Services\Billing\CollectionTarget: gate() and settle()). Each case shapes the same target twice, once for each path:
 * confirm() of a pending report for that bill, and a move of an equal receipt onto it. Then:
 *  - if confirm() refuses, the move refuses too, as `reassign_target_not_collectable` carrying confirm()'s code and message,
 *    and writes nothing;
 *  - if confirm() takes the money, the move does too, and the bill, the course Paid flag and the package mark end the same.
 * A rule added to only one path makes its case fail here. Move-only gates that are stricter than confirm() (director limits,
 * package ownership, an unpriced package) are tested in ReceiptReassignApplyTest, not here.
 */
class ReceiptReassignConfirmParityTest extends TestCase
{
    use RefreshDatabase;
    use ReceiptReassignFixtures;

    private const AMOUNT = 8000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_RECEIPT_REASSIGN' => true]]);
        config(['billing.receipt_reassign_director_max_amount' => 0, 'billing.receipt_reassign_director_max_age_days' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * One case per CollectionTarget rule, each with its pinned expectation, so two paths wrong the same way cannot pass:
     * [shape, confirm()'s refusal code (false = it takes the money; null = refused without a code), the move's code
     * (reassign_target_not_collectable carries confirm()'s code as `reason`), the outcome of an accepted case (pinned fields)].
     *
     * @return array<string, array{0: string, 1: string|null|false, 2: string|null, 3: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $paid = ['bill_status' => 'paid', 'bill_paid' => self::AMOUNT];

        return [
            'gate: tutoring course' => ['tutoring', 'tutoring_no_payment_obligation', 'reassign_target_not_collectable', []],
            'gate: zero-fee course' => ['free', 'zero_fee_no_payment_obligation', 'reassign_target_not_collectable', []],
            'gate: waived contract' => ['waived', null, 'reassign_target_not_collectable', []],
            'gate: month already on another course\'s shared bill' => ['shared_month', 'period_on_shared_bill', 'reassign_target_not_collectable', []],
            'hold: stored-paid bill of a paid package takes no money' => ['package_hold', 'course_already_paid', 'reassign_target_package_paid', []],
            'hold: an unpaid package holds nothing; its paid bill marks it' => ['package', false, null, $paid + ['course_paid' => 1, 'package_paid' => true]],
            'settle: whole bill paid settles the course' => ['plain', false, null, $paid + ['course_paid' => 1, 'package_paid' => null]],
            'settle: part of the bill leaves the course open' => ['partial', false, null, ['bill_status' => 'partial', 'bill_paid' => self::AMOUNT, 'course_paid' => 0]],
            'settle: monthly package member settles only itself' => ['date_member', false, null, $paid + ['package_paid' => false]],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string, mixed> $accepted
     */
    public function test_confirm_and_the_move_give_the_same_outcome_for_the_same_target(string $case, string|null|false $confirmCode, ?string $moveCode, array $accepted): void
    {
        $tok = $this->token('S', 1);

        $viaConfirm = $this->shape($case);
        $pending = PaymentReport::create([
            'StudentID' => $viaConfirm['course']->StudentID, 'StudentClassID' => $viaConfirm['course']->ID, 'InvoiceID' => $viaConfirm['invoice']->id,
            'reported_by_name' => 'x', 'payment_date' => '2026-11-10', 'payment_method' => 'transfer', 'reported_amount' => self::AMOUNT,
            'status' => 'pending', 'report_token_hash' => hash('sha256', uniqid('c', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
        $confirmed = $this->putJson("/api/v1/payment-reports/{$pending->id}/confirm", [], $this->h($tok));

        $viaMove = $this->shape($case);
        $source = $this->bill($this->student(1), self::AMOUNT, 'paid', self::AMOUNT);
        $receipt = PaymentReport::create([
            'StudentID' => $source['invoice']->StudentID, 'StudentClassID' => $source['course']->ID, 'InvoiceID' => $source['invoice']->id,
            'reported_by_name' => 'x', 'payment_date' => '2026-11-10', 'payment_method' => 'transfer', 'reported_amount' => self::AMOUNT,
            'status' => 'confirmed', 'confirmed_by' => 1, 'confirmed_at' => Carbon::now(), 'payment_id' => $source['payment']->id,
            'report_token_hash' => hash('sha256', uniqid('m', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
        $before = $this->snapshot();
        $plan = $this->previewTo($tok, $receipt, $viaMove['invoice']);
        $moved = $plan->status() === 200
            ? $this->postJson("/api/v1/payment-reports/{$receipt->id}/reassign", ['target_invoice_id' => $viaMove['invoice']->id, 'state_hash' => $plan->json('state_hash'), 'reason' => '選錯學生'], $this->h($tok))
            : $plan;

        if ($confirmCode !== false) {
            // Pinned: the exact refusal each path gives, then that the two agree.
            $this->assertSame(422, $confirmed->status(), $confirmed->getContent());
            $this->assertSame($confirmCode, $confirmed->json('code'), $confirmed->getContent());
            $this->assertSame(422, $moved->status(), "confirm refused ({$confirmed->getContent()}) but the move answered {$moved->getContent()}");
            $this->assertSame($moveCode, $moved->json('code'), $moved->getContent());
            if ($moveCode === 'reassign_target_not_collectable') { // the shared gate: confirm()'s own code and words
                $this->assertSame($confirmed->json('code'), $moved->json('reason'));
                $sameWords = fn (?string $m) => preg_replace('/\d+/', '#', (string) $m); // a message may name each side's own bill id
                $this->assertSame($sameWords($confirmed->json('message')), $sameWords($moved->json('message')));
            }
            $this->assertSame($before, $this->snapshot(), 'a refused move writes nothing');

            return;
        }
        $this->assertSame(200, $confirmed->status(), $confirmed->getContent());
        $this->assertSame(201, $moved->status(), "confirm took the money but the move answered {$moved->getContent()}");
        $viaConfirmOutcome = $this->outcome($viaConfirm);
        $this->assertSame($accepted, array_intersect_key($viaConfirmOutcome, $accepted), 'pinned outcome');
        $this->assertSame($viaConfirmOutcome, $this->outcome($viaMove));
    }

    /**
     * The source side follows void()'s rules (Payment's reversal predicates, the bill-status rules, CollectionTarget::unsettle):
     * [shape, void()'s refusal code (false = it reverses), the move's code, the source bill afterwards (pinned)].
     *
     * @return array<string, array{0: string, 1: string|false, 2: string|null, 3: array<string, mixed>}>
     */
    public static function sourceCases(): array
    {
        return [
            'legacy unlinked reversal on the bill: both refuse' => ['legacy', 'legacy_reversal_unreconciled', 'reassign_legacy_reversal_unreconciled', []],
            'status: the receipt was all the bill held, so it is unpaid' => ['alone', false, null, ['bill_status' => 'unpaid', 'bill_paid' => 0, 'course_paid' => 0]],
            'status: another receipt stays on the bill, so it is partial' => ['shared', false, null, ['bill_status' => 'partial', 'bill_paid' => self::AMOUNT, 'course_paid' => 0]],
        ];
    }

    /**
     * @dataProvider sourceCases
     * @param array<string, mixed> $after
     */
    public function test_void_and_the_move_leave_the_source_bill_the_same(string $case, string|false $voidCode, ?string $moveCode, array $after): void
    {
        $tok = $this->token('S', 1);
        [$viaVoid, $voided] = $this->source($case);
        $res = $this->putJson("/api/v1/payment-reports/{$voided->id}/void", ['void_reason' => '收錯帳單'], $this->h($tok));

        [$viaMove, $moved] = $this->source($case);
        $target = $this->bill($this->student(1), self::AMOUNT);
        $before = $this->snapshot();
        $plan = $this->previewTo($tok, $moved, $target['invoice']);
        $move = $plan->status() === 200
            ? $this->postJson("/api/v1/payment-reports/{$moved->id}/reassign", ['target_invoice_id' => $target['invoice']->id, 'state_hash' => $plan->json('state_hash'), 'reason' => '選錯學生'], $this->h($tok))
            : $plan;

        if ($voidCode !== false) {
            $this->assertSame($voidCode, $res->json('code'), $res->getContent());
            $this->assertSame($moveCode, $move->json('code'), $move->getContent());
            $this->assertSame($res->status(), $move->status());
            $this->assertSame($before, $this->snapshot(), 'a refused move writes nothing');

            return;
        }
        $this->assertSame(200, $res->status(), $res->getContent());
        $this->assertSame(201, $move->status(), $move->getContent());
        $voidOutcome = $this->outcome(['invoice' => $viaVoid['invoice'], 'course' => $viaVoid['course'], 'package' => null]);
        $this->assertSame($after, array_intersect_key($voidOutcome, $after), 'pinned outcome');
        $this->assertSame($voidOutcome, $this->outcome(['invoice' => $viaMove['invoice'], 'course' => $viaMove['course'], 'package' => null]));
    }

    /** @return array{0: array{course:StudentClass, invoice:Invoice, payment:?\App\Models\Payment}, 1: PaymentReport} a confirmed receipt on its own paid bill */
    private function source(string $case): array
    {
        $student = $this->student(1);
        $total = $case === 'shared' ? 2 * self::AMOUNT : self::AMOUNT;
        $bill = $this->bill($student, $total, 'paid', self::AMOUNT);
        if ($case === 'shared') { // a second, unrelated receipt's money on the same bill
            $this->payment($bill['invoice'], self::AMOUNT);
            $bill['invoice']->update(['PaidAmount' => $total, 'Status' => 'paid']);
        }
        if ($case === 'legacy') {
            \App\Models\Payment::create(['InvoiceID' => $bill['invoice']->id, 'Amount' => -50, 'PaidAt' => '2026-11-01', 'Method' => 'void']);
        }
        $receipt = PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $bill['course']->ID, 'InvoiceID' => $bill['invoice']->id, 'reported_by_name' => 'x',
            'payment_date' => '2026-11-10', 'payment_method' => 'transfer', 'reported_amount' => self::AMOUNT, 'status' => 'confirmed',
            'confirmed_by' => 1, 'confirmed_at' => Carbon::now(), 'payment_id' => $bill['payment']->id,
            'report_token_hash' => hash('sha256', uniqid('s', true)), 'token_expires_at' => Carbon::now()->addDay(),
        ]);

        return [$bill, $receipt];
    }

    /** @return array{course:StudentClass, invoice:Invoice, package:?CoursePackage} a fresh target of the given shape, own student */
    private function shape(string $case): array
    {
        $student = $this->student(1);
        $package = in_array($case, ['package', 'package_hold', 'date_member'], true) ? $this->package($student) : null;
        if ($case === 'package_hold') {
            $package->forceFill(['paid' => true, 'paid_at' => '2026-11-01'])->save();
        }
        $course = ['PackageID' => $package?->id] + match ($case) {
            'tutoring' => ['ClassType' => 'tutoring'],
            'free' => ['Charge' => 0],
            'date_member', 'shared_month' => ['ScheduleMode' => 'date', 'StartDate' => '2026-11-01', 'EndDate' => '2026-11-30'],
            default => [],
        };
        $total = match ($case) { 'partial' => self::AMOUNT + 1000, 'free' => 0, default => self::AMOUNT };
        $invoiceOverrides = match ($case) {
            'date_member', 'shared_month' => ['billing_period' => '2026-11'],
            'package_hold' => ['Status' => 'paid'], // stored paid, no money on it: the hold, not the Payment rows, decides
            default => [],
        };
        $bill = $this->bill($student, $total, 'unpaid', 0, $course, $invoiceOverrides);
        if ($case === 'shared_month') { // another course's live November bill already carries this course's lessons
            $carrier = $this->bill($student, self::AMOUNT, 'unpaid', 0, [], ['billing_period' => '2026-11']);
            $carrier['invoice']->items()->create(['StudentClassID' => $bill['course']->ID, 'Description' => 'shared', 'Amount' => self::AMOUNT]);
        }
        if ($case === 'waived') {
            $bill['course']->update(['closed_reason' => 'waived']); // a waived contract takes no new bill either, so it is waived after billing
        }

        return ['course' => $bill['course'], 'invoice' => $bill['invoice'], 'package' => $package];
    }

    /** @param array{course:StudentClass, invoice:Invoice, package:?CoursePackage} $shape @return array<string, mixed> */
    private function outcome(array $shape): array
    {
        $invoice = $shape['invoice']->fresh();
        $course = $shape['course']->fresh();

        return ['bill_status' => $invoice->Status, 'bill_paid' => (int) $invoice->PaidAmount, 'course_paid' => (int) $course->Paid,
            'closed_reason' => $course->closed_reason, 'package_paid' => $shape['package'] ? (bool) $shape['package']->fresh()->paid : null];
    }

    private function package(Student $owner): CoursePackage
    {
        return CoursePackage::create(['student_id' => $owner->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8,
            'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
    }
}
