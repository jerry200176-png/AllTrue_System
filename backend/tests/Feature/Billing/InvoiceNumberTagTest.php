<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StudentClass;
use App\Services\Billing\BillingPeriod;
use App\Services\Billing\InvoiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** in-app #514: the number tag is bookkeeping (never shown), the coverage ownership rule, and the label rule through production code. */
class InvoiceNumberTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_slip_data_never_shows_the_number_tag(): void
    {
        $token = $this->createDirectorToken([1], 'director-slip-514@example.com');
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-04-29', 'EndDate' => '2026-05-27']);
        $inv = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-04-29', 'billing_period' => '2026-05', 'TotalAmount' => 7500,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '請匯款 [invno_period:2026-04]']);
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->getJson("/api/v1/invoices/{$inv->id}/slip-data")->assertOk();
        $this->assertSame('請匯款', $res->json('note'));
        $this->assertStringNotContainsString('invno_period', json_encode($res->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame('', InvoiceNumber::visibleNote('[invno_period:2026-04]'));
    }

    public function test_a_lone_item_of_another_subject_does_not_cover_a_lesson(): void
    {
        $student = $this->createStudent();
        $math = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'SubjectID' => 1, 'StartDate' => '2026-04-29', 'EndDate' => '2026-05-27']);
        $english = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'SubjectID' => 2, 'StartDate' => '2026-05-28', 'EndDate' => '2026-06-30']);
        $inv = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $math->ID, 'IssueDate' => '2026-05-28', 'billing_period' => '2026-05', 'TotalAmount' => 1500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        InvoiceItem::create(['InvoiceID' => $inv->id, 'StudentClassID' => $english->ID, 'Description' => 'e', 'Amount' => 1500, 'PeriodStart' => '2026-05-28', 'PeriodEnd' => '2026-06-30']);
        $this->assertSame([], BillingPeriod::datesCoveredByBills($math, ['2026-05-28']), "another subject's only item never covers");
        DB::table('InvoiceItem')->where('InvoiceID', $inv->id)->update(['StudentClassID' => null]);
        $this->assertSame(['2026-05-28'], BillingPeriod::datesCoveredByBills($math, ['2026-05-28']), 'a lone unowned item still does');
    }

    public static function cycles(): array
    {
        // [cycle start, end, ISO weekday of the lessons, expected label]; week 2 = Tuesday, 5 = Friday
        return [
            'starts on the 1st' => ['2026-05-01', '2026-05-31', 2, '2026-05'],
            'starts on the 15th, first lesson same month' => ['2026-05-15', '2026-06-14', 2, '2026-05'],
            'starts on the 28th, first lesson same month' => ['2026-04-28', '2026-05-27', 4, '2026-04'],
            'starts on the 28th, first lesson next month' => ['2026-05-28', '2026-06-26', 2, '2026-06'],
            'starts on the 29th' => ['2026-04-29', '2026-05-27', 2, '2026-05'],
            'starts on the 30th, first lesson next month' => ['2026-04-30', '2026-05-28', 2, '2026-05'],
            'starts on the 31st' => ['2026-08-31', '2026-09-29', 3, '2026-09'],
            'year boundary' => ['2026-12-30', '2027-01-28', 2, '2027-01'],
            'start day is a lesson day' => ['2026-04-29', '2026-05-27', 3, '2026-04'],
        ];
    }

    #[DataProvider('cycles')]
    public function test_for_cycle_labels_by_the_first_lesson_month_through_the_schedule(string $start, string $end, int $weekday, string $expected): void
    {
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => $start, 'EndDate' => $end, 'week' => $weekday, 'time' => '18:00:00', 'SessionDuration' => 120]);
        $this->assertSame($expected, BillingPeriod::forCycle($course, $start, $end));
    }

    public function test_a_cycle_with_no_schedule_falls_back_to_the_start_month(): void
    {
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['ScheduleMode' => 'date', 'StartDate' => '2026-04-29', 'EndDate' => '2026-05-27', 'week' => null, 'time' => null]);
        $this->assertSame('2026-04', BillingPeriod::forCycle($course, '2026-04-29', '2026-05-27'));
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = \App\Models\User::create(['LoginName' => $loginName, 'Name' => '主任測試', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        foreach ($campusIds as $campusId) {
            \App\Models\UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        \App\Models\AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function createStudent(): \App\Models\Student
    {
        return \App\Models\Student::create(['name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge(['StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->toDateString(), 'EndDate' => null, 'TotalHours' => 20, 'Charge' => 0, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0], $overrides));
    }
}
