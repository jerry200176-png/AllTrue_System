<?php

namespace Tests\Support;

use App\Models\{AuthToken, Campus, Invoice, InvoiceItem, Payment, Student, StudentClass, User, UserCampus};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Shared isolated #535 fixtures; never contains production IDs or credentials. */
trait SubjectCreditFixtures
{
    protected function fixture(float $rate = 1500, int $charge = 12000, int $used = 2): array
    {
        $campus = Campus::factory()->create();
        $student = Student::create(['name' => 'isolated-credit', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $teacher = User::create(['LoginName' => uniqid('credit-teacher-'), 'Name' => 'isolated teacher', 'PSW' => 'test-only', 'type' => 'T', 'phone' => '0900000000']);
        $attributes = ['StudentID' => $student->id, 'GradeID' => 1, 'TeacherID' => $teacher->id,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-01', 'TotalHours' => 16,
            'Charge' => $charge, 'Pay' => $charge, 'Paid' => 1, 'PayDate' => '2026-09-01', 'Disconunt' => 0,
            'Rate' => $rate, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8 - $used, 'UsedSessions' => $used,
            'deduction_basis' => 'fixed_session', 'ClassType' => 'one_on_one'];
        $source = StudentClass::create($attributes + ['SubjectID' => 970001]);
        $target = StudentClass::create(array_replace($attributes, ['SubjectID' => 970002, 'Paid' => 0, 'Pay' => 0, 'UsedSessions' => 0, 'RemainingSessions' => 8]));
        for ($i = 0; $i < $used; $i++) {
            $this->appendClassSession($source, Carbon::parse('2026-09-01')->addDays($i)->toDateString(), 'attended');
        }
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $source->ID, 'IssueDate' => '2026-09-01', 'TotalAmount' => $charge, 'PaidAmount' => $charge, 'Status' => 'paid', 'ScheduleModeAtIssue' => 'count']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $source->ID, 'Description' => 'source', 'Amount' => $charge]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $charge, 'PaidAt' => '2026-09-01', 'Method' => 'cash']);
        $debt = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $target->ID, 'IssueDate' => '2026-09-01', 'TotalAmount' => $charge, 'PaidAmount' => 0, 'Status' => 'unpaid', 'ScheduleModeAtIssue' => 'count']);
        InvoiceItem::create(['InvoiceID' => $debt->id, 'StudentClassID' => $target->ID, 'Description' => 'existing debt', 'Amount' => $charge]);

        return [$this->token((int) $campus->id), $source, $target, $invoice];
    }

    protected function token(?int $campus, bool $director = true): string
    {
        $user = User::create(['LoginName' => uniqid('credit-user-'), 'Name' => 'isolated user', 'PSW' => 'test-only', 'type' => $director ? 'A' : 'T', 'phone' => '0900000000']);
        if ($campus !== null) {
            UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => $director ? 1 : 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    protected function appendClassSession(StudentClass $course, string $date, string $status): void
    {
        DB::table('ClassSession')->insert(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '08:00', 'EndTime' => '09:00', 'Status' => $status]);
    }

    protected function businessSnapshot(): array
    {
        $snapshot = [];
        foreach (['StudentClass', 'ClassSession', 'Invoice', 'InvoiceItem', 'Payment', 'payment_reports', 'LearningRecord', 'StudentSingIn', 'session_deduction_ledger'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
