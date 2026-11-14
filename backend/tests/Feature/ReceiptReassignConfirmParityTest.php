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

    /** @return array<string, array{0: string}> one case per gate refusal and per settle outcome of CollectionTarget */
    public static function cases(): array
    {
        return [
            'gate: tutoring course' => ['tutoring'],
            'gate: zero-fee course' => ['free'],
            'gate: waived contract' => ['waived'],
            'settle: whole bill paid settles the course' => ['plain'],
            'settle: part of the bill leaves the course open' => ['partial'],
            'settle: paid count-package bill marks the package' => ['package'],
            'settle: monthly package member settles only itself' => ['date_member'],
        ];
    }

    /** @dataProvider cases */
    public function test_confirm_and_the_move_give_the_same_outcome_for_the_same_target(string $case): void
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

        if ($confirmed->status() !== 200) {
            $this->assertSame(422, $confirmed->status(), $confirmed->getContent());
            $this->assertSame(422, $moved->status(), "confirm refused ({$confirmed->getContent()}) but the move answered {$moved->getContent()}");
            $this->assertSame('reassign_target_not_collectable', $moved->json('code'));
            $this->assertSame($confirmed->json('code'), $moved->json('reason'));
            $this->assertSame($confirmed->json('message'), $moved->json('message'));
            $this->assertSame($before, $this->snapshot(), 'a refused move writes nothing');

            return;
        }
        $this->assertSame(201, $moved->status(), "confirm took the money but the move answered {$moved->getContent()}");
        $this->assertSame($this->outcome($viaConfirm), $this->outcome($viaMove));
    }

    /** @return array{course:StudentClass, invoice:Invoice, package:?CoursePackage} a fresh target of the given shape, own student */
    private function shape(string $case): array
    {
        $student = $this->student(1);
        $package = in_array($case, ['package', 'date_member'], true) ? $this->package($student) : null;
        $course = ['PackageID' => $package?->id] + match ($case) {
            'tutoring' => ['ClassType' => 'tutoring'],
            'free' => ['Charge' => 0],
            'date_member' => ['ScheduleMode' => 'date', 'StartDate' => '2026-11-01', 'EndDate' => '2026-11-30'],
            default => [],
        };
        $total = match ($case) { 'partial' => self::AMOUNT + 1000, 'free' => 0, default => self::AMOUNT };
        $bill = $this->bill($student, $total, 'unpaid', 0, $course, $case === 'date_member' ? ['billing_period' => '2026-11'] : []);
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
