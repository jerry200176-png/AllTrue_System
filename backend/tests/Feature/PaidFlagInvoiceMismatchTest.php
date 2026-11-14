<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Services\Billing\PaidFlagInvoiceMismatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #5187: the row dump behind the nightly invariant `paid_flag_vs_invoice`. It lists exactly the contracts the
 * invariant counts, IDs / enums / booleans only (public repo: no names, no amounts).
 */
class PaidFlagInvoiceMismatchTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_NAME = 'Zq-Secret-Student-Name';

    /** @return array{0:int,1:int} [student_class_id, campus_id] */
    private function seedCourse(array $over = []): array
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => self::SECRET_NAME, 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $id = DB::table('StudentClass')->insertGetId($over + [
            'StudentID' => (int) $student->id, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now(), 'TotalHours' => 8,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'Paid' => 0,
        ]);

        return [$id, (int) $campus->id];
    }

    private function seedBill(?int $headerCourse, int $total = 1000, int $paid = 0): int
    {
        $id = DB::table('Invoice')->insertGetId([
            'StudentID' => 1, 'StudentClassID' => $headerCourse, 'IssueDate' => '2026-10-01', 'TotalAmount' => $total,
            'PaidAmount' => $paid, 'Status' => $paid >= $total ? 'paid' : 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($paid > 0) {
            DB::table('Payment')->insert([
                'InvoiceID' => $id, 'Amount' => $paid, 'Method' => 'cash', 'PaidAt' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function seedLine(int $invoiceId, int $course): void
    {
        DB::table('InvoiceItem')->insert([
            'InvoiceID' => $invoiceId, 'StudentClassID' => $course, 'Amount' => 500, 'Description' => self::SECRET_NAME,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function nightlyCount(): int
    {
        Artisan::call('bugs:verify-reproductions', ['--json' => true]);
        $rows = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR)['conditions'];

        return (int) collect($rows)->firstWhere('key', 'paid_flag_vs_invoice')['count'];
    }

    public function test_dump_lists_exactly_the_counted_rows_of_each_side(): void
    {
        [$clean1] = $this->seedCourse(['Paid' => 1]);
        $this->seedBill($clean1, 1000, 1000);
        [$clean2] = $this->seedCourse(['Paid' => 0]);
        $this->seedBill($clean2, 1000, 0);

        [$sideA, $campusA] = $this->seedCourse(['Paid' => 1]);
        $openBill = $this->seedBill($sideA, 1000, 0);
        [$sideB, $campusB] = $this->seedCourse(['Paid' => 0]);
        $paidBill = $this->seedBill($sideB, 1000, 1000);

        $rows = (new PaidFlagInvoiceMismatch())->rows();

        $this->assertSame($this->nightlyCount(), count($rows));
        $this->assertEqualsCanonicalizing([$sideA, $sideB], array_column($rows, 'student_class_id'));
        $byId = array_column($rows, null, 'student_class_id');

        $a = $byId[$sideA];
        $this->assertSame(PaidFlagInvoiceMismatch::SIDE_A, $a['side']);
        $this->assertSame($campusA, $a['campus_id']);
        $this->assertSame(1, $a['paid_flag']);
        $this->assertFalse($a['fully_paid']);
        $this->assertSame([$openBill], $a['open_invoice_ids']);
        $this->assertSame([$openBill], $a['linked_invoice_ids']);
        $this->assertFalse($a['shared_bill']);

        $b = $byId[$sideB];
        $this->assertSame(PaidFlagInvoiceMismatch::SIDE_B, $b['side']);
        $this->assertSame($campusB, $b['campus_id']);
        $this->assertSame(0, $b['paid_flag']);
        $this->assertTrue($b['fully_paid']);
        $this->assertSame([], $b['open_invoice_ids']);
        $this->assertSame([$paidBill], $b['linked_invoice_ids']);
    }

    public function test_line_linked_shared_bill_is_flagged_and_header_course_is_not_listed_when_clean(): void
    {
        [$header] = $this->seedCourse(['Paid' => 1]);
        [$lineOnly, $campus] = $this->seedCourse(['Paid' => 0]);
        $shared = $this->seedBill($header, 1000, 1000); // fully paid; header course flagged paid, line course not (H1 side B)
        $this->seedLine($shared, $lineOnly);

        $rows = (new PaidFlagInvoiceMismatch())->rows();

        $this->assertSame([$lineOnly], array_column($rows, 'student_class_id'));
        $this->assertSame($campus, $rows[0]['campus_id']);
        $this->assertSame(PaidFlagInvoiceMismatch::SIDE_B, $rows[0]['side']);
        $this->assertTrue($rows[0]['shared_bill']);
        $this->assertSame('line', $rows[0]['invoice_links'][0]['link']);
        $this->assertSame($this->nightlyCount(), count($rows));
    }

    public function test_recount_reopen_fact_and_stopped_or_package_rows_are_excluded(): void
    {
        [$dateMode] = $this->seedCourse(['Paid' => 1, 'ScheduleMode' => 'date']);
        $this->seedBill($dateMode, 1000, 0);
        [$stopped] = $this->seedCourse(['Paid' => 1, 'Stop' => 1]);
        $this->seedBill($stopped, 1000, 0);

        $rows = (new PaidFlagInvoiceMismatch())->rows();

        $this->assertSame([$dateMode], array_column($rows, 'student_class_id'));
        $this->assertTrue($rows[0]['recount_can_reopen']);
        $this->assertFalse($rows[0]['closed_reason_set']);
        $this->assertSame($this->nightlyCount(), count($rows));
    }

    public function test_dump_has_only_whitelisted_keys_and_no_pii_or_amounts(): void
    {
        [$a] = $this->seedCourse(['Paid' => 1, 'Charge' => 123457]);
        $this->seedBill($a, 987654, 0);
        [$b] = $this->seedCourse(['Paid' => 0]);
        $this->seedBill($b, 987654, 987654);

        $rows = (new PaidFlagInvoiceMismatch())->rows();
        $this->assertCount(2, $rows);

        $allowed = [
            'student_class_id', 'campus_id', 'side', 'paid_flag', 'fully_paid', 'linked_invoice_ids', 'open_invoice_ids',
            'invoice_links', 'resolver_status', 'under_review', 'shared_bill', 'stop', 'closed_reason_set', 'recount_can_reopen',
        ];
        foreach ($rows as $row) {
            $this->assertEqualsCanonicalizing($allowed, array_keys($row));
        }
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ([self::SECRET_NAME, '987654', '123457'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }
}
