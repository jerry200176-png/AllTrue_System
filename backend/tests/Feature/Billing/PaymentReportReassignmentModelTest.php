<?php

namespace Tests\Feature\Billing;

use App\Models\Campus;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\PaymentReportReassignment;
use App\Models\Student;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #5113 design PR 2: the link row of a receipt move persists, casts and resolves its relations, and the
 * database itself refuses a second move from the same receipt or onto the same new receipt.
 */
class PaymentReportReassignmentModelTest extends TestCase
{
    use BuildsMoneyFixtures, RefreshDatabase;

    private Campus $campus;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::factory()->create();
        $this->actor = UserFactory::new()->director()->create();
    }

    public function test_row_persists_with_integer_and_datetime_casts_and_no_updated_at(): void
    {
        [$from, $to] = [$this->receipt('2032-03'), $this->receipt('2032-04')];
        $key = (string) Str::uuid();

        $created = PaymentReportReassignment::create($this->attrs($from, $to, ['idempotency_key' => $key, 'amount' => '4000']));
        $row = PaymentReportReassignment::query()->findOrFail($created->id);

        foreach (['id', 'from_report_id', 'to_report_id', 'from_invoice_id', 'to_invoice_id', 'from_student_id',
            'to_student_id', 'campus_id', 'amount', 'actor_user_id'] as $column) {
            $this->assertIsInt($row->getAttribute($column), $column);
        }
        $this->assertSame(4000, $row->amount);
        $this->assertSame($key, $row->idempotency_key);
        $this->assertSame('選錯學生', $row->reason);
        $this->assertInstanceOf(Carbon::class, $row->created_at);
        $this->assertArrayNotHasKey('updated_at', $row->getAttributes());
        $this->assertNull(PaymentReportReassignment::UPDATED_AT);
    }

    public function test_source_bill_may_be_missing_but_every_other_link_is_required(): void
    {
        [$from, $to] = [$this->receipt('2032-03'), $this->receipt('2032-04')];

        $row = PaymentReportReassignment::create($this->attrs($from, $to, ['from_invoice_id' => null]));
        $this->assertNull($row->fresh()->from_invoice_id);
        $this->assertNull($row->fresh()->fromInvoice);

        [$from2, $to2] = [$this->receipt('2032-05'), $this->receipt('2032-06')];
        foreach (['from_report_id', 'to_report_id', 'to_invoice_id', 'from_student_id', 'to_student_id', 'campus_id',
            'amount', 'reason', 'idempotency_key', 'actor_user_id'] as $column) {
            try {
                PaymentReportReassignment::create($this->attrs($from2, $to2, [$column => null]));
                $this->fail("$column accepted NULL");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, PaymentReportReassignment::query()->count());
    }

    public function test_a_receipt_cannot_be_moved_twice(): void
    {
        [$from, $to, $other] = [$this->receipt('2032-03'), $this->receipt('2032-04'), $this->receipt('2032-05')];
        PaymentReportReassignment::create($this->attrs($from, $to));

        try {
            PaymentReportReassignment::create($this->attrs($from, $other));
            $this->fail('a second move from the same receipt was stored');
        } catch (QueryException $e) {
            $this->assertStringContainsString('prr_from_report_uq', $e->getMessage());
        }
        $this->assertSame(1, PaymentReportReassignment::query()->count());
    }

    public function test_a_new_receipt_cannot_come_from_two_moves(): void
    {
        [$from, $to, $other] = [$this->receipt('2032-03'), $this->receipt('2032-04'), $this->receipt('2032-05')];
        PaymentReportReassignment::create($this->attrs($from, $to));

        try {
            PaymentReportReassignment::create($this->attrs($other, $to));
            $this->fail('a second move onto the same new receipt was stored');
        } catch (QueryException $e) {
            $this->assertStringContainsString('prr_to_report_uq', $e->getMessage());
        }
        $this->assertSame(1, PaymentReportReassignment::query()->count());
    }

    public function test_a_wrong_move_can_be_corrected_by_moving_the_new_receipt_again(): void
    {
        [$a, $b, $c] = [$this->receipt('2032-03'), $this->receipt('2032-04'), $this->receipt('2032-05')];
        PaymentReportReassignment::create($this->attrs($a, $b));
        $second = PaymentReportReassignment::create($this->attrs($b, $c));

        $this->assertSame(2, PaymentReportReassignment::query()->count());
        $this->assertSame($b->id, $second->fresh()->from_report_id);
    }

    public function test_relations_resolve_both_receipts_bills_students_campus_and_actor(): void
    {
        [$from, $to] = [$this->receipt('2032-03'), $this->receipt('2032-04')];
        $row = PaymentReportReassignment::create($this->attrs($from, $to))->fresh();

        $this->assertTrue($row->fromReport->is($from));
        $this->assertTrue($row->toReport->is($to));
        $this->assertInstanceOf(Invoice::class, $row->fromInvoice);
        $this->assertSame((int) $from->InvoiceID, $row->fromInvoice->id);
        $this->assertSame((int) $to->InvoiceID, $row->toInvoice->id);
        $this->assertInstanceOf(Student::class, $row->fromStudent);
        $this->assertSame((int) $from->StudentID, $row->fromStudent->id);
        $this->assertSame((int) $to->StudentID, $row->toStudent->id);
        $this->assertNotSame($row->fromStudent->id, $row->toStudent->id);
        $this->assertTrue($row->campus->is($this->campus));
        $this->assertTrue($row->actor->is($this->actor));
    }

    /** A confirmed receipt on its own student, course and bill, all in the test campus. */
    private function receipt(string $period): PaymentReport
    {
        $student = Student::factory()->create(['CampusID' => $this->campus->id]);
        $course = $this->course([[$period, 4000, 'paid', 4000, []]], [], [], $student);

        return PaymentReport::create([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'InvoiceID' => Invoice::query()->where('StudentClassID', $course->ID)->value('id'),
            'reported_by_name' => 'x', 'payment_date' => "$period-05", 'payment_method' => 'cash', 'reported_amount' => 4000,
            'status' => 'confirmed', 'report_token_hash' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->addDay(),
        ]);
    }

    /** @return array<string, mixed> */
    private function attrs(PaymentReport $from, PaymentReport $to, array $override = []): array
    {
        return $override + [
            'from_report_id' => $from->id, 'to_report_id' => $to->id,
            'from_invoice_id' => $from->InvoiceID, 'to_invoice_id' => $to->InvoiceID,
            'from_student_id' => $from->StudentID, 'to_student_id' => $to->StudentID,
            'campus_id' => $this->campus->id, 'amount' => 4000, 'reason' => '選錯學生',
            'idempotency_key' => (string) Str::uuid(), 'actor_user_id' => $this->actor->id,
        ];
    }
}
