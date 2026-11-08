<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\Billing\StudentBillWorkbookData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AssertsQueryBudget;
use Tests\TestCase;

class StudentBillWorkbookDataTest extends TestCase
{
    use RefreshDatabase, AssertsQueryBudget;

    private function student(int $campus = 1): Student
    {
        Campus::firstOrCreate(['id' => $campus], ['name' => '合成分校', 'code' => 'fixture' . $campus, 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '']);
        return Student::create(['name' => '合成學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(Student $student, array $extra = []): StudentClass
    {
        DB::table('Subject')->insertOrIgnore(['id' => 1, 'School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => '合成英文']);
        return StudentClass::create($extra + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-06-01', 'TotalHours' => 16, 'Charge' => 5000, 'Rate' => 123.45, 'rate_unit' => 'hour',
            'Paid' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 6, 'ClassType' => 'one_on_one', 'UsedSessions' => 2,
        ]);
    }

    private function request(): Request
    {
        $actor = User::create(['LoginName' => uniqid('fixture') . '@example.test', 'Name' => '合成主任', 'PSW' => 'synthetic', 'type' => 'A', 'phone' => 912345678, 'MustChangePassword' => false]);
        $request = Request::create('/api/v1/accounting/bills/export', 'POST', ['branch_id' => 1]);
        $request->attributes->add(['auth_user' => $actor, 'auth_role' => 'director', 'auth_has_campus' => true, 'auth_campus_ids' => [1]]);
        return $request;
    }

    private function lessonFixture(StudentClass $course, string $date, string $status, string $end = '11:30:00'): int
    {
        return DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '10:00:00', 'EndTime' => $end, 'Status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fingerprint(): string
    {
        $tables = ['StudentClass', 'ClassSession', 'LearningRecord', 'StudentSingIn', 'Invoice', 'InvoiceItem', 'Payment', 'payment_reports', 'session_deduction_ledger', 'security_audit_events'];
        return hash('sha256', serialize(array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), $tables)));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_scoped_evidence_preserves_ordinals_money_and_source_rows_without_writes(): void
    {
        CarbonImmutable::setTestNow('2026-11-08 12:00:00 Asia/Taipei');
        $student = $this->student();
        $course = $this->course($student);
        $unselected = $this->course($student);
        $teacher = User::create(['LoginName' => 'teacher@example.test', 'Name' => '合成紀錄老師', 'PSW' => 'synthetic', 'type' => 'T', 'phone' => 912345679]);
        $this->lessonFixture($course, '2026-06-01', 'scheduled'); // Canonical numbering counts this; the attendance export must not.
        $this->lessonFixture($course, '2026-06-05', 'leave');
        $taught = $this->lessonFixture($course, '2026-06-12', 'attended');
        $this->lessonFixture($course, '2026-06-15', 'completed', '11:00:00');
        $conflict = $this->lessonFixture($course, '2026-06-20', 'attended');
        $this->lessonFixture($course, '2026-11-10', 'attended');
        $this->lessonFixture($unselected, '2026-06-18', 'attended');
        DB::table('LearningRecord')->insert(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'ClassSessionID' => $taught, 'TeacherID' => $teacher->id, 'Status' => 'approved', 'Content' => '合成教學', 'SessionDate' => '2026-06-12', 'StartTime' => '10:00:00', 'EndTime' => '11:30:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('StudentSingIn')->insert(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'ClassSessionID' => $conflict, 'Status' => 'leave', 'SignInDT' => '2026-06-20 10:00:00', 'SignOutDT' => '2026-06-20 11:30:00', 'MDT' => now()]);
        $invoice = DB::table('Invoice')->insertGetId(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'IssueDate' => '2026-06-01', 'DueDate' => '2026-06-10']);
        $payment = DB::table('Payment')->insertGetId(['InvoiceID' => $invoice, 'Amount' => 5000, 'PaidAt' => '2026-06-02', 'Method' => 'cash', 'Note' => 'PRIVATE_NOT_EXPORTED']);
        $report = DB::table('payment_reports')->insertGetId(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'InvoiceID' => $invoice, 'reported_by_name' => '合成回報者', 'payment_date' => '2026-06-02', 'payment_method' => 'cash', 'reported_amount' => 5000, 'status' => 'voided', 'payment_id' => $payment, 'account_last5' => '12345', 'note' => 'PRIVATE_NOT_EXPORTED', 'report_token_hash' => hash('sha256', 'synthetic-original'), 'token_expires_at' => now()->addDay()]);
        DB::table('Payment')->insert(['InvoiceID' => $invoice, 'Amount' => -5000, 'PaidAt' => '2026-06-03', 'Method' => 'void', 'payment_report_id' => $report]);
        DB::table('payment_reports')->insert(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'reported_by_name' => '合成回報者', 'payment_date' => '2026-06-04', 'reported_amount' => 1000, 'payment_method' => 'transfer', 'status' => 'pending', 'account_last5' => '12345', 'report_token_hash' => hash('sha256', 'synthetic-pending'), 'token_expires_at' => now()->addDay()]);
        $request = $this->request();
        $before = $this->fingerprint();
        $output = null;
        $this->assertQueryCountAtMost(80, function () use (&$output, $course, $student, $request) {
            $output = app(StudentBillWorkbookData::class)->build([['id' => $course->ID, 'student_id' => $student->id, 'subject' => '合成英文']], ['course:' . $course->ID => ['合成學生', '合成英文', '剩 6 堂', '未繳', '—', 5000, 0, 5000]], $request);
        });
        $details = $output[0]['sources'][0]['contracts'];
        $this->assertCount(1, $details);
        $this->assertSame(['2026-06-12', '2026-06-15'], array_column($details[0]['lessons'], 'date'));
        $this->assertSame([2, null], array_column($details[0]['lessons'], 'ordinal'));
        $this->assertEquals([90, 60], array_column($details[0]['lessons'], 'minutes'));
        $this->assertSame(['合成紀錄老師', null], array_column($details[0]['lessons'], 'teacher'));
        $this->assertSame([null, null], array_column($details[0]['lessons'], 'historical_unit_fee'));
        $this->assertCount(1, $details[0]['warnings']);
        $this->assertSame(5000, $details[0]['invoices'][0]['total_amount']);
        $this->assertSame(0, $details[0]['invoices'][0]['calculated_applied_amount']);
        $this->assertSame([5000, -5000], array_column($details[0]['invoices'][0]['payments'], 'amount'));
        $this->assertSame('pending', $details[0]['unmatched_reports'][0]['status']);
        $this->assertNull($details[0]['unmatched_reports'][0]['receipt_no']);
        $this->assertStringNotContainsString('PRIVATE_NOT_EXPORTED', json_encode($output));
        $this->assertStringNotContainsString('account_last5', json_encode($output));
        $this->assertSame($before, $this->fingerprint());
        CarbonImmutable::setTestNow();
    }

    public function test_package_members_and_internal_reads_remain_inside_existing_staff_boundaries(): void
    {
        $student = $this->student();
        $other = $this->student();
        $foreign = $this->student(2);
        $package = CoursePackage::create(['student_id' => $student->id, 'campus_id' => 1, 'name' => '合成方案', 'billing_mode' => 'count', 'total_sessions' => 8]);
        $a = $this->course($student, ['PackageID' => $package->id]);
        $b = $this->course($student, ['PackageID' => $package->id]);
        $wrongStudent = $this->course($other, ['PackageID' => $package->id]);
        $wrongCampus = $this->course($foreign, ['PackageID' => $package->id]);
        $source = ['package_id' => $package->id, 'student_id' => $student->id, 'subject' => '合成方案', 'member_class_ids' => [$a->ID, $b->ID, $wrongStudent->ID, $wrongCampus->ID]];
        $request = $this->request();
        $data = app(StudentBillWorkbookData::class)->build([$source, $source], ['package:' . $package->id => ['合成學生', '合成方案', '剩 8 堂', '未繳', '—', 8000, 0, 8000]], $request);
        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['sources']);
        $this->assertCount(2, $data[0]['sources'][0]['contracts']);
        $this->assertSame(8000, $data[0]['sources'][0]['summary'][5]);
        foreach ([['auth_role', 'teacher', 403], ['auth_has_campus', false, 403], ['auth_campus_ids', [2], 403]] as [$key, $value, $status]) {
            $denied = clone $request;
            $denied->attributes = clone $request->attributes;
            $denied->attributes->set($key, $value);
            try {
                app(StudentBillWorkbookData::class)->build([$source], [], $denied);
                $this->fail('A route-level staff guard was bypassed.');
            } catch (HttpException $exception) {
                $this->assertSame($status, $exception->getStatusCode());
            }
        }
        $request->attributes->get('auth_user')->MustChangePassword = true;
        try {
            app(StudentBillWorkbookData::class)->build([$source], [], $request);
            $this->fail('Password-change guard was bypassed.');
        } catch (HttpException $exception) {
            $this->assertSame(428, $exception->getStatusCode());
        }
    }
}
